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
 * Port dari backend-production App\Http\Controllers\API\Inventory\StockOutController
 * (+ MaterialIssueService, Eloquent) ke Slim/PDO polos -- alur Material Issue
 * (stock out dari request produksi) saja. Field request/response dikutip apa
 * adanya dari sana (req_item_id, qty_remaining, dst) supaya kompatibel kalau
 * inventory-apk suatu saat di-pointing kesini.
 *
 * TIDAK diport di pass ini (lihat inventory-apk/ROADMAP.md): getStockOutDone,
 * getStockOutHistory, flow retur produksi 3-tahap
 * (inbox/approve/receive/reject/detail/history) -- belum dipakai FE sekarang.
 *
 * **Manual Stock Out** (2026-08-23, `submitStockOutManual()`) -- port dari
 * `App\Services\Inventory\StockOut\ManualStockOutService::submit()`
 * (Eloquent). Sama tabel dgn Manual Stock In (`wh_t_stock_adjustment`/
 * `_detail`, type=OUT, source=MANUAL) & Opname (`OpnameController::
 * createAdjustmentForOpname()`), cuma beda `reason`. AdminGudang-only (role
 * dari JWT, pola sama Opname approve/reject & Manual Stock In -- versi asli
 * TIDAK py gate ini sama sekali). **TIDAK ADA foto** -- asimetri SENGAJA dari
 * versi asli (`ManualStockOutService::submit()` sama sekali tidak menangani
 * upload foto, beda dari `ManualStockInService` yang opsional -- dikutip apa
 * adanya, bukan lupa porting). Validasi stok cukup diserahkan ke
 * `StockPosting::postOut()` (sudah lempar exception kalau kurang) -- versi
 * Laravel asli py double-check manual `assertOnHandEnough()` SEBELUM
 * postOut(), sengaja TIDAK direplikasi krn redundan (postOut() dgn lock FOR
 * UPDATE sudah cukup & lebih aman thd race condition drpd cek terpisah).
 * `getStockOutManualMaterials`/`getStockOutManualHistory` versi asli TIDAK
 * diport, sama alasan dgn Manual Stock In (lihat StockInController.php).
 *
 * requester_name/department_name SENGAJA tidak di-join (beda dari
 * MaterialIssueService asli yang selalu sertakan) -- frontend inventory-apk
 * saat ini tidak merender field itu sama sekali (cek src/pages/stock-out/),
 * dan requester asli join ke tabel `users` LEGACY (bukan shared_m_users yang
 * dipakai auth modul ini) -- kalau nanti field itu dibutuhkan FE, join ulang
 * ke shared_m_users (konsisten dgn OpnameController::getSessions), BUKAN ke
 * `users`.
 */
class StockOutController
{
    use ApiEnvelope;

    private const ISSUABLE_STATUSES = ['APPROVED', 'PARTIAL_ISSUED'];

    public function getStockOutActive(Request $request, Response $response): Response
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

        $sql = 'SELECT rm.* FROM prd_t_req_material rm
                WHERE rm.warehouse_id = ? AND rm.status IN (' . implode(',', array_fill(0, count(self::ISSUABLE_STATUSES), '?')) . ')
                  AND rm.deleted_at IS NULL';
        $params = array_merge([$warehouseId], self::ISSUABLE_STATUSES);

        if ($statusFilter === 'open') {
            $sql .= ' AND rm.status = ?';
            $params[] = 'APPROVED';
        } elseif ($statusFilter === 'partial') {
            $sql .= ' AND rm.status = ?';
            $params[] = 'PARTIAL_ISSUED';
        }
        if ($search !== null) {
            $sql .= ' AND (rm.req_number LIKE ? OR rm.notes LIKE ? OR rm.spk_number LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($month && $year) {
            $sql .= ' AND MONTH(rm.req_date) = ? AND YEAR(rm.req_date) = ?';
            $params[] = $month;
            $params[] = $year;
        } elseif ($year) {
            $sql .= ' AND YEAR(rm.req_date) = ?';
            $params[] = $year;
        }
        $sql .= " ORDER BY FIELD(rm.status, 'PARTIAL_ISSUED', 'APPROVED'),
                           FIELD(rm.priority, 'URGENT', 'HIGH', 'NORMAL', 'LOW'), rm.req_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $reqRows = $stmt->fetchAll();

        $summary = $this->fetchItemSummary($pdo, array_column($reqRows, 'id'));

        $reqList = array_map(function ($r) use ($summary) {
            return $this->formatReqRow($r, $summary[(int) $r['id']] ?? null);
        }, $reqRows);

        return $this->apiSuccess($response, ['req_list' => $reqList]);
    }

    public function getStockOutReqItems(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $reqId = (int) ($body['req_id'] ?? 0);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($reqId <= 0 || $warehouseId <= 0) {
            return $this->apiError($response, 'req_id dan warehouse_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $req = $this->findReq($pdo, $reqId);
        if (!$req) {
            return $this->apiNotFound($response, 'Request material tidak ditemukan');
        }

        $items = array_map(function ($d) {
            return $this->formatReqItem($d, true);
        }, $this->fetchReqItemsWithMaterial($pdo, $reqId, $warehouseId));

        return $this->apiSuccess($response, [
            'request' => $this->formatReqHeader($req),
            'items' => $items,
        ]);
    }

    public function getStockOutReqDetail(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $reqId = (int) ($body['req_id'] ?? 0);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($reqId <= 0 || $warehouseId <= 0) {
            return $this->apiError($response, 'req_id dan warehouse_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $req = $this->findReq($pdo, $reqId);
        if (!$req) {
            return $this->apiNotFound($response, 'Request material tidak ditemukan');
        }

        $items = array_map(function ($d) {
            return $this->formatReqItem($d, false);
        }, $this->fetchReqItemsWithMaterial($pdo, $reqId, $warehouseId));

        $totalApproved = array_sum(array_column($items, 'qty_approved'));
        $totalIssued = array_sum(array_column($items, 'qty_issued'));
        $progressPct = $totalApproved > 0 ? (int) round($totalIssued / $totalApproved * 100) : 0;

        $header = $this->formatReqHeader($req);
        $header['notes'] = $req['notes'];
        $header['progress_pct'] = $progressPct;

        return $this->apiSuccess($response, ['request' => $header, 'items' => $items]);
    }

    public function submitStockOut(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $reqId = (int) ($body['req_id'] ?? 0);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $userId = (int) ($request->getAttribute('user_id') ?? ($body['user_id'] ?? 0));
        $notes = self::nullableString($body['notes'] ?? null);

        if ($reqId <= 0 || $warehouseId <= 0) {
            return $this->apiError($response, 'req_id dan warehouse_id wajib diisi.', 422);
        }

        $items = json_decode((string) ($body['items'] ?? '[]'), true);
        if (!is_array($items) || empty($items)) {
            return $this->apiError($response, 'Items tidak valid', 422);
        }
        $hasQty = false;
        foreach ($items as $it) {
            if ((float) ($it['qty_out'] ?? 0) > 0) {
                $hasQty = true;
                break;
            }
        }
        if (!$hasQty) {
            return $this->apiError($response, 'Minimal 1 item harus memiliki qty keluar', 422);
        }

        $pdo = Database::connection();

        $req = $this->findReq($pdo, $reqId);
        if (!$req) {
            return $this->apiError($response, 'Request material tidak ditemukan', 404);
        }
        if (!in_array($req['status'], self::ISSUABLE_STATUSES, true)) {
            return $this->apiError($response, "Request {$req['req_number']} tidak bisa di-issue (status: {$req['status']})", 400);
        }

        $pdo->beginTransaction();
        try {
            $issueNumber = DocumentNumber::next($pdo, 'ISS');
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO prd_t_material_issue
                    (issue_number, issue_date, warehouse_id, req_material_id, department_id, status, notes, created_by, created_at)
                 VALUES (:num, :now1, :wh, :req, :dept, :status, :notes, :uid, :now2)'
            );
            $ins->execute([
                'num' => $issueNumber,
                'now1' => $now,
                'wh' => $warehouseId,
                'req' => $reqId,
                'dept' => $req['department_id'],
                'status' => 'DRAFT',
                'notes' => $notes,
                'uid' => $userId ?: null,
                'now2' => $now,
            ]);
            $issueId = (int) $pdo->lastInsertId();

            $photoPath = $this->uploadPhoto($request, $issueId);

            foreach ($items as $item) {
                $this->processIssueItem($pdo, $issueId, $issueNumber, $req, $item, $warehouseId, $userId);
            }

            $now2 = date('Y-m-d H:i:s');
            $updFields = 'status = :status, posted_at = :now1, posted_by = :uid, updated_at = :now2';
            $params = ['status' => 'POSTED', 'now1' => $now2, 'uid' => $userId ?: null, 'now2' => $now2, 'id' => $issueId];
            if ($photoPath !== null) {
                $updFields .= ', photo_path = :photo';
                $params['photo'] = $photoPath;
            }
            $pdo->prepare("UPDATE prd_t_material_issue SET {$updFields} WHERE id = :id")->execute($params);

            $newReqStatus = $this->recalculateReqStatus($pdo, $reqId, $req['status']);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['issue_id' => $issueId, 'issue_number' => $issueNumber, 'req_status' => $newReqStatus],
                "Pengeluaran berhasil disimpan: {$issueNumber}",
                201
            );
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * POST /inventory/stock-out/submit-stockout-manual (2026-08-23)
     * Port dari ManualStockOutService::submit() -- stock out AD-HOC di luar
     * request produksi (mis. barang rusak dibuang, sample QC, hilang,
     * disposal), langsung posting ke `wh_t_stock_adjustment`(type=OUT,
     * source=MANUAL) + detail, BUKAN ke `prd_t_material_issue` (itu KHUSUS
     * issue ke produksi, lihat submitStockOut() di atas).
     *
     * AdminGudang-only -- role SELALU dari JWT, 403 kalau bukan.
     *
     * body: { warehouse_id (wajib), reason? (bebas, label spt HADIAH/
     * KERUSAKAN/HILANG -- default 'MANUAL_OUT' kalau kosong), notes?, items
     * (wajib, JSON string [{material_id, qty, item_notes?}]) }. TIDAK ADA
     * `photo` (lihat docblock class ini).
     */
    public function submitStockOutManual(Request $request, Response $response): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh melakukan Stock Out manual.', 403);
        }

        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $userId = (int) ($request->getAttribute('user_id') ?? ($body['user_id'] ?? 0));
        $reason = self::nullableString($body['reason'] ?? null) ?? 'MANUAL_OUT';
        $notes = self::nullableString($body['notes'] ?? null);

        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }

        $items = json_decode((string) ($body['items'] ?? '[]'), true);
        if (!is_array($items) || empty($items)) {
            return $this->apiError($response, 'Items tidak valid', 422);
        }
        $hasQty = false;
        foreach ($items as $it) {
            if ((float) ($it['qty'] ?? 0) > 0) {
                $hasQty = true;
                break;
            }
        }
        if (!$hasQty) {
            return $this->apiError($response, 'Minimal 1 item harus memiliki qty > 0', 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $adjNumber = $this->nextAdjustmentNumber($pdo);
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO wh_t_stock_adjustment
                    (adjustment_number, adjustment_date, warehouse_id, adjustment_type, source_type,
                     reason, status, notes, created_by, created_at, updated_at)
                 VALUES (:num, :now1, :wh, \'OUT\', \'MANUAL\', :reason, \'DRAFT\', :notes, :uid, :now2, :now3)'
            );
            $ins->execute([
                'num' => $adjNumber, 'now1' => $now, 'wh' => $warehouseId, 'reason' => $reason,
                'notes' => $notes, 'uid' => $userId ?: null, 'now2' => $now, 'now3' => $now,
            ]);
            $adjId = (int) $pdo->lastInsertId();

            foreach ($items as $item) {
                $this->processManualOutItem($pdo, $adjId, $adjNumber, $warehouseId, $item, $userId);
            }

            $now2 = date('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE wh_t_stock_adjustment SET status = \'POSTED\', posted_at = :now, posted_by = :uid WHERE id = :id'
            )->execute(['now' => $now2, 'uid' => $userId ?: null, 'id' => $adjId]);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['adjustment_id' => $adjId, 'doc_number' => $adjNumber],
                "Stock Out manual berhasil disimpan: {$adjNumber}",
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

    /** unit_cost di-resolve StockPosting::postOut() sendiri dari avg_unit_cost saat ini (WAC). */
    private function processManualOutItem(PDO $pdo, int $adjId, string $adjNumber, int $warehouseId, array $item, int $userId): void
    {
        $materialId = (int) ($item['material_id'] ?? 0);
        $qty = (float) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            return;
        }
        $itemNotes = self::nullableString($item['item_notes'] ?? null);

        $matStmt = $pdo->prepare('SELECT unit_id FROM wh_m_material WHERE id = :id');
        $matStmt->execute(['id' => $materialId]);
        $unitId = $matStmt->fetchColumn();
        if ($unitId === false) {
            throw new RuntimeException("Material #{$materialId} tidak ditemukan");
        }

        $ins = $pdo->prepare(
            'INSERT INTO wh_t_stock_adjustment_detail (stock_adjustment_id, material_id, qty, unit_id, unit_cost, notes)
             VALUES (:adj, :mat, :qty, :unit, 0, :notes)'
        );
        $ins->execute(['adj' => $adjId, 'mat' => $materialId, 'qty' => $qty, 'unit' => $unitId, 'notes' => $itemNotes]);

        StockPosting::postOut($pdo, [
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'qty' => $qty,
            'transaction_type' => 'ADJUSTMENT_OUT',
            'reference_type' => 'wh_t_stock_adjustment',
            'reference_id' => $adjId,
            'reference_number' => $adjNumber,
            'remarks' => $itemNotes ?: "Manual Stock Out {$adjNumber}",
            'created_by' => $userId ?: null,
        ]);
    }

    /** Sync counter ke max aktual dulu (pola sama OpnameController::createAdjustmentForOpname()/StockInController). */
    private function nextAdjustmentNumber(PDO $pdo): string
    {
        $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(adjustment_number, 5) AS UNSIGNED)) AS max_num FROM wh_t_stock_adjustment WHERE adjustment_number LIKE 'ADJ-%'")->fetch();
        $maxNum = (int) ($row['max_num'] ?? 0);
        if ($maxNum > 0) {
            DocumentNumber::syncToAtLeast($pdo, 'ADJ', $maxNum);
        }

        return DocumentNumber::next($pdo, 'ADJ');
    }

    private function processIssueItem(PDO $pdo, int $issueId, string $issueNumber, array $req, array $item, int $warehouseId, int $userId): void
    {
        $qtyOut = (float) ($item['qty_out'] ?? 0);
        if ($qtyOut <= 0) {
            return;
        }
        $reqDetailId = (int) ($item['req_item_id'] ?? 0);
        $materialId = (int) ($item['material_id'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM prd_t_req_material_detail WHERE id = :id AND req_material_id = :req FOR UPDATE');
        $stmt->execute(['id' => $reqDetailId, 'req' => $req['id']]);
        $reqDetail = $stmt->fetch();
        if (!$reqDetail) {
            throw new RuntimeException("Item request #{$reqDetailId} tidak ditemukan");
        }

        $sisaQty = (float) $reqDetail['qty_approved'] - (float) $reqDetail['qty_issued'];
        if ($qtyOut > $sisaQty + 0.0001) {
            throw new RuntimeException("Qty keluar ({$qtyOut}) melebihi sisa ({$sisaQty}) untuk material #{$materialId}");
        }

        $balStmt = $pdo->prepare('SELECT qty_on_hand FROM wh_t_stock_balance WHERE warehouse_id = :w AND material_id = :m');
        $balStmt->execute(['w' => $warehouseId, 'm' => $materialId]);
        $onHand = (float) ($balStmt->fetchColumn() ?: 0);
        if ($onHand < $qtyOut - 0.0001) {
            throw new RuntimeException("Stok tidak cukup untuk material #{$materialId} (tersedia: {$onHand}, diminta: {$qtyOut})");
        }

        $ins = $pdo->prepare(
            'INSERT INTO prd_t_material_issue_detail
                (material_issue_id, req_material_detail_id, material_id, unit_id, qty, unit_cost, notes)
             VALUES (:iid, :rdid, :mid, :unit, :qty, 0, :notes)'
        );
        $ins->execute([
            'iid' => $issueId,
            'rdid' => $reqDetailId,
            'mid' => $materialId,
            'unit' => $reqDetail['unit_id'],
            'qty' => $qtyOut,
            'notes' => self::nullableString($item['item_notes'] ?? null),
        ]);

        $updRd = $pdo->prepare('UPDATE prd_t_req_material_detail SET qty_issued = qty_issued + :qty WHERE id = :id');
        $updRd->execute(['qty' => $qtyOut, 'id' => $reqDetailId]);

        StockPosting::postOut($pdo, [
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'qty' => $qtyOut,
            'transaction_type' => 'ISSUE_PRODUKSI',
            'reference_type' => 'prd_t_material_issue',
            'reference_id' => $issueId,
            'reference_number' => $issueNumber,
            'remarks' => "Issue ke produksi -- {$req['req_number']}",
            'created_by' => $userId ?: null,
            'decrement_outstanding_out' => $qtyOut,
        ]);
    }

    /** Port dari ReqMaterialRepository::recalculateStatus(). */
    private function recalculateReqStatus(PDO $pdo, int $reqId, string $currentStatus): string
    {
        $stmt = $pdo->prepare('SELECT qty_approved, qty_issued FROM prd_t_req_material_detail WHERE req_material_id = :id');
        $stmt->execute(['id' => $reqId]);
        $items = $stmt->fetchAll();
        if (empty($items)) {
            return $currentStatus;
        }

        $allDone = true;
        $anyIssued = false;
        foreach ($items as $item) {
            $qtyTarget = (float) $item['qty_approved'];
            $qtyIssued = (float) $item['qty_issued'];
            if ($qtyIssued > 0) {
                $anyIssued = true;
            }
            if ($qtyIssued < $qtyTarget) {
                $allDone = false;
            }
        }

        if ($allDone) {
            $newStatus = 'ISSUED';
        } elseif ($anyIssued) {
            $newStatus = 'PARTIAL_ISSUED';
        } else {
            $newStatus = in_array($currentStatus, ['SUBMITTED', 'APPROVED'], true) ? $currentStatus : 'APPROVED';
        }

        if ($newStatus !== $currentStatus) {
            $upd = $pdo->prepare('UPDATE prd_t_req_material SET status = :s, updated_at = :now WHERE id = :id');
            $upd->execute(['s' => $newStatus, 'now' => date('Y-m-d H:i:s'), 'id' => $reqId]);
        }

        return $newStatus;
    }

    private function fetchItemSummary(PDO $pdo, array $reqIds): array
    {
        $reqIds = array_values(array_unique(array_map('intval', $reqIds)));
        if (empty($reqIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($reqIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT req_material_id,
                    COUNT(*) AS total_items,
                    SUM(qty_requested) AS total_requested,
                    SUM(qty_approved) AS total_approved,
                    SUM(qty_issued) AS total_issued,
                    ROUND(SUM(qty_issued) / NULLIF(SUM(qty_approved), 0) * 100, 0) AS progress_pct
             FROM prd_t_req_material_detail
             WHERE req_material_id IN ({$placeholders})
             GROUP BY req_material_id"
        );
        $stmt->execute($reqIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['req_material_id']] = $r;
        }
        return $out;
    }

    private function findReq(PDO $pdo, int $reqId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM prd_t_req_material WHERE id = :id');
        $stmt->execute(['id' => $reqId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function fetchReqItemsWithMaterial(PDO $pdo, int $reqId, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT rmd.*, m.code AS material_code, m.name AS material_name, u.code AS unit_code,
                    COALESCE(sb.qty_on_hand, 0) AS current_stock
             FROM prd_t_req_material_detail rmd
             LEFT JOIN wh_m_material m ON m.id = rmd.material_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             LEFT JOIN wh_t_stock_balance sb ON sb.material_id = rmd.material_id AND sb.warehouse_id = :wh
             WHERE rmd.req_material_id = :req
             ORDER BY m.name'
        );
        $stmt->execute(['wh' => $warehouseId, 'req' => $reqId]);
        return $stmt->fetchAll();
    }

    private function formatReqRow(array $r, $summary): array
    {
        return [
            'id' => (int) $r['id'],
            'req_number' => $r['req_number'],
            'req_date' => $r['req_date'] ? date('d-m-Y', strtotime($r['req_date'])) : '-',
            'spk_number' => $r['spk_number'],
            'status' => $r['status'],
            'priority' => $r['priority'],
            'notes' => $r['notes'],
            'total_items' => $summary ? (int) $summary['total_items'] : 0,
            'total_requested' => $summary ? (float) $summary['total_requested'] : 0,
            'total_approved' => $summary ? (float) $summary['total_approved'] : 0,
            'total_issued' => $summary ? (float) $summary['total_issued'] : 0,
            'progress_pct' => $summary ? (int) $summary['progress_pct'] : 0,
        ];
    }

    private function formatReqHeader(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'req_number' => $r['req_number'],
            'req_date' => $r['req_date'] ? date('d-m-Y', strtotime($r['req_date'])) : '-',
            'spk_number' => $r['spk_number'],
            'priority' => $r['priority'],
            'status' => $r['status'],
        ];
    }

    private function formatReqItem(array $d, bool $withStock): array
    {
        $qtyRequested = (float) $d['qty_requested'];
        $qtyApproved = (float) $d['qty_approved'];
        $qtyIssued = (float) $d['qty_issued'];

        $row = [
            'req_item_id' => (int) $d['id'],
            'material_id' => (int) $d['material_id'],
            'material_code' => $d['material_code'] ?? '-',
            'material_name' => $d['material_name'] ?? '-',
            'unit' => $d['unit_code'] ?? '-',
            'qty_requested' => $qtyRequested,
            'qty_approved' => $qtyApproved,
            'qty_issued' => $qtyIssued,
            'qty_remaining' => max(0, $qtyApproved - $qtyIssued),
            'notes' => $d['notes'],
        ];

        if ($withStock) {
            $row['current_stock'] = (float) ($d['current_stock'] ?? 0);
        }

        return $row;
    }

    private function uploadPhoto(Request $request, int $issueId): ?string
    {
        $baseDir = __DIR__ . "/../../../public/uploads/stockout/{$issueId}";
        return PhotoStorage::save($request, 'photo', $baseDir, "uploads/stockout/{$issueId}", 'bukti');
    }

    private static function nullableString($v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }
}
