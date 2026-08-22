<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Database;
use App\Support\DocumentNumber;
use App\Inventory\Support\ApiEnvelope;
use App\Inventory\Support\StockPosting;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Port dari backend-production App\Http\Controllers\API\Inventory\OpnameController
 * (+ OpnameService + StockOpnameRepository + StockAdjustmentRepository, Eloquent)
 * ke Slim/PDO polos. Field request/response dikutip apa adanya dari sana
 * (qty_actual bukan qty_physical, session_number, dst) supaya kompatibel
 * kalau inventory-apk suatu saat di-pointing kesini.
 *
 * TIDAK diport di pass ini: export-pdf/export-excel (OpnameExportService) --
 * fitur laporan terpisah, tidak ada yang bergantung padanya.
 *
 * [PERBAIKAN KEAMANAN dari versi asli] role ('AdminGudang'/'StaffGudang') dan
 * user_id di sini SELALU dari JWT (`$request->getAttribute('role'/'user_id')`),
 * TIDAK PERNAH dari body request. Versi Laravel asli (ApproveOpnameSessionRequest
 * dkk) mempercayai `user_position` dari BODY untuk keputusan authorize() --
 * comment di source aslinya sendiri menandai ini sbg lubang keamanan yang
 * belum diperbaiki ("client tidak bisa impersonate role" -- justru BISA,
 * krn authorize() percaya body). Di sini TIDAK direplikasi; role selalu dari
 * token yang sudah diverifikasi AuthMiddleware, client tidak bisa klaim
 * role sendiri.
 */
class OpnameController
{
    use ApiEnvelope;

    private const EDITABLE = ['DRAFT', 'IN_PROGRESS'];

    // ─── READ ─────────────────────────────────────────────────────

    public function getSessions(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }
        $search = self::nullableString($body['search'] ?? null);
        $month = is_numeric($body['filter_month'] ?? null) ? (int) $body['filter_month'] : null;
        $year = is_numeric($body['filter_year'] ?? null) ? (int) $body['filter_year'] : null;

        $role = (string) $request->getAttribute('role');
        $userId = (int) $request->getAttribute('user_id');
        $scopeUserId = ($role === 'StaffGudang') ? $userId : null;

        $pdo = Database::connection();

        $sql = 'SELECT o.*, u1.username AS creator_username, u2.username AS approver_username,
                    (SELECT COUNT(*) FROM wh_t_stock_opname_detail d WHERE d.stock_opname_id = o.id) AS item_count
                FROM wh_t_stock_opname o
                LEFT JOIN shared_m_users u1 ON u1.user_id = o.created_by
                LEFT JOIN shared_m_users u2 ON u2.user_id = o.approved_by
                WHERE o.warehouse_id = :wh AND o.deleted_at IS NULL
                  AND o.status NOT IN (\'DRAFT\', \'IN_PROGRESS\')';
        $params = ['wh' => $warehouseId];

        if ($scopeUserId) {
            $sql .= ' AND o.created_by = :uid';
            $params['uid'] = $scopeUserId;
        }
        if ($search !== null) {
            $sql .= ' AND (o.opname_number LIKE :s1 OR o.catatan LIKE :s2 OR u1.username LIKE :s3)';
            $like = "%{$search}%";
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
        }
        if ($month && $year) {
            $sql .= ' AND MONTH(o.opname_date) = :m AND YEAR(o.opname_date) = :y';
            $params['m'] = $month;
            $params['y'] = $year;
        } elseif ($month) {
            $sql .= ' AND MONTH(o.opname_date) = :m';
            $params['m'] = $month;
        } elseif ($year) {
            $sql .= ' AND YEAR(o.opname_date) = :y';
            $params['y'] = $year;
        }
        $sql .= ' ORDER BY o.opname_date DESC, o.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sessions = array_map([self::class, 'formatSessionRow'], $stmt->fetchAll());

        $activeDraft = $this->findActiveSession($pdo, $warehouseId, $scopeUserId);
        $activeSession = null;
        $activeItems = [];
        if ($activeDraft) {
            $activeSession = [
                'id' => (int) $activeDraft['id'],
                'session_number' => $activeDraft['opname_number'],
                'opname_date' => date('d M Y', strtotime($activeDraft['opname_date'])),
                'notes' => $activeDraft['catatan'],
            ];
            $activeItems = array_map([self::class, 'formatDetailRow'], $this->getDetailsForOpname($pdo, (int) $activeDraft['id'], 'd.id DESC'));
        }

        return $this->apiSuccess($response, [
            'sessions' => $sessions,
            'active_session' => $activeSession,
            'active_items' => $activeItems,
        ]);
    }

    public function getSessionDetail(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        if ($opnameId <= 0) {
            return $this->apiError($response, 'session_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT o.*, u1.username AS creator_username, u2.username AS approver_username
             FROM wh_t_stock_opname o
             LEFT JOIN shared_m_users u1 ON u1.user_id = o.created_by
             LEFT JOIN shared_m_users u2 ON u2.user_id = o.approved_by
             WHERE o.id = :id'
        );
        $stmt->execute(['id' => $opnameId]);
        $opn = $stmt->fetch();
        if (!$opn) {
            return $this->apiNotFound($response, 'Sesi opname tidak ditemukan');
        }

        $items = array_map([self::class, 'formatDetailRow'], $this->getDetailsForOpname($pdo, $opnameId, 'm.name ASC'));

        return $this->apiSuccess($response, [
            'session' => [
                'id' => (int) $opn['id'],
                'session_number' => $opn['opname_number'],
                'opname_date' => date('d M Y', strtotime($opn['opname_date'])),
                'conducted_by' => $opn['created_by'] !== null ? (int) $opn['created_by'] : null,
                'conducted_by_name' => $opn['creator_username'] ?? '-',
                'approved_by_name' => $opn['approver_username'],
                'approved_at' => $opn['approved_at'] ? date('d M Y H:i', strtotime($opn['approved_at'])) : null,
                'notes' => $opn['catatan'],
                'status' => self::normalizeStatus($opn['status']),
                'raw_status' => $opn['status'],
            ],
            'items' => $items,
        ]);
    }

    public function lookupMaterial(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $query = trim((string) ($body['query'] ?? ''));
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($query === '' || $warehouseId <= 0) {
            return $this->apiError($response, 'query dan warehouse_id wajib diisi.', 422);
        }

        $role = (string) $request->getAttribute('role');

        $pdo = Database::connection();
        // :q1/:q2/:q3 (bukan :q x3) -- PDO_MySQL dgn real prepared statement
        // (ATTR_EMULATE_PREPARES=false) menolak named placeholder yg sama
        // dipakai >1 kali dlm satu query, lihat catatan yg sama di MaterialController.
        $stmt = $pdo->prepare(
            'SELECT m.id, m.code, m.name, m.unit_id, u.code AS unit_code
             FROM wh_m_material m
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             WHERE m.is_active = 1 AND m.deleted_at IS NULL AND (m.id = :q1 OR m.code = :q2 OR m.barcode = :q3)
             LIMIT 1'
        );
        $stmt->execute(['q1' => $query, 'q2' => $query, 'q3' => $query]);
        $material = $stmt->fetch();
        if (!$material) {
            return $this->apiNotFound($response, 'Material tidak ditemukan');
        }

        $balStmt = $pdo->prepare('SELECT qty_on_hand FROM wh_t_stock_balance WHERE warehouse_id = :w AND material_id = :m');
        $balStmt->execute(['w' => $warehouseId, 'm' => $material['id']]);
        $qtyOnHand = (float) ($balStmt->fetchColumn() ?: 0);

        $showStock = ($role !== 'StaffGudang');

        return $this->apiSuccess($response, [
            'material' => [
                'id' => (int) $material['id'],
                'code' => $material['code'],
                'name' => $material['name'],
                'unit' => $material['unit_code'] ?? '-',
                'current_stock' => $showStock ? $qtyOnHand : null,
                'show_stock' => $showStock,
            ],
        ]);
    }

    // ─── WRITE ────────────────────────────────────────────────────

    public function createSession(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $opnameDate = $body['opname_date'] ?? null;
        if ($warehouseId <= 0 || !is_string($opnameDate) || $opnameDate === '') {
            return $this->apiError($response, 'warehouse_id dan opname_date wajib diisi.', 422);
        }

        $role = (string) $request->getAttribute('role');
        $userId = (int) $request->getAttribute('user_id');
        $scopeUserId = ($role === 'StaffGudang') ? $userId : null;

        $pdo = Database::connection();

        if ($this->findActiveSession($pdo, $warehouseId, $scopeUserId)) {
            return $this->apiError($response, 'Masih ada sesi draft aktif', 422);
        }

        $pdo->beginTransaction();
        try {
            $docNumber = DocumentNumber::next($pdo, 'OPN');
            $now = date('Y-m-d H:i:s');

            $ins = $pdo->prepare(
                'INSERT INTO wh_t_stock_opname
                    (opname_number, opname_date, warehouse_id, status, total_items, total_variance_in, total_variance_out, catatan, created_by, created_at, updated_at)
                 VALUES (:num, :date, :wh, \'DRAFT\', 0, 0, 0, :notes, :uid, :now1, :now2)'
            );
            $ins->execute([
                'num' => $docNumber, 'date' => $opnameDate, 'wh' => $warehouseId,
                'notes' => $body['notes'] ?? null, 'uid' => $userId, 'now1' => $now, 'now2' => $now,
            ]);
            $opnameId = (int) $pdo->lastInsertId();

            $pdo->commit();
            return $this->apiSuccess(
                $response,
                ['opname_id' => $opnameId, 'session_number' => $docNumber],
                "Sesi opname berhasil dibuat: {$docNumber}",
                201
            );
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function saveScan(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        $materialId = (int) ($body['material_id'] ?? 0);
        $qtyPhysical = is_numeric($body['qty_actual'] ?? null) ? (float) $body['qty_actual'] : null;
        $userId = (int) $request->getAttribute('user_id');

        if ($opnameId <= 0 || $materialId <= 0 || $qtyPhysical === null) {
            return $this->apiError($response, 'session_id, material_id, dan qty_actual wajib diisi.', 422);
        }
        if ($qtyPhysical < 0) {
            return $this->apiError($response, 'Qty fisik tidak boleh negatif', 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM wh_t_stock_opname WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $opnameId]);
            $opn = $stmt->fetch();
            if (!$opn) {
                throw new RuntimeException('Sesi opname tidak ditemukan');
            }
            if (!in_array($opn['status'], self::EDITABLE, true)) {
                throw new RuntimeException('Sesi sudah tidak bisa diubah (status: ' . $opn['status'] . ')');
            }

            $balStmt = $pdo->prepare('SELECT qty_on_hand, avg_unit_cost FROM wh_t_stock_balance WHERE warehouse_id = :w AND material_id = :m');
            $balStmt->execute(['w' => $opn['warehouse_id'], 'm' => $materialId]);
            $bal = $balStmt->fetch();
            $qtySystem = $bal ? (float) $bal['qty_on_hand'] : 0.0;
            $unitCost = $bal ? (float) $bal['avg_unit_cost'] : 0.0;

            $detStmt = $pdo->prepare('SELECT * FROM wh_t_stock_opname_detail WHERE stock_opname_id = :o AND material_id = :m');
            $detStmt->execute(['o' => $opnameId, 'm' => $materialId]);
            $detail = $detStmt->fetch();

            $now = date('Y-m-d H:i:s');
            if ($detail) {
                // qty_system TIDAK di-refresh -- tetap snapshot saat pertama kali scan.
                $variance = $qtyPhysical - (float) $detail['qty_system'];
                $upd = $pdo->prepare(
                    'UPDATE wh_t_stock_opname_detail SET qty_physical = :phys, qty_variance = :var, counted_at = :now, counted_by = :uid WHERE id = :id'
                );
                $upd->execute(['phys' => $qtyPhysical, 'var' => $variance, 'now' => $now, 'uid' => $userId, 'id' => $detail['id']]);
            } else {
                $matStmt = $pdo->prepare('SELECT unit_id FROM wh_m_material WHERE id = :id');
                $matStmt->execute(['id' => $materialId]);
                $unitId = $matStmt->fetchColumn();
                if ($unitId === false) {
                    throw new RuntimeException('Material tidak ditemukan');
                }

                $variance = $qtyPhysical - $qtySystem;
                $ins = $pdo->prepare(
                    'INSERT INTO wh_t_stock_opname_detail
                        (stock_opname_id, material_id, unit_id, qty_system, qty_physical, qty_variance, unit_cost, counted_at, counted_by)
                     VALUES (:o, :m, :u, :sys, :phys, :var, :cost, :now, :uid)'
                );
                $ins->execute([
                    'o' => $opnameId, 'm' => $materialId, 'u' => $unitId, 'sys' => $qtySystem,
                    'phys' => $qtyPhysical, 'var' => $variance, 'cost' => $unitCost, 'now' => $now, 'uid' => $userId,
                ]);
            }

            if ($opn['status'] === 'DRAFT') {
                $pdo->prepare('UPDATE wh_t_stock_opname SET status = \'IN_PROGRESS\' WHERE id = :id')->execute(['id' => $opnameId]);
            }

            $pdo->commit();
            return $this->apiSuccess($response, ['saved' => true], 'Data tersimpan');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function submitSession(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        if ($opnameId <= 0) {
            return $this->apiError($response, 'session_id wajib diisi.', 422);
        }
        $userId = (int) $request->getAttribute('user_id');
        $role = (string) $request->getAttribute('role');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM wh_t_stock_opname WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $opnameId]);
            $opn = $stmt->fetch();
            if (!$opn) {
                throw new RuntimeException('Sesi tidak ditemukan');
            }
            if (!in_array($opn['status'], self::EDITABLE, true)) {
                throw new RuntimeException('Sesi sudah di-submit sebelumnya');
            }

            $details = $this->getDetailsForOpname($pdo, $opnameId, 'd.id ASC');
            if (empty($details)) {
                throw new RuntimeException('Sesi kosong, tidak ada material yang di-scan');
            }

            $this->applyTotals($pdo, $opnameId, $details);

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE wh_t_stock_opname SET status = \'SUBMITTED\', submitted_at = :now, submitted_by = :uid WHERE id = :id'
            )->execute(['now' => $now, 'uid' => $userId, 'id' => $opnameId]);

            $autoApproved = false;
            if ($role === 'AdminGudang') {
                $this->executeApproval($pdo, $opnameId, (int) $opn['warehouse_id'], $userId, 'Auto-approved oleh AdminGudang');
                $autoApproved = true;
            }

            $pdo->commit();

            $status = self::normalizeStatus($this->currentStatus($pdo, $opnameId));
            $msg = $autoApproved
                ? 'Opname langsung di-approve. Stok telah di-update.'
                : 'Sesi berhasil dikirim untuk approval Admin Gudang.';

            return $this->apiSuccess($response, [
                'opname_id' => $opnameId,
                'session_number' => $opn['opname_number'],
                'status' => $status,
                'auto_approved' => $autoApproved,
            ], $msg);
        } catch (InvalidArgumentException | RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function approveSession(Request $request, Response $response): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh approve sesi opname.', 403);
        }

        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        if ($opnameId <= 0) {
            return $this->apiError($response, 'session_id wajib diisi.', 422);
        }
        $userId = (int) $request->getAttribute('user_id');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM wh_t_stock_opname WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $opnameId]);
            $opn = $stmt->fetch();
            if (!$opn) {
                throw new RuntimeException('Sesi tidak ditemukan');
            }
            if ($opn['status'] !== 'SUBMITTED') {
                throw new RuntimeException('Sesi bukan status SUBMITTED (status sekarang: ' . $opn['status'] . ')');
            }

            $this->executeApproval($pdo, $opnameId, (int) $opn['warehouse_id'], $userId, 'Diapprove oleh AdminGudang');
            $pdo->commit();

            return $this->apiSuccess($response, [
                'opname_id' => $opnameId,
                'session_number' => $opn['opname_number'],
                'status' => self::normalizeStatus($this->currentStatus($pdo, $opnameId)),
            ], 'Opname di-approve. Stok telah di-update.');
        } catch (InvalidArgumentException | RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function rejectSession(Request $request, Response $response): Response
    {
        $role = (string) $request->getAttribute('role');
        if ($role !== 'AdminGudang') {
            return $this->apiError($response, 'Hanya AdminGudang yang boleh reject sesi opname.', 403);
        }

        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        if ($opnameId <= 0) {
            return $this->apiError($response, 'session_id wajib diisi.', 422);
        }
        $userId = (int) $request->getAttribute('user_id');
        $reason = self::nullableString($body['reason'] ?? null);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM wh_t_stock_opname WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $opnameId]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('Sesi tidak ditemukan');
            }
            if ($status !== 'SUBMITTED') {
                throw new RuntimeException('Hanya sesi SUBMITTED yang bisa di-reject');
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare(
                'UPDATE wh_t_stock_opname SET status = \'REJECTED\', rejected_at = :now, rejected_by = :uid, rejected_reason = :reason WHERE id = :id'
            )->execute(['now' => $now, 'uid' => $userId, 'reason' => $reason, 'id' => $opnameId]);

            $pdo->commit();
            return $this->apiSuccess($response, ['opname_id' => $opnameId, 'status' => 'rejected'], 'Opname di-reject.');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteSession(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $opnameId = (int) ($body['session_id'] ?? 0);
        if ($opnameId <= 0) {
            return $this->apiError($response, 'session_id wajib diisi.', 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM wh_t_stock_opname WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $opnameId]);
            $status = $stmt->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('Sesi tidak ditemukan');
            }
            if (!in_array($status, self::EDITABLE, true)) {
                throw new RuntimeException('Hanya sesi DRAFT/IN_PROGRESS yang bisa dibatalkan');
            }

            $now = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE wh_t_stock_opname SET status = \'CANCELLED\', deleted_at = :now WHERE id = :id')
                ->execute(['now' => $now, 'id' => $opnameId]);

            $pdo->commit();
            return $this->apiSuccess($response, ['deleted' => true], 'Sesi berhasil dibatalkan');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─── Private — Approval core (port dari executeApproval/createAdjustmentForOpname) ──

    private function executeApproval(PDO $pdo, int $opnameId, int $warehouseId, int $userId, string $noteLabel): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE wh_t_stock_opname SET status = \'APPROVED\', approved_at = :now, approved_by = :uid WHERE id = :id')
            ->execute(['now' => $now, 'uid' => $userId, 'id' => $opnameId]);

        $stmt = $pdo->prepare('SELECT * FROM wh_t_stock_opname_detail WHERE stock_opname_id = :id');
        $stmt->execute(['id' => $opnameId]);
        $details = $stmt->fetchAll();

        $opnStmt = $pdo->prepare('SELECT opname_number FROM wh_t_stock_opname WHERE id = :id');
        $opnStmt->execute(['id' => $opnameId]);
        $opnameNumber = $opnStmt->fetchColumn();

        $surplus = array_filter($details, fn ($d) => (float) $d['qty_variance'] > 0.0001);
        $shortage = array_filter($details, fn ($d) => (float) $d['qty_variance'] < -0.0001);

        $primaryAdjId = null;
        if (!empty($surplus)) {
            $primaryAdjId = $this->createAdjustmentForOpname($pdo, $opnameId, $opnameNumber, $warehouseId, 'IN', $surplus, $userId, $noteLabel);
        }
        if (!empty($shortage)) {
            $adjOut = $this->createAdjustmentForOpname($pdo, $opnameId, $opnameNumber, $warehouseId, 'OUT', $shortage, $userId, $noteLabel);
            $primaryAdjId = $primaryAdjId ?? $adjOut;
        }

        $pdo->prepare(
            'UPDATE wh_t_stock_opname SET status = \'ADJUSTED\', adjustment_id = :adj, adjusted_at = :now WHERE id = :id'
        )->execute(['adj' => $primaryAdjId, 'now' => $now, 'id' => $opnameId]);
    }

    /** @param array<int, array<string,mixed>> $items */
    private function createAdjustmentForOpname(PDO $pdo, int $opnameId, string $opnameNumber, int $warehouseId, string $direction, array $items, int $userId, string $noteLabel): int
    {
        $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(adjustment_number, 5) AS UNSIGNED)) AS max_num FROM wh_t_stock_adjustment WHERE adjustment_number LIKE 'ADJ-%'")->fetch();
        $maxNum = (int) ($row['max_num'] ?? 0);
        if ($maxNum > 0) {
            DocumentNumber::syncToAtLeast($pdo, 'ADJ', $maxNum);
        }
        $adjNumber = DocumentNumber::next($pdo, 'ADJ');

        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO wh_t_stock_adjustment
                (adjustment_number, adjustment_date, warehouse_id, adjustment_type, source_type, source_opname_id,
                 reason, status, notes, created_by, created_at, updated_at)
             VALUES (:num, :now1, :wh, :type, \'OPNAME\', :opn, \'OPNAME_SELISIH\', \'DRAFT\', :notes, :uid, :now2, :now3)'
        );
        $ins->execute([
            'num' => $adjNumber, 'now1' => $now, 'wh' => $warehouseId, 'type' => $direction, 'opn' => $opnameId,
            'notes' => "Auto dari opname {$opnameNumber} — {$noteLabel}", 'uid' => $userId, 'now2' => $now, 'now3' => $now,
        ]);
        $adjId = (int) $pdo->lastInsertId();

        $mutationType = $direction === 'IN' ? 'OPNAME_IN' : 'OPNAME_OUT';

        foreach ($items as $d) {
            $qtyAbs = abs((float) $d['qty_variance']);
            $unitCost = (float) $d['unit_cost'];

            $detIns = $pdo->prepare(
                'INSERT INTO wh_t_stock_adjustment_detail (stock_adjustment_id, material_id, qty, unit_id, unit_cost, notes)
                 VALUES (:adj, :mat, :qty, :unit, :cost, :notes)'
            );
            $detIns->execute([
                'adj' => $adjId, 'mat' => $d['material_id'], 'qty' => $qtyAbs, 'unit' => $d['unit_id'],
                'cost' => $unitCost, 'notes' => "Opname {$opnameNumber} — selisih fisik",
            ]);

            $postingPayload = [
                'warehouse_id' => $warehouseId,
                'material_id' => $d['material_id'],
                'qty' => $qtyAbs,
                'transaction_type' => $mutationType,
                'reference_type' => 'wh_t_stock_adjustment',
                'reference_id' => $adjId,
                'reference_number' => $adjNumber,
                'remarks' => "Opname {$opnameNumber}",
                'created_by' => $userId,
            ];

            if ($direction === 'IN') {
                // Surplus -- masuk stok. unit_cost 0 supaya postIn pakai avg existing
                // (adjustment, bukan pembelian baru) -- sama pola ManualStockInService.
                $postingPayload['unit_cost'] = 0;
                StockPosting::postIn($pdo, $postingPayload);
            } else {
                StockPosting::postOut($pdo, $postingPayload);
            }
        }

        $pdo->prepare('UPDATE wh_t_stock_adjustment SET status = \'POSTED\', posted_at = :now, posted_by = :uid WHERE id = :id')
            ->execute(['now' => $now, 'uid' => $userId, 'id' => $adjId]);

        return $adjId;
    }

    private function applyTotals(PDO $pdo, int $opnameId, array $details): void
    {
        $totalIn = 0.0;
        $totalOut = 0.0;
        foreach ($details as $d) {
            $v = (float) $d['qty_variance'];
            if ($v > 0) {
                $totalIn += $v;
            } elseif ($v < 0) {
                $totalOut += abs($v);
            }
        }

        $pdo->prepare(
            'UPDATE wh_t_stock_opname SET total_items = :n, total_variance_in = :vin, total_variance_out = :vout WHERE id = :id'
        )->execute(['n' => count($details), 'vin' => $totalIn, 'vout' => $totalOut, 'id' => $opnameId]);
    }

    // ─── Private — Queries & formatting ────────────────────────────

    private function findActiveSession(PDO $pdo, int $warehouseId, ?int $scopeUserId): ?array
    {
        $sql = 'SELECT * FROM wh_t_stock_opname WHERE warehouse_id = :wh AND deleted_at IS NULL AND status IN (\'DRAFT\',\'IN_PROGRESS\')';
        $params = ['wh' => $warehouseId];
        if ($scopeUserId) {
            $sql .= ' AND created_by = :uid';
            $params['uid'] = $scopeUserId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    private function getDetailsForOpname(PDO $pdo, int $opnameId, string $orderBy): array
    {
        $stmt = $pdo->prepare(
            "SELECT d.*, m.code AS material_code, m.name AS material_name, u.code AS unit_code
             FROM wh_t_stock_opname_detail d
             LEFT JOIN wh_m_material m ON m.id = d.material_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             WHERE d.stock_opname_id = :id
             ORDER BY {$orderBy}"
        );
        $stmt->execute(['id' => $opnameId]);
        return $stmt->fetchAll();
    }

    private function currentStatus(PDO $pdo, int $opnameId): string
    {
        $stmt = $pdo->prepare('SELECT status FROM wh_t_stock_opname WHERE id = :id');
        $stmt->execute(['id' => $opnameId]);
        return (string) $stmt->fetchColumn();
    }

    private static function formatSessionRow(array $s): array
    {
        return [
            'id' => (int) $s['id'],
            'session_number' => $s['opname_number'],
            'opname_date' => date('d-m-Y', strtotime($s['opname_date'])),
            'conducted_by' => $s['created_by'] !== null ? (int) $s['created_by'] : null,
            'conducted_by_name' => $s['creator_username'] ?? '-',
            'notes' => $s['catatan'],
            'status' => self::normalizeStatus($s['status']),
            'raw_status' => $s['status'],
            'item_count' => (int) ($s['item_count'] ?? 0),
        ];
    }

    private static function formatDetailRow(array $d): array
    {
        return [
            'id' => (int) $d['id'],
            'material_id' => (int) $d['material_id'],
            'material_code' => $d['material_code'] ?? '-',
            'material_name' => $d['material_name'] ?? '-',
            'unit' => $d['unit_code'] ?? '-',
            'qty_system' => (float) $d['qty_system'],
            'qty_actual' => (float) $d['qty_physical'], // FE expect nama ini, bukan qty_physical
            'qty_variance' => (float) $d['qty_variance'],
            'notes' => $d['notes'] ?? null,
        ];
    }

    /** DRAFT/IN_PROGRESS->draft, SUBMITTED->submitted, APPROVED/ADJUSTED->approved, REJECTED->rejected, CANCELLED->cancelled. */
    private static function normalizeStatus(string $status): string
    {
        switch ($status) {
            case 'DRAFT':
            case 'IN_PROGRESS':
                return 'draft';
            case 'SUBMITTED':
                return 'submitted';
            case 'APPROVED':
            case 'ADJUSTED':
                return 'approved';
            case 'REJECTED':
                return 'rejected';
            case 'CANCELLED':
                return 'cancelled';
            default:
                return strtolower($status);
        }
    }

    private static function nullableString($v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }
}
