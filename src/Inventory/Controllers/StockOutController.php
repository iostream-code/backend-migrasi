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
 * getStockOutHistory.
 *
 * **Retur Produksi** (2026-08-24, `getStockOutReturn{Inbox,History,Detail}`/
 * `{approve,receive,reject}StockOutReturn`) -- port dari
 * `App\Services\Inventory\StockOut\ReturProduksiService` (Eloquent). Retur
 * DIBUAT di `backend-production` lewat `produksi-apk` (POST
 * /produksi/retur-material, endpoint itu TIDAK diport ke sini -- tabel
 * `prd_t_retur_produksi(_detail)` SHARED dgn DB yang sama, jadi baris yang
 * dibuat di sana langsung kebaca di sini). Modul ini murni sisi GUDANG:
 * approve (tanpa gerak stok) -> receive (SATU-SATUNYA titik stok naik,
 * lewat StockPosting::postIn(), reuse apa adanya) -> selesai; atau reject
 * (boleh dari SUBMITTED maupun APPROVED, TIDAK PERNAH ada gerak stok krn
 * memang belum pernah bergerak sebelum RECEIVED di alur ini -- beda dari
 * sisi Stock-In/Purchase yang butuh rollback stok kalau reject dari
 * APPROVED). GOOD/DAMAGED/EXPIRED tetap SAMA-SAMA menambah qty_on_hand,
 * beda cuma `transaction_type` ledger (`RECEIVE_RETUR_DAMAGED` vs
 * `RECEIVE_RETUR_PRODUKSI`) -- tidak ada gudang karantina terpisah,
 * dikutip apa adanya dari versi asli (keterbatasan yang sudah diketahui,
 * BUKAN bug port). Reversal `qty_issued` di `prd_t_req_material_detail`
 * (via `reverseQtyIssued()`, reuse `recalculateReqStatus()` yang sudah ada
 * di file ini) HANYA terjadi kalau `source_type=ISSUE` DAN
 * `reason IN (DAMAGED, WRONG_SPEC)` -- EXCESS/OTHER TIDAK direversal.
 *
 * **[PERBAIKAN KEAMANAN dari versi asli, sadar]** approve/receive/reject
 * AdminGudang-only (role dari JWT) -- versi Laravel asli endpoint2 ini
 * TANPA AUTH SAMA SEKALI (route top-level, tidak ada middleware apa pun).
 * Endpoint baca (inbox/history/detail) TIDAK digate role, sama seperti
 * endpoint baca lain di modul ini.
 *
 * **Skema**: `prd_t_retur_produksi` di `db_dump.sql` (snapshot lama) TIDAK
 * py kolom `rejected_at`/`rejected_by`/`rejected_reason` walau Eloquent
 * model & service asli jelas memakainya -- dicek `SHOW COLUMNS` live
 * (2026-08-24), memang belum ada di produksi. Ditambahkan via
 * `database/inventory/01_add_retur_produksi_reject_columns.sql` (file
 * skema PERTAMA modul Inventory, sudah dijalankan ke produksi).
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

    /**
     * POST /inventory/stock-out/get-stockout-return-inbox -- kerjaan yang
     * masih perlu ditindaklanjuti gudang (status SUBMITTED/APPROVED), urut
     * retur_date ASC (FIFO, sama persis versi asli).
     */
    public function getStockOutReturnInbox(Request $request, Response $response): Response
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

        $sql = "SELECT rp.*, d.name AS department_name
                FROM prd_t_retur_produksi rp
                LEFT JOIN shared_m_department d ON d.id = rp.department_id
                WHERE rp.warehouse_id = ? AND rp.deleted_at IS NULL
                  AND rp.status IN ('SUBMITTED','APPROVED')";
        $params = [$warehouseId];

        if ($statusFilter === 'submitted') {
            $sql .= ' AND rp.status = ?';
            $params[] = 'SUBMITTED';
        } elseif ($statusFilter === 'approved') {
            $sql .= ' AND rp.status = ?';
            $params[] = 'APPROVED';
        }
        if ($search !== null) {
            $sql .= ' AND (rp.retur_number LIKE ? OR rp.notes LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
        }
        if ($month && $year) {
            $sql .= ' AND MONTH(rp.retur_date) = ? AND YEAR(rp.retur_date) = ?';
            $params[] = $month;
            $params[] = $year;
        } elseif ($year) {
            $sql .= ' AND YEAR(rp.retur_date) = ?';
            $params[] = $year;
        }
        $sql .= ' ORDER BY rp.retur_date ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $this->apiSuccess($response, ['retur_list' => $this->formatReturList($pdo, $stmt->fetchAll())]);
    }

    /**
     * POST /inventory/stock-out/get-stockout-return-history -- semua status,
     * urut FIELD(status,...) lalu retur_date DESC (sama persis versi asli).
     * `source_type` dinormalisasi uppercase (FE lazimnya kirim lowercase
     * issue/manual, kolom DB ISSUE/MANUAL -- versi Laravel asli py bug
     * case-mismatch di sini, diperbaiki bukan direplikasi).
     */
    public function getStockOutReturnHistory(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }
        $search = self::nullableString($body['search'] ?? null);
        $month = is_numeric($body['filter_month'] ?? null) ? (int) $body['filter_month'] : null;
        $year = is_numeric($body['filter_year'] ?? null) ? (int) $body['filter_year'] : null;
        $sourceType = self::nullableString($body['source_type'] ?? null);
        $sourceType = $sourceType !== null ? strtoupper($sourceType) : null;

        $pdo = Database::connection();

        $sql = "SELECT rp.*, d.name AS department_name
                FROM prd_t_retur_produksi rp
                LEFT JOIN shared_m_department d ON d.id = rp.department_id
                WHERE rp.warehouse_id = ? AND rp.deleted_at IS NULL";
        $params = [$warehouseId];

        if ($sourceType !== null && in_array($sourceType, ['ISSUE', 'MANUAL'], true)) {
            $sql .= ' AND rp.source_type = ?';
            $params[] = $sourceType;
        }
        if ($search !== null) {
            $sql .= ' AND (rp.retur_number LIKE ? OR rp.notes LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
        }
        if ($month && $year) {
            $sql .= ' AND MONTH(rp.retur_date) = ? AND YEAR(rp.retur_date) = ?';
            $params[] = $month;
            $params[] = $year;
        } elseif ($year) {
            $sql .= ' AND YEAR(rp.retur_date) = ?';
            $params[] = $year;
        }
        $sql .= " ORDER BY FIELD(rp.status,'SUBMITTED','APPROVED','RECEIVED','CANCELLED'), rp.retur_date DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $this->apiSuccess($response, ['retur_list' => $this->formatReturList($pdo, $stmt->fetchAll())]);
    }

    public function getStockOutReturnDetail(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $returId = (int) ($body['retur_id'] ?? 0);
        if ($returId <= 0) {
            return $this->apiError($response, 'retur_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $retur = $this->findRetur($pdo, $returId);
        if (!$retur) {
            return $this->apiNotFound($response, 'Retur tidak ditemukan');
        }

        return $this->apiSuccess($response, [
            'retur' => $this->formatReturHeader($pdo, $retur),
            'items' => $this->fetchReturDetailItems($pdo, $returId),
        ]);
    }

    /**
     * POST /inventory/stock-out/stockout-return/{id}/approve -- AdminGudang-
     * only. Tanpa gerak stok, murni transisi status SUBMITTED -> APPROVED.
     */
    public function approveStockOutReturn(Request $request, Response $response, array $args): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh approve retur produksi.', 403);
        }

        $returId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $notes = self::nullableString($body['notes'] ?? null);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM prd_t_retur_produksi WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $returId]);
            $retur = $stmt->fetch();
            if (!$retur) {
                throw new RuntimeException('Retur tidak ditemukan');
            }
            if ($retur['status'] !== 'SUBMITTED') {
                throw new RuntimeException("Retur {$retur['retur_number']} tidak bisa di-approve (status saat ini: {$retur['status']}). Harus berstatus SUBMITTED.");
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE prd_t_retur_produksi SET status = 'APPROVED', approved_by = :uid, approved_at = :now, notes = :notes, updated_by = :uid2, updated_at = :now2 WHERE id = :id"
            )->execute([
                'uid' => $userId ?: null,
                'now' => $now,
                'notes' => self::appendNote($retur['notes'], $notes),
                'uid2' => $userId ?: null,
                'now2' => $now,
                'id' => $returId,
            ]);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['retur_id' => $returId, 'retur_number' => $retur['retur_number'], 'status' => 'APPROVED'],
                "Retur {$retur['retur_number']} berhasil di-approve"
            );
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * POST /inventory/stock-out/stockout-return/{id}/receive -- AdminGudang-
     * only. SATU-SATUNYA titik stok naik di alur ini. Double-lock (baca
     * detail dulu, lalu re-lock header & re-cek status APPROVED sebelum
     * posting) mereplikasi guard concurrency versi asli persis.
     */
    public function receiveStockOutReturn(Request $request, Response $response, array $args): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh menerima retur produksi.', 403);
        }

        $returId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $notes = self::nullableString($body['notes'] ?? null);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $retur = $this->findRetur($pdo, $returId);
            if (!$retur) {
                throw new RuntimeException('Retur tidak ditemukan');
            }
            if ($retur['status'] !== 'APPROVED') {
                throw new RuntimeException("Retur {$retur['retur_number']} tidak bisa diterima (status saat ini: {$retur['status']}). Harus berstatus APPROVED.");
            }
            $details = $this->fetchReturDetailItems($pdo, $returId, true);

            $lockStmt = $pdo->prepare('SELECT status FROM prd_t_retur_produksi WHERE id = :id FOR UPDATE');
            $lockStmt->execute(['id' => $returId]);
            if ($lockStmt->fetchColumn() !== 'APPROVED') {
                throw new RuntimeException("Retur {$retur['retur_number']} berubah status secara bersamaan. Coba lagi.");
            }

            foreach ($details as $detail) {
                $isDamaged = in_array($detail['condition_status_raw'], ['DAMAGED', 'EXPIRED'], true);
                StockPosting::postIn($pdo, [
                    'warehouse_id' => (int) $retur['warehouse_id'],
                    'material_id' => $detail['material_id_raw'],
                    'qty' => $detail['qty_raw'],
                    'unit_cost' => $detail['unit_cost_raw'],
                    'transaction_type' => $isDamaged ? 'RECEIVE_RETUR_DAMAGED' : 'RECEIVE_RETUR_PRODUKSI',
                    'reference_type' => 'prd_t_retur_produksi',
                    'reference_id' => $returId,
                    'reference_number' => $retur['retur_number'],
                    'remarks' => "Retur produksi {$retur['retur_number']}" . ($isDamaged ? " [kondisi: {$detail['condition_status_raw']}]" : ''),
                    'created_by' => $userId ?: null,
                ]);
            }

            if ($retur['source_type'] === 'ISSUE' && in_array($retur['reason'], ['DAMAGED', 'WRONG_SPEC'], true)) {
                $this->reverseQtyIssued($pdo, $details);
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE prd_t_retur_produksi SET status = 'RECEIVED', received_by = :uid, received_at = :now, notes = :notes, updated_by = :uid2, updated_at = :now2 WHERE id = :id"
            )->execute([
                'uid' => $userId ?: null,
                'now' => $now,
                'notes' => self::appendNote($retur['notes'], $notes),
                'uid2' => $userId ?: null,
                'now2' => $now,
                'id' => $returId,
            ]);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['retur_id' => $returId, 'retur_number' => $retur['retur_number'], 'status' => 'RECEIVED'],
                "Retur {$retur['retur_number']} berhasil diterima, stok sudah diperbarui"
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * POST /inventory/stock-out/stockout-return/{id}/reject -- AdminGudang-
     * only. Boleh dari SUBMITTED maupun APPROVED (stok belum pernah bergerak
     * di kedua status itu, jadi tidak perlu rollback -- beda dari sisi
     * Stock-In/Purchase). `reason` wajib.
     */
    public function rejectStockOutReturn(Request $request, Response $response, array $args): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh reject retur produksi.', 403);
        }

        $returId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $userId = (int) ($request->getAttribute('user_id') ?? 0);
        $reason = self::nullableString($body['reason'] ?? null);
        if ($reason === null) {
            return $this->apiError($response, 'Alasan reject wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM prd_t_retur_produksi WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $returId]);
            $retur = $stmt->fetch();
            if (!$retur) {
                throw new RuntimeException('Retur tidak ditemukan');
            }
            if (!in_array($retur['status'], ['SUBMITTED', 'APPROVED'], true)) {
                throw new RuntimeException("Retur {$retur['retur_number']} tidak bisa di-reject (status saat ini: {$retur['status']}). Hanya SUBMITTED atau APPROVED yang bisa di-reject.");
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                "UPDATE prd_t_retur_produksi SET status = 'CANCELLED', rejected_by = :uid, rejected_at = :now, rejected_reason = :reason, updated_by = :uid2, updated_at = :now2 WHERE id = :id"
            )->execute([
                'uid' => $userId ?: null,
                'now' => $now,
                'reason' => $reason,
                'uid2' => $userId ?: null,
                'now2' => $now,
                'id' => $returId,
            ]);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['retur_id' => $returId, 'retur_number' => $retur['retur_number'], 'status' => 'CANCELLED'],
                "Retur {$retur['retur_number']} berhasil di-reject"
            );
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 400);
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

    // ─── Retur Produksi helpers ─────────────────────────────────────

    /**
     * Port dari ReturProduksiService::reverseQtyIssued() -- dipanggil HANYA
     * dari receiveStockOutReturn() saat source_type=ISSUE & reason
     * DAMAGED/WRONG_SPEC (lihat guard di sana). Map issue_detail_id ->
     * req_material_detail_id via prd_t_material_issue_detail, agregasi qty
     * retur per baris request, lock FOR UPDATE, floor di 0, lalu reuse
     * recalculateReqStatus() yang sudah ada di file ini per req_material_id
     * yang terpengaruh.
     */
    private function reverseQtyIssued(PDO $pdo, array $details): void
    {
        $issueDetailIds = array_values(array_unique(array_filter(array_column($details, 'issue_detail_id_raw'))));
        if (empty($issueDetailIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($issueDetailIds), '?'));
        $stmt = $pdo->prepare("SELECT id, req_material_detail_id FROM prd_t_material_issue_detail WHERE id IN ({$placeholders})");
        $stmt->execute($issueDetailIds);
        $issueToReqDetail = [];
        foreach ($stmt->fetchAll() as $row) {
            $issueToReqDetail[(int) $row['id']] = (int) $row['req_material_detail_id'];
        }

        $qtyByReqDetail = [];
        foreach ($details as $d) {
            $issueDetailId = $d['issue_detail_id_raw'] ?? null;
            if (!$issueDetailId || !isset($issueToReqDetail[$issueDetailId])) {
                continue;
            }
            $reqDetailId = $issueToReqDetail[$issueDetailId];
            $qtyByReqDetail[$reqDetailId] = ($qtyByReqDetail[$reqDetailId] ?? 0) + (float) $d['qty_raw'];
        }

        $affectedReqIds = [];
        foreach ($qtyByReqDetail as $reqDetailId => $qtyRetur) {
            $lockStmt = $pdo->prepare('SELECT req_material_id, qty_issued FROM prd_t_req_material_detail WHERE id = :id FOR UPDATE');
            $lockStmt->execute(['id' => $reqDetailId]);
            $row = $lockStmt->fetch();
            if (!$row) {
                continue;
            }
            $pdo->prepare('UPDATE prd_t_req_material_detail SET qty_issued = :qty WHERE id = :id')
                ->execute(['qty' => max(0, (float) $row['qty_issued'] - $qtyRetur), 'id' => $reqDetailId]);
            $affectedReqIds[(int) $row['req_material_id']] = true;
        }

        foreach (array_keys($affectedReqIds) as $reqId) {
            $stmt2 = $pdo->prepare('SELECT status FROM prd_t_req_material WHERE id = :id');
            $stmt2->execute(['id' => $reqId]);
            $this->recalculateReqStatus($pdo, $reqId, (string) $stmt2->fetchColumn());
        }
    }

    private static function appendNote(?string $existing, ?string $addition): ?string
    {
        if ($addition === null) {
            return $existing;
        }
        $note = 'Catatan gudang: ' . $addition;
        return $existing ? "{$existing} | {$note}" : $note;
    }

    private function findRetur(PDO $pdo, int $returId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM prd_t_retur_produksi WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $returId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array> daftar retur ter-format, termasuk items_summary & source_ref per baris. */
    private function formatReturList(PDO $pdo, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $summaryByRetur = $this->fetchItemsSummary($pdo, array_column($rows, 'id'));
        $sourceRefByRetur = $this->fetchSourceRefs($pdo, $rows);
        $userNames = $this->fetchUserNames($pdo, $rows);

        return array_map(function ($r) use ($summaryByRetur, $sourceRefByRetur, $userNames) {
            $id = (int) $r['id'];
            $items = $summaryByRetur[$id] ?? [];
            return [
                'id' => $id,
                'retur_number' => $r['retur_number'],
                'retur_date' => $r['retur_date'] ? date('d-m-Y', strtotime($r['retur_date'])) : '-',
                'department_name' => $r['department_name'] ?? '-',
                'source_type' => $r['source_type'],
                'source_ref' => $sourceRefByRetur[$id] ?? null,
                'reason' => $r['reason'],
                'status' => $r['status'],
                'submitted_by' => $userNames[(int) ($r['created_by'] ?? 0)] ?? null,
                'total_items' => count($items),
                'items_summary' => $items,
            ];
        }, $rows);
    }

    private function fetchItemsSummary(PDO $pdo, array $returIds): array
    {
        $returIds = array_values(array_unique(array_map('intval', $returIds)));
        if (empty($returIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($returIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT rpd.retur_produksi_id, m.name AS material_name, m.code AS material_code,
                    rpd.qty, u.code AS unit_code, rpd.condition_status
             FROM prd_t_retur_produksi_detail rpd
             LEFT JOIN wh_m_material m ON m.id = rpd.material_id
             LEFT JOIN shared_m_unit u ON u.id = rpd.unit_id
             WHERE rpd.retur_produksi_id IN ({$placeholders})"
        );
        $stmt->execute($returIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['retur_produksi_id']][] = [
                'material_name' => $r['material_name'] ?? '-',
                'material_code' => $r['material_code'] ?? '-',
                'qty' => (float) $r['qty'],
                'unit' => $r['unit_code'] ?? '-',
                'condition_status' => $r['condition_status'],
            ];
        }
        return $out;
    }

    /** @return array<int,string> retur_id => nomor dokumen sumber (issue_number / adjustment_number). */
    private function fetchSourceRefs(PDO $pdo, array $rows): array
    {
        $out = [];
        $issueIds = array_values(array_unique(array_filter(array_column($rows, 'issue_id'))));
        if (!empty($issueIds)) {
            $placeholders = implode(',', array_fill(0, count($issueIds), '?'));
            $stmt = $pdo->prepare("SELECT id, issue_number FROM prd_t_material_issue WHERE id IN ({$placeholders})");
            $stmt->execute($issueIds);
            $byIssue = [];
            foreach ($stmt->fetchAll() as $r) {
                $byIssue[(int) $r['id']] = $r['issue_number'];
            }
            foreach ($rows as $row) {
                if (!empty($row['issue_id']) && isset($byIssue[(int) $row['issue_id']])) {
                    $out[(int) $row['id']] = $byIssue[(int) $row['issue_id']];
                }
            }
        }
        $adjIds = array_values(array_unique(array_filter(array_column($rows, 'source_adjustment_id'))));
        if (!empty($adjIds)) {
            $placeholders = implode(',', array_fill(0, count($adjIds), '?'));
            $stmt = $pdo->prepare("SELECT id, adjustment_number FROM wh_t_stock_adjustment WHERE id IN ({$placeholders})");
            $stmt->execute($adjIds);
            $byAdj = [];
            foreach ($stmt->fetchAll() as $r) {
                $byAdj[(int) $r['id']] = $r['adjustment_number'];
            }
            foreach ($rows as $row) {
                if (!empty($row['source_adjustment_id']) && isset($byAdj[(int) $row['source_adjustment_id']])) {
                    $out[(int) $row['id']] = $byAdj[(int) $row['source_adjustment_id']];
                }
            }
        }
        return $out;
    }

    /** @return array<int,string> user_id => nama_lengkap, dikumpulkan dari created_by/approved_by/received_by/rejected_by baris yang diberikan. */
    private function fetchUserNames(PDO $pdo, array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            foreach (['created_by', 'approved_by', 'received_by', 'rejected_by'] as $col) {
                if (!empty($r[$col])) {
                    $ids[] = (int) $r[$col];
                }
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT user_id, nama_lengkap FROM shared_m_users WHERE user_id IN ({$placeholders})");
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['user_id']] = $r['nama_lengkap'];
        }
        return $out;
    }

    private function formatReturHeader(PDO $pdo, array $r): array
    {
        $id = (int) $r['id'];
        $sourceRef = $this->fetchSourceRefs($pdo, [$r]);
        $userNames = $this->fetchUserNames($pdo, [$r]);

        $deptStmt = $pdo->prepare('SELECT name FROM shared_m_department WHERE id = :id');
        $deptStmt->execute(['id' => $r['department_id']]);
        $deptName = $deptStmt->fetchColumn();

        return [
            'id' => $id,
            'retur_number' => $r['retur_number'],
            'retur_date' => $r['retur_date'] ? date('d-m-Y', strtotime($r['retur_date'])) : '-',
            'warehouse_id' => (int) $r['warehouse_id'],
            'department_id' => (int) $r['department_id'],
            'department_name' => $deptName ?: '-',
            'source_type' => $r['source_type'],
            'source_ref' => $sourceRef[$id] ?? null,
            'reason' => $r['reason'],
            'status' => $r['status'],
            'notes' => $r['notes'],
            'submitted_by' => $userNames[(int) ($r['created_by'] ?? 0)] ?? null,
            'approved_by' => $userNames[(int) ($r['approved_by'] ?? 0)] ?? null,
            'approved_at' => $r['approved_at'] ? date('d-m-Y H:i', strtotime($r['approved_at'])) : null,
            'received_by' => $userNames[(int) ($r['received_by'] ?? 0)] ?? null,
            'received_at' => $r['received_at'] ? date('d-m-Y H:i', strtotime($r['received_at'])) : null,
            'rejected_by' => $userNames[(int) ($r['rejected_by'] ?? 0)] ?? null,
            'rejected_at' => $r['rejected_at'] ? date('d-m-Y H:i', strtotime($r['rejected_at'])) : null,
            'rejected_reason' => $r['rejected_reason'],
        ];
    }

    /**
     * @return array daftar item retur ter-format. $withRaw=true (dipakai
     * receiveStockOutReturn()) menyertakan field `*_raw` yang dibutuhkan
     * posting stok/reversal -- TIDAK dikirim ke response FE biasa.
     */
    private function fetchReturDetailItems(PDO $pdo, int $returId, bool $withRaw = false): array
    {
        $stmt = $pdo->prepare(
            'SELECT rpd.*, m.code AS material_code, m.name AS material_name, u.code AS unit_code
             FROM prd_t_retur_produksi_detail rpd
             LEFT JOIN wh_m_material m ON m.id = rpd.material_id
             LEFT JOIN shared_m_unit u ON u.id = rpd.unit_id
             WHERE rpd.retur_produksi_id = :id'
        );
        $stmt->execute(['id' => $returId]);

        return array_map(function ($d) use ($withRaw) {
            $row = [
                'detail_id' => (int) $d['id'],
                'material_id' => (int) $d['material_id'],
                'material_code' => $d['material_code'] ?? '-',
                'material_name' => $d['material_name'] ?? '-',
                'unit' => $d['unit_code'] ?? '-',
                'qty' => (float) $d['qty'],
                'unit_cost' => (float) $d['unit_cost'],
                'condition_status' => $d['condition_status'],
                'notes' => $d['notes'],
            ];
            if ($withRaw) {
                $row['material_id_raw'] = (int) $d['material_id'];
                $row['qty_raw'] = (float) $d['qty'];
                $row['unit_cost_raw'] = (float) $d['unit_cost'];
                $row['condition_status_raw'] = $d['condition_status'];
                $row['issue_detail_id_raw'] = $d['issue_detail_id'] ? (int) $d['issue_detail_id'] : null;
            }
            return $row;
        }, $stmt->fetchAll());
    }
}
