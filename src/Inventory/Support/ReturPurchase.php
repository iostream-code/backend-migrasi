<?php

declare(strict_types=1);

namespace App\Inventory\Support;

use App\Support\DocumentNumber;
use PDO;

/**
 * "Ajukan Retur PO" (2026-08-30, BARU -- rombak alur Retur/PO) -- Admin
 * Inventory (inventory-apk) ajukan retur atas PO yang sudah diterima. Port
 * dari backend-production `App\Services\Purchase\PurchaseReturService::create()`
 * (Eloquent) jadi raw PDO -- SUPAYA PERILAKUNYA SAMA PERSIS (cap validasi
 * per-lini, reservasi stok) -- tabel `pur_t_retur_purchase(_detail)` &
 * `wh_t_stock_balance` MILIK modul Purchase/Warehouse backend-production,
 * dibaca/ditulis LANGSUNG dari sini (satu database produksi yang sama),
 * pola SAMA PERSIS dgn `Ekspedisi\Support\PoSuratJalan`/`ReturPoSuratJalan`
 * -- baca docblock kelas itu utk konteks lengkap kenapa pola ini dipakai.
 *
 * Setelah SUBMITTED di sini, approve/reject-nya ITU SENDIRI tetap di
 * backend-production (`PurchaseReturController`, dipakai produksi-apk User
 * Pusat) -- modul ini HANYA menangani pengajuan awal.
 */
class ReturPurchase
{
    /**
     * POST /inventory/purchase-retur/create.
     *
     * `$data`: `purchase_order_id` (wajib), `notes` (wajib), `warehouse_id`
     * (wajib), `user_id` (wajib -- submitted_by), `items` (wajib, min 1,
     * `[{po_detail_id, qty_returned, reason?, notes?}, ...]`).
     *
     * Cap per-lini (SAMA PERSIS dgn PurchaseReturService::create()):
     *   qty_returned <= qty_received - qty_returned(akumulasi PO detail)
     *                  - qty_pending(retur lain yg masih SUBMITTED)
     *
     * @throws \InvalidArgumentException pesan siap ditampilkan ke user
     */
    public static function create(PDO $pdo, array $data): array
    {
        $poId = (int) ($data['purchase_order_id'] ?? 0);
        $notes = trim((string) ($data['notes'] ?? ''));
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);
        $items = $data['items'] ?? [];

        if ($poId <= 0) {
            throw new \InvalidArgumentException('purchase_order_id wajib diisi.');
        }
        if ($notes === '') {
            throw new \InvalidArgumentException('Catatan/alasan retur wajib diisi.');
        }
        if (!$items) {
            throw new \InvalidArgumentException('items wajib diisi minimal 1.');
        }

        $podIds = [];
        foreach ($items as $item) {
            $podId = (int) ($item['po_detail_id'] ?? 0);
            $qty = (float) ($item['qty_returned'] ?? 0);
            if ($podId <= 0 || $qty <= 0) {
                throw new \InvalidArgumentException('po_detail_id dan qty_returned (> 0) wajib diisi tiap item.');
            }
            $podIds[] = $podId;
        }
        if (count($podIds) !== count(array_unique($podIds))) {
            throw new \InvalidArgumentException(
                'Terdapat po_detail_id yang dikirim lebih dari sekali. Gabungkan qty untuk detail yang sama menjadi satu baris.'
            );
        }

        $pdo->beginTransaction();
        try {
            $poStmt = $pdo->prepare('SELECT * FROM pur_t_purchase_order WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $poStmt->execute(['id' => $poId]);
            $po = $poStmt->fetch();
            if (!$po) {
                throw new \InvalidArgumentException("Purchase Order ID {$poId} tidak ditemukan.");
            }
            if (!in_array($po['status'], ['RECEIVED', 'PARTIAL_RECEIVED'], true)) {
                throw new \InvalidArgumentException(
                    "PO berstatus '{$po['status']}' belum bisa diretur. Hanya PO yang sudah (sebagian) diterima."
                );
            }

            $resolvedWarehouseId = $warehouseId ?: (int) $po['warehouse_id'];

            $placeholders = implode(',', array_fill(0, count($podIds), '?'));
            $lockStmt = $pdo->prepare("SELECT * FROM pur_t_purchase_order_detail WHERE id IN ($placeholders) FOR UPDATE");
            $lockStmt->execute($podIds);
            $podMap = [];
            foreach ($lockStmt->fetchAll() as $row) {
                $podMap[(int) $row['id']] = $row;
            }

            $pendingByPod = self::pendingQtyByPoDetail($pdo, $podIds);

            // Validasi SEMUA line dulu -- fail fast, tidak ada partial insert.
            foreach ($items as $item) {
                $podId = (int) $item['po_detail_id'];
                $pod = $podMap[$podId] ?? null;
                if (!$pod) {
                    throw new \InvalidArgumentException("po_detail_id {$podId} tidak ditemukan pada Purchase Order #{$poId}.");
                }
                if ((int) $pod['purchase_order_id'] !== $poId) {
                    throw new \InvalidArgumentException("po_detail_id {$podId} bukan milik Purchase Order #{$poId}.");
                }

                $qtyReturn = (float) $item['qty_returned'];
                $alreadyReturned = (float) $pod['qty_returned'];
                $pending = (float) ($pendingByPod[$podId] ?? 0);
                $maxReturnable = (float) $pod['qty_received'] - $alreadyReturned - $pending;

                if ($qtyReturn > $maxReturnable + 0.0001) {
                    throw new \InvalidArgumentException(
                        "Qty retur ({$qtyReturn}) untuk material #{$pod['material_id']} melebihi sisa yang bisa diretur "
                            . "({$maxReturnable}). Diterima: {$pod['qty_received']}, sudah diretur: {$alreadyReturned}, "
                            . "masih diproses: {$pending}."
                    );
                }
            }

            $returNumber = DocumentNumber::next($pdo, 'RTP');
            $now = date('Y-m-d H:i:s');

            $pdo->prepare(
                "INSERT INTO pur_t_retur_purchase
                    (retur_number, retur_date, warehouse_id, source_type, purchase_order_id, supplier_id,
                     reason, retur_action, notes, status, created_by, updated_by, created_at, updated_at)
                 VALUES
                    (:retur_number, :retur_date, :warehouse_id, 'PO', :purchase_order_id, :supplier_id,
                     :reason, 'REPLACEMENT', :notes, 'SUBMITTED', :created_by, :updated_by, :created_at, :updated_at)"
            )->execute([
                'retur_number' => $returNumber,
                'retur_date' => $now,
                'warehouse_id' => $resolvedWarehouseId,
                'purchase_order_id' => $poId,
                'supplier_id' => $po['supplier_id'],
                // Header 'reason' diisi dari item pertama -- kolom detail
                // tetap punya reason per-lini sendiri (condition_status),
                // header cuma ringkasan (sama pola dgn source_type/retur_action).
                'reason' => $items[0]['reason'] ?? 'OTHER',
                'notes' => $notes,
                'created_by' => $userId ?: null,
                'updated_by' => $userId ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $returId = (int) $pdo->lastInsertId();

            $detailInsert = $pdo->prepare(
                'INSERT INTO pur_t_retur_purchase_detail
                    (retur_purchase_id, material_id, po_detail_id, unit_id, qty_returned, qty_replaced,
                     unit_price, condition_status, notes)
                 VALUES
                    (:retur_purchase_id, :material_id, :po_detail_id, :unit_id, :qty_returned, 0,
                     :unit_price, :condition_status, :notes)'
            );
            foreach ($items as $item) {
                $podId = (int) $item['po_detail_id'];
                $pod = $podMap[$podId];
                $qtyReturn = (float) $item['qty_returned'];

                $detailInsert->execute([
                    'retur_purchase_id' => $returId,
                    'material_id' => $pod['material_id'],
                    'po_detail_id' => $podId,
                    'unit_id' => $pod['unit_id'],
                    'qty_returned' => $qtyReturn,
                    'unit_price' => $pod['unit_price'],
                    'condition_status' => $item['reason'] ?? 'OTHER',
                    'notes' => $item['notes'] ?? null,
                ]);

                self::reserveStock($pdo, $resolvedWarehouseId, (int) $pod['material_id'], $qtyReturn);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'id' => $returId,
            'retur_number' => $returNumber,
            'status' => 'SUBMITTED',
            'po_number' => $po['po_number'],
            'total_items' => count($items),
        ];
    }

    /**
     * Total qty_returned dari retur lain yang MASIH SUBMITTED (belum
     * di-approve/reject Pusat) per po_detail_id -- dipakai cap validasi,
     * SAMA PERSIS dgn PurchaseReturRepository::getPendingQtyByPoDetail().
     * Retur APPROVED sudah ikut terhitung di pur_t_purchase_order_detail.qty_returned
     * langsung (lihat PurchaseReturService::approve() backend-production),
     * jadi TIDAK dihitung dobel di sini.
     *
     * @return array<int, float> po_detail_id => total qty pending
     */
    private static function pendingQtyByPoDetail(PDO $pdo, array $podIds): array
    {
        if (!$podIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($podIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT d.po_detail_id, SUM(d.qty_returned) AS total
             FROM pur_t_retur_purchase_detail d
             JOIN pur_t_retur_purchase r ON r.id = d.retur_purchase_id
             WHERE d.po_detail_id IN ($placeholders) AND r.status = 'SUBMITTED' AND r.deleted_at IS NULL
             GROUP BY d.po_detail_id"
        );
        $stmt->execute($podIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['po_detail_id']] = (float) $row['total'];
        }

        return $out;
    }

    /**
     * Reservasi stok saat retur diajukan (SUBMITTED): qty_outstanding_out
     * naik, qty_on_hand TIDAK berubah -- SAMA PERSIS dgn
     * PurchaseReturService::reserveStock() (backend-production). Dilepas
     * (kalau reject) atau dikonsumsi jadi mutation OUT (kalau approve) di
     * backend-production sana, TIDAK diurus dari sini.
     *
     * @throws \InvalidArgumentException stok tidak cukup utk reservasi
     */
    private static function reserveStock(PDO $pdo, int $warehouseId, int $materialId, float $qty): void
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM wh_t_stock_balance WHERE warehouse_id = :wh AND material_id = :mat FOR UPDATE'
        );
        $stmt->execute(['wh' => $warehouseId, 'mat' => $materialId]);
        $balance = $stmt->fetch();

        if (!$balance) {
            throw new \InvalidArgumentException(
                "Tidak ada data stok untuk material ID {$materialId} di warehouse ID {$warehouseId} -- tidak bisa diretur."
            );
        }

        $available = (float) $balance['qty_on_hand'] - (float) $balance['qty_outstanding_out'] - $qty;
        if ($available < -0.0001) {
            throw new \InvalidArgumentException(
                "Stok material ID {$materialId} tidak cukup untuk reservasi retur. On hand: {$balance['qty_on_hand']}, "
                    . "sudah direservasi: {$balance['qty_outstanding_out']}, diminta: {$qty}."
            );
        }

        $newOutstandingOut = (float) $balance['qty_outstanding_out'] + $qty;
        $newAvailable = (float) $balance['qty_on_hand'] - $newOutstandingOut;

        $pdo->prepare(
            'UPDATE wh_t_stock_balance SET qty_outstanding_out = :out, qty_available = :avail, updated_at = :now WHERE id = :id'
        )->execute([
            'out' => $newOutstandingOut,
            'avail' => $newAvailable,
            'now' => date('Y-m-d H:i:s'),
            'id' => $balance['id'],
        ]);
    }

    /**
     * GET /inventory/purchase-retur/eligible-po -- daftar PO milik 1
     * warehouse yang sudah (sebagian) diterima, lengkap dgn lini item +
     * sisa qty yang masih bisa diretur (`qty_received - qty_returned -
     * pending`) -- sumber data utk picker "Ajukan Retur" inventory-apk.
     * Cuma lini dgn sisa > 0 yang dikeluarkan (mirip pola outstanding-po
     * di Ekspedisi::PoSuratJalan).
     */
    public static function eligiblePo(PDO $pdo, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            "SELECT po.id AS po_id, po.po_number, po.supplier_id,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name
             FROM pur_t_purchase_order po
             LEFT JOIN shared_m_supplier s ON s.supplier_id = po.supplier_id
             WHERE po.deleted_at IS NULL AND po.warehouse_id = :wh
                   AND po.status IN ('RECEIVED', 'PARTIAL_RECEIVED')
             ORDER BY po.po_date DESC"
        );
        $stmt->execute(['wh' => $warehouseId]);
        $pos = $stmt->fetchAll();
        if (!$pos) {
            return [];
        }

        $poIds = array_column($pos, 'po_id');
        $placeholders = implode(',', array_fill(0, count($poIds), '?'));
        $detailStmt = $pdo->prepare(
            "SELECT d.id AS po_detail_id, d.purchase_order_id, d.material_id, d.unit_id,
                    d.qty_received, d.qty_returned,
                    COALESCE(m.name, '-') AS material_name, COALESCE(u.code, '-') AS unit_code
             FROM pur_t_purchase_order_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.purchase_order_id IN ($placeholders)
             ORDER BY d.id"
        );
        $detailStmt->execute($poIds);
        $detailRows = $detailStmt->fetchAll();

        $pending = self::pendingQtyByPoDetail($pdo, array_column($detailRows, 'po_detail_id'));

        $itemsByPoId = [];
        foreach ($detailRows as $d) {
            $sisa = (float) $d['qty_received'] - (float) $d['qty_returned'] - (float) ($pending[(int) $d['po_detail_id']] ?? 0);
            if ($sisa <= 0.0001) {
                continue;
            }
            $itemsByPoId[(int) $d['purchase_order_id']][] = [
                'po_detail_id' => (int) $d['po_detail_id'],
                'material_name' => $d['material_name'],
                'unit_code' => $d['unit_code'],
                'qty_returnable' => $sisa,
            ];
        }

        $result = [];
        foreach ($pos as $po) {
            $items = $itemsByPoId[(int) $po['po_id']] ?? [];
            if ($items) {
                $result[] = [
                    'po_id' => (int) $po['po_id'],
                    'po_number' => $po['po_number'],
                    'supplier_name' => $po['supplier_name'],
                    'items' => $items,
                ];
            }
        }

        return $result;
    }

    /**
     * POST /inventory/purchase-retur/list -- riwayat retur milik 1 warehouse
     * (SEMUA status, terbaru dulu), dgn breakdown item -- pola sama dgn
     * HomeController::listPurchaseRequest().
     */
    public static function list(PDO $pdo, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.retur_number, r.retur_date, r.status, r.retur_action, r.reason, r.notes,
                    r.rejected_reason,
                    po.po_number,
                    COALESCE(s.supplier_nama, s.code, '-') AS supplier_name,
                    u.username AS requester_name
             FROM pur_t_retur_purchase r
             LEFT JOIN pur_t_purchase_order po ON po.id = r.purchase_order_id
             LEFT JOIN shared_m_supplier s ON s.supplier_id = r.supplier_id
             LEFT JOIN shared_m_users u ON u.user_id = r.created_by
             WHERE r.deleted_at IS NULL AND r.warehouse_id = :wh
             ORDER BY r.retur_date DESC, r.id DESC
             LIMIT 100"
        );
        $stmt->execute(['wh' => $warehouseId]);
        $returs = $stmt->fetchAll();
        if (!$returs) {
            return [];
        }

        $itemsByReturId = self::batchItems($pdo, array_column($returs, 'id'));

        return array_map(function ($r) use ($itemsByReturId) {
            $items = $itemsByReturId[(int) $r['id']] ?? [];
            return [
                'id' => (int) $r['id'],
                'retur_number' => $r['retur_number'],
                'retur_date' => $r['retur_date'] ? date('Y-m-d H:i', strtotime($r['retur_date'])) : null,
                'status' => $r['status'],
                'retur_action' => $r['retur_action'],
                'reason' => $r['reason'],
                'po_number' => $r['po_number'],
                'supplier_name' => $r['supplier_name'],
                'requester_name' => $r['requester_name'] ?? '-',
                'notes' => $r['notes'],
                'rejected_reason' => $r['rejected_reason'],
                'total_items' => count($items),
                'items' => $items,
            ];
        }, $returs);
    }

    /** @return array<int,array> keyed by retur_purchase_id */
    private static function batchItems(PDO $pdo, array $returIds): array
    {
        $returIds = array_values(array_unique(array_map('intval', $returIds)));
        if (!$returIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($returIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT d.retur_purchase_id, d.material_id, d.qty_returned, d.qty_replaced,
                    m.code AS material_code, m.name AS material_name, u.code AS unit_code
             FROM pur_t_retur_purchase_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = d.unit_id
             WHERE d.retur_purchase_id IN ($placeholders)"
        );
        $stmt->execute($returIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['retur_purchase_id']][] = [
                'material_id' => (int) $row['material_id'],
                'material_code' => $row['material_code'] ?? '-',
                'material_name' => $row['material_name'] ?? '-',
                'unit_code' => $row['unit_code'] ?? '-',
                'qty_returned' => (float) $row['qty_returned'],
                'qty_replaced' => (float) $row['qty_replaced'],
            ];
        }

        return $out;
    }
}
