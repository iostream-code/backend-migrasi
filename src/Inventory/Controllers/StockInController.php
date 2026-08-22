<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Database;
use App\Inventory\Support\ApiEnvelope;
use App\Inventory\Support\StockPosting;
use App\Support\DocumentNumber;
use App\Support\PhotoStorage;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Port dari backend-production App\Http\Controllers\API\Inventory\StockInController
 * (+ ReceiveService, Eloquent) ke Slim/PDO polos -- sisi GUDANG saja (receive
 * PO). Field request/response dikutip apa adanya dari sana (po_item_id,
 * qty_remaining, dst) supaya kompatibel kalau inventory-apk suatu saat
 * di-pointing kesini.
 *
 * TIDAK diport di pass ini (lihat inventory-apk/ROADMAP.md): getStockInDone,
 * getStockInHistory, Manual Stock In, retur/replacement barang rusak, export
 * Excel/PDF -- belum dipakai FE sekarang.
 *
 * [DISEDERHANAKAN dari versi asli, keputusan sadar 2026-08-22] ReceiveService
 * asli auto-alokasi tiap item receive ke SJ (Surat Jalan) digital yang OPEN
 * (FIFO lintas SJ) atau auto-create "shadow SJ" ke pur_t_surat_jalan(_detail)
 * kalau tidak ketemu -- integrasi penuh dengan modul Shipping/SJ purchase.
 * Di sini TIDAK direplikasi: submitStockInReceive cuma insert receive
 * header+detail, update qty_received PO, lalu StockPosting::postIn() --
 * sj_detail_id SELALU NULL. Alasan: modul SJ digital (pur_t_surat_jalan)
 * belum pernah dipakai live di produksi sama sekali (0 baris saat pass ini
 * dibuat, dicek langsung ke DB), dan inventory-apk sendiri TIDAK PERNAH
 * kirim surat_jalan_id/sj_detail_id di payload-nya -- selalu lewat jalur
 * "walk-in" yang di versi asli berujung shadow-SJ. Kalau nanti modul
 * Shipping/SJ mulai dipakai beneran dan histori pur_t_surat_jalan perlu
 * ter-link dari receive gudang, porting bagian itu ke sini (perlu
 * SuratJalan+SuratJalanDetail model/service tambahan, di luar Inventory).
 */
class StockInController
{
    use ApiEnvelope;

    private const OUTSTANDING_STATUSES = ['APPROVED', 'READY', 'SENT', 'PARTIAL_RECEIVED'];

    public function getStockInActive(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }
        $statusFilter = $body['status_filter'] ?? 'all';
        $search = self::nullableString($body['search'] ?? null);
        $month = is_numeric($body['filter_month'] ?? null) ? (int) $body['filter_month'] : null;
        $year = is_numeric($body['filter_year'] ?? null) ? (int) $body['filter_year'] : null;

        $pdo = Database::connection();

        // IN(...) dinamis (status outstanding) lebih gampang pakai placeholder
        // POSITIONAL murni di query ini (beda pola dari controller lain di
        // modul ini yang murni named) -- PDO_MySQL dgn ATTR_EMULATE_PREPARES=
        // false tidak boleh campur named+positional dalam satu query.
        $sql = 'SELECT po.*, s.supplier_nama AS supplier_name, s.supplier_type
                FROM pur_t_purchase_order po
                LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
                WHERE po.warehouse_id = ? AND po.status IN (' . implode(',', array_fill(0, count(self::OUTSTANDING_STATUSES), '?')) . ')
                  AND po.deleted_at IS NULL';
        $params = array_merge([$warehouseId], self::OUTSTANDING_STATUSES);

        if ($statusFilter === 'open') {
            $sql .= ' AND po.status IN (?, ?)';
            $params[] = 'APPROVED';
            $params[] = 'SENT';
        } elseif ($statusFilter === 'partial') {
            $sql .= ' AND po.status = ?';
            $params[] = 'PARTIAL_RECEIVED';
        }
        if ($search !== null) {
            $sql .= ' AND (po.po_number LIKE ? OR s.supplier_nama LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
        }
        if ($month && $year) {
            $sql .= ' AND MONTH(po.po_date) = ? AND YEAR(po.po_date) = ?';
            $params[] = $month;
            $params[] = $year;
        } elseif ($year) {
            $sql .= ' AND YEAR(po.po_date) = ?';
            $params[] = $year;
        }
        $sql .= " ORDER BY FIELD(po.status, 'PARTIAL_RECEIVED', 'SENT', 'APPROVED'), po.po_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $poRows = $stmt->fetchAll();

        $summary = $this->fetchItemSummary($pdo, array_column($poRows, 'id'));

        $poList = array_map(function ($po) use ($summary) {
            return $this->formatPoRow($po, $summary[(int) $po['id']] ?? null);
        }, $poRows);

        return $this->apiSuccess($response, ['po_list' => $poList]);
    }

    public function getStockInPoItems(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $poId = (int) ($body['po_id'] ?? 0);
        if ($poId <= 0) {
            return $this->apiError($response, 'po_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $po = $this->findPoWithSupplier($pdo, $poId);
        if (!$po) {
            return $this->apiNotFound($response, 'PO tidak ditemukan');
        }

        $items = array_map([$this, 'formatPoItem'], $this->fetchPoItemsWithMaterial($pdo, $poId));

        return $this->apiSuccess($response, [
            'po' => $this->formatPoHeader($po),
            'items' => $items,
        ]);
    }

    public function getStockInPoDetail(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $poId = (int) ($body['po_id'] ?? 0);
        if ($poId <= 0) {
            return $this->apiError($response, 'po_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $po = $this->findPoWithSupplier($pdo, $poId);
        if (!$po) {
            return $this->apiNotFound($response, 'PO tidak ditemukan');
        }

        $items = array_map([$this, 'formatPoItem'], $this->fetchPoItemsWithMaterial($pdo, $poId));

        $totalOrdered = array_sum(array_column($items, 'qty_ordered'));
        $totalNetReceived = array_sum(array_map(function ($i) {
            return $i['qty_received'] - $i['qty_returned'];
        }, $items));
        $progressPct = $totalOrdered > 0 ? (int) round($totalNetReceived / $totalOrdered * 100) : 0;

        $header = $this->formatPoHeader($po);
        $header['notes'] = $po['notes'];
        $header['progress_pct'] = $progressPct;

        return $this->apiSuccess($response, ['po' => $header, 'items' => $items]);
    }

    public function submitStockInReceive(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $poId = (int) ($body['po_id'] ?? 0);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $userId = (int) ($request->getAttribute('user_id') ?? ($body['user_id'] ?? 0));
        $notes = self::nullableString($body['notes'] ?? null);

        if ($poId <= 0 || $warehouseId <= 0) {
            return $this->apiError($response, 'po_id dan warehouse_id wajib diisi.', 422);
        }

        $items = json_decode((string) ($body['items'] ?? '[]'), true);
        if (!is_array($items) || empty($items)) {
            return $this->apiError($response, 'Items tidak valid', 422);
        }
        $hasQty = false;
        foreach ($items as $it) {
            if ((float) ($it['qty_receive'] ?? 0) > 0) {
                $hasQty = true;
                break;
            }
        }
        if (!$hasQty) {
            return $this->apiError($response, 'Minimal 1 item harus memiliki qty receive', 422);
        }

        $pdo = Database::connection();

        $po = $this->findPoWithSupplier($pdo, $poId);
        if (!$po) {
            return $this->apiError($response, 'PO tidak ditemukan', 404);
        }
        if (!in_array($po['status'], self::OUTSTANDING_STATUSES, true)) {
            return $this->apiError($response, "PO {$po['po_number']} tidak bisa di-receive (status: {$po['status']})", 400);
        }

        $pdo->beginTransaction();
        try {
            $receiveNumber = DocumentNumber::next($pdo, 'RCV');
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO pur_t_receive_warehouse
                    (receive_number, receive_date, warehouse_id, purchase_order_id, supplier_id, status, notes, created_by, created_at)
                 VALUES (:num, :now1, :wh, :po, :sup, :status, :notes, :uid, :now2)'
            );
            $ins->execute([
                'num' => $receiveNumber,
                'now1' => $now,
                'wh' => $warehouseId,
                'po' => $poId,
                'sup' => $po['supplier_id'],
                'status' => 'DRAFT',
                'notes' => $notes,
                'uid' => $userId ?: null,
                'now2' => $now,
            ]);
            $receiveId = (int) $pdo->lastInsertId();

            $photoPath = $this->uploadPhoto($request, $receiveId);
            if ($photoPath !== null) {
                $upd = $pdo->prepare('UPDATE pur_t_receive_warehouse SET photo_path = :p WHERE id = :id');
                $upd->execute(['p' => $photoPath, 'id' => $receiveId]);
            }

            foreach ($items as $item) {
                $this->processReceiveItem($pdo, $receiveId, $receiveNumber, $po, $item, $warehouseId, $userId);
            }

            $updStatus = $pdo->prepare(
                'UPDATE pur_t_receive_warehouse SET status = :status, posted_at = :now1, posted_by = :uid, updated_at = :now2 WHERE id = :id'
            );
            $now2 = date('Y-m-d H:i:s');
            $updStatus->execute(['status' => 'POSTED', 'now1' => $now2, 'uid' => $userId ?: null, 'now2' => $now2, 'id' => $receiveId]);

            $newPoStatus = $this->recalculatePoStatus($pdo, $poId, $po['status']);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['receive_id' => $receiveId, 'receive_number' => $receiveNumber, 'po_status' => $newPoStatus],
                "Penerimaan berhasil disimpan: {$receiveNumber}",
                201
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function processReceiveItem(PDO $pdo, int $receiveId, string $receiveNumber, array $po, array $item, int $warehouseId, int $userId): void
    {
        $qtyReceive = (float) ($item['qty_receive'] ?? 0);
        if ($qtyReceive <= 0) {
            return;
        }
        $poDetailId = (int) ($item['po_item_id'] ?? 0);
        $materialId = (int) ($item['material_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM pur_t_purchase_order_detail WHERE id = :id AND purchase_order_id = :po FOR UPDATE');
        $stmt->execute(['id' => $poDetailId, 'po' => $po['id']]);
        $poDetail = $stmt->fetch();
        if (!$poDetail) {
            throw new RuntimeException("Item PO #{$poDetailId} tidak ditemukan");
        }

        $sisaQty = (float) $poDetail['qty_ordered'] - (float) $poDetail['qty_received'];
        if ($qtyReceive > $sisaQty + 0.0001) {
            throw new RuntimeException("Qty receive ({$qtyReceive}) melebihi sisa PO ({$sisaQty}) untuk material #{$materialId}");
        }

        $unitPrice = (float) $poDetail['unit_price'];

        $ins = $pdo->prepare(
            'INSERT INTO pur_t_receive_warehouse_detail
                (receive_warehouse_id, material_id, po_detail_id, unit_id, qty_received, qty_rejected, unit_price, condition_status)
             VALUES (:rid, :mid, :pdid, :unit, :qty, 0, :price, :cond)'
        );
        $ins->execute([
            'rid' => $receiveId,
            'mid' => $materialId,
            'pdid' => $poDetailId,
            'unit' => $poDetail['unit_id'],
            'qty' => $qtyReceive,
            'price' => $unitPrice,
            'cond' => 'GOOD',
        ]);

        $updPod = $pdo->prepare('UPDATE pur_t_purchase_order_detail SET qty_received = qty_received + :qty WHERE id = :id');
        $updPod->execute(['qty' => $qtyReceive, 'id' => $poDetailId]);

        StockPosting::postIn($pdo, [
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'qty' => $qtyReceive,
            'unit_cost' => $unitPrice,
            'transaction_type' => 'RECEIVE_PO',
            'reference_type' => 't_receive_warehouse',
            'reference_id' => $receiveId,
            'reference_number' => $receiveNumber,
            'remarks' => "Receive PO {$po['po_number']}",
            'created_by' => $userId ?: null,
            'decrement_outstanding_in' => $qtyReceive,
        ]);
    }

    /** Port dari PurchaseOrderRepository::recalculateStatus() (versi tanpa qty_shipped/SJ). */
    private function recalculatePoStatus(PDO $pdo, int $poId, string $currentStatus): string
    {
        $stmt = $pdo->prepare('SELECT qty_ordered, qty_shipped, qty_received, qty_returned FROM pur_t_purchase_order_detail WHERE purchase_order_id = :id');
        $stmt->execute(['id' => $poId]);
        $items = $stmt->fetchAll();
        if (empty($items)) {
            return $currentStatus;
        }

        $allReceived = true;
        $anyReceived = false;
        $anyShipped = false;
        foreach ($items as $item) {
            $netReceived = (float) $item['qty_received'] - (float) $item['qty_returned'];
            if ($netReceived > 0) {
                $anyReceived = true;
            }
            if ($netReceived < (float) $item['qty_ordered']) {
                $allReceived = false;
            }
            if ((float) $item['qty_shipped'] > 0) {
                $anyShipped = true;
            }
        }

        if ($allReceived) {
            $newStatus = 'RECEIVED';
        } elseif ($anyReceived) {
            $newStatus = 'PARTIAL_RECEIVED';
        } elseif ($anyShipped) {
            $newStatus = 'SENT';
        } else {
            $newStatus = in_array($currentStatus, ['APPROVED', 'READY', 'SENT'], true) ? $currentStatus : 'APPROVED';
        }

        if ($newStatus !== $currentStatus) {
            $upd = $pdo->prepare('UPDATE pur_t_purchase_order SET status = :s, updated_at = :now WHERE id = :id');
            $upd->execute(['s' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $poId]);
        }

        return $newStatus;
    }

    private function fetchItemSummary(PDO $pdo, array $poIds): array
    {
        $poIds = array_values(array_unique(array_map('intval', $poIds)));
        if (empty($poIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT purchase_order_id,
                    COUNT(*) AS total_items,
                    SUM(qty_ordered) AS total_ordered,
                    SUM(qty_received - qty_returned) AS total_net_received,
                    ROUND(SUM(qty_received - qty_returned) / NULLIF(SUM(qty_ordered), 0) * 100, 0) AS progress_pct
             FROM pur_t_purchase_order_detail
             WHERE purchase_order_id IN ({$placeholders})
             GROUP BY purchase_order_id"
        );
        $stmt->execute($poIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['purchase_order_id']] = $r;
        }
        return $out;
    }

    private function findPoWithSupplier(PDO $pdo, int $poId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT po.*, s.supplier_nama AS supplier_name, s.supplier_type
             FROM pur_t_purchase_order po
             LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
             WHERE po.id = :id'
        );
        $stmt->execute(['id' => $poId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function fetchPoItemsWithMaterial(PDO $pdo, int $poId): array
    {
        $stmt = $pdo->prepare(
            'SELECT pod.*, m.code AS material_code, m.name AS material_name, u.code AS unit_code
             FROM pur_t_purchase_order_detail pod
             LEFT JOIN wh_m_material m ON m.id = pod.material_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             WHERE pod.purchase_order_id = :po
             ORDER BY m.name'
        );
        $stmt->execute(['po' => $poId]);
        return $stmt->fetchAll();
    }

    private function formatPoRow(array $po, $summary): array
    {
        return [
            'id' => (int) $po['id'],
            'po_number' => $po['po_number'],
            'po_type' => $po['po_type'],
            'po_source' => $po['po_source'],
            'supplier_name' => $po['supplier_name'] ?? '-',
            'supplier_type' => $po['supplier_type'] ?? null,
            'po_date' => $po['po_date'] ? date('d-m-Y', strtotime($po['po_date'])) : '-',
            'status' => $po['status'],
            'notes' => $po['notes'],
            'total_items' => $summary ? (int) $summary['total_items'] : 0,
            'total_ordered' => $summary ? (float) $summary['total_ordered'] : 0,
            'total_net_received' => $summary ? (float) $summary['total_net_received'] : 0,
            'progress_pct' => $summary ? (int) $summary['progress_pct'] : 0,
        ];
    }

    private function formatPoHeader(array $po): array
    {
        return [
            'id' => (int) $po['id'],
            'po_number' => $po['po_number'],
            'supplier_name' => $po['supplier_name'] ?? '-',
            'po_date' => $po['po_date'] ? date('d-m-Y', strtotime($po['po_date'])) : '-',
            'status' => $po['status'],
        ];
    }

    private function formatPoItem(array $d): array
    {
        $qtyOrdered = (float) $d['qty_ordered'];
        $qtyReceived = (float) $d['qty_received'];
        $qtyReturned = (float) $d['qty_returned'];

        return [
            'po_item_id' => (int) $d['id'],
            'material_id' => (int) $d['material_id'],
            'material_code' => $d['material_code'] ?? '-',
            'material_name' => $d['material_name'] ?? '-',
            'unit' => $d['unit_code'] ?? '-',
            'qty_ordered' => $qtyOrdered,
            'qty_received' => $qtyReceived,
            'qty_returned' => $qtyReturned,
            'qty_remaining' => max(0, $qtyOrdered - $qtyReceived),
        ];
    }

    private function uploadPhoto(Request $request, int $receiveId): ?string
    {
        $baseDir = __DIR__ . "/../../../public/uploads/stockin/{$receiveId}";
        return PhotoStorage::save($request, 'photo', $baseDir, "uploads/stockin/{$receiveId}", 'bukti');
    }

    private static function nullableString($v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }
}
