<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Database;
use App\Inventory\Support\ApiEnvelope;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Port dari backend-production App\Http\Controllers\API\Inventory\HomeController
 * (+ DashboardService + MaterialService::getMaterialDetail, Eloquent) ke
 * Slim/PDO polos. Field request/response dikutip apa adanya dari sana
 * (stock_status, butuh_po, dst) supaya kompatibel kalau inventory-apk suatu
 * saat di-pointing kesini.
 *
 * TIDAK diport di pass ini (lihat inventory-apk/ROADMAP.md): getWarehouses,
 * checkInternetInventory, createPurchaseRequest/listPurchaseRequest/
 * getOutstandingReqByMaterial (popup "Request Order" & tab PO) -- belum ada
 * UI-nya yang benar-benar terhubung di inventory-apk saat ini (tab PO masih
 * placeholder, lihat README app itu bagian "Status fitur").
 */
class HomeController
{
    use ApiEnvelope;

    private const OUTSTANDING_PO_STATUSES = ['APPROVED', 'READY', 'SENT', 'PARTIAL_RECEIVED'];
    private const ISSUABLE_REQ_STATUSES = ['APPROVED', 'PARTIAL_ISSUED'];
    private const PENDING_PR_STATUSES = ['SUBMITTED', 'APPROVED', 'PARTIAL_ORDERED'];

    public function getDashboard(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }
        $months = is_numeric($body['months'] ?? null) ? (int) $body['months'] : 0;
        $rawStatusFilter = $body['status_filter'] ?? 'all';
        $statusFilter = in_array($rawStatusFilter, ['ok', 'low', 'empty', 'overstock', 'all'], true)
            ? $rawStatusFilter
            : 'all';

        // months = 0 berarti "semua" -- dateFrom = null (tidak dibatasi), sama
        // seperti DashboardService::getDashboardData() asli (startOfDay juga
        // disamakan, bukan cuma "N bulan dari jam sekarang").
        $dateFrom = $months > 0 ? date('Y-m-d 00:00:00', strtotime("-{$months} months")) : null;

        $pdo = Database::connection();

        $materials = $this->fetchMaterials($pdo, $warehouseId);
        $inData = $this->fetchMutationTotals($pdo, $warehouseId, 'qty_in', $dateFrom);
        $outData = $this->fetchMutationTotals($pdo, $warehouseId, 'qty_out', $dateFrom);
        $poData = $this->fetchPoOutstanding($pdo, $warehouseId, $dateFrom);
        $reqData = $this->fetchReqOutstanding($pdo, $warehouseId);
        $prData = $this->fetchPrOutstanding($pdo, $warehouseId);

        $result = [];
        $countOk = 0;
        $countLow = 0;
        $countEmpty = 0;
        $countOver = 0;

        foreach ($materials as $m) {
            $item = $this->buildMaterialItem($m, $inData, $outData, $poData, $reqData, $prData);
            switch ($item['stock_status']) {
                case 'empty': $countEmpty++; break;
                case 'low': $countLow++; break;
                case 'overstock': $countOver++; break;
                default: $countOk++; break;
            }
            $result[] = $item;
        }

        usort($result, function ($a, $b) {
            $order = ['empty' => 0, 'low' => 1, 'overstock' => 2, 'ok' => 3];
            $diff = ($order[$a['stock_status']] ?? 3) - ($order[$b['stock_status']] ?? 3);
            return $diff !== 0 ? $diff : $b['total_out'] <=> $a['total_out'];
        });

        $filtered = $statusFilter === 'all'
            ? $result
            : array_values(array_filter($result, function ($item) use ($statusFilter) {
                return $item['stock_status'] === $statusFilter;
            }));

        return $this->apiSuccess($response, [
            'summary' => [
                'count_ok' => $countOk,
                'count_low' => $countLow,
                'count_empty' => $countEmpty,
                'count_overstock' => $countOver,
                'count_total' => count($result),
                'count_shown' => count($filtered),
                'status_filter' => $statusFilter,
            ],
            'materials' => $filtered,
        ], 'OK', 200, ['months' => $months, 'status_filter' => $statusFilter]);
    }

    public function getMaterialDetail(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $materialId = (int) ($body['material_id'] ?? 0);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($materialId <= 0 || $warehouseId <= 0) {
            return $this->apiError($response, 'material_id dan warehouse_id wajib diisi.', 422);
        }
        $days = is_numeric($body['days'] ?? null) ? (int) $body['days'] : 0;
        $dateFrom = $days > 0 ? date('Y-m-d 00:00:00', strtotime("-{$days} days")) : null;

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT m.id, m.code, m.name, c.name AS category_name, u.code AS unit_code, u.name AS unit_name,
                    COALESCE(mw.min_stock, 0) AS min_stock, COALESCE(mw.max_stock, 0) AS max_stock,
                    mw.rack_location,
                    sb.id AS balance_id, COALESCE(sb.qty_on_hand, 0) AS qty_on_hand,
                    COALESCE(sb.qty_available, 0) AS qty_available,
                    COALESCE(sb.qty_outstanding_in, 0) AS qty_outstanding_in,
                    COALESCE(sb.qty_outstanding_out, 0) AS qty_outstanding_out,
                    COALESCE(sb.avg_unit_cost, 0) AS avg_unit_cost
             FROM wh_m_material m
             LEFT JOIN wh_m_material_category c ON c.id = m.category_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             LEFT JOIN wh_m_material_warehouse mw ON mw.material_id = m.id AND mw.warehouse_id = :wh1
             LEFT JOIN wh_t_stock_balance sb ON sb.material_id = m.id AND sb.warehouse_id = :wh2
             WHERE m.id = :id AND m.deleted_at IS NULL'
        );
        $stmt->execute(['wh1' => $warehouseId, 'wh2' => $warehouseId, 'id' => $materialId]);
        $m = $stmt->fetch();
        if (!$m) {
            return $this->apiNotFound($response, 'Material tidak ditemukan');
        }

        $historySql = 'SELECT id, mutation_date, transaction_type, reference_number, qty_in, qty_out, unit_cost, balance_after, remarks
                        FROM wh_log_stock_mutation
                        WHERE warehouse_id = :wh AND material_id = :m';
        $summarySql = 'SELECT COALESCE(SUM(qty_in), 0) AS total_in, COALESCE(SUM(qty_out), 0) AS total_out, COUNT(*) AS total_trx
                        FROM wh_log_stock_mutation
                        WHERE warehouse_id = :wh AND material_id = :m';
        $params = ['wh' => $warehouseId, 'm' => $materialId];
        if ($dateFrom !== null) {
            $historySql .= ' AND mutation_date >= :from';
            $summarySql .= ' AND mutation_date >= :from';
            $params['from'] = $dateFrom;
        }
        $historySql .= ' ORDER BY mutation_date DESC, id DESC LIMIT 50';

        $hStmt = $pdo->prepare($historySql);
        $hStmt->execute($params);
        $history = array_map(function ($h) {
            return [
                'id' => (int) $h['id'],
                'date' => date('d/m/Y H:i', strtotime($h['mutation_date'])),
                'transaction_type' => $h['transaction_type'],
                'reference_number' => $h['reference_number'],
                'qty_in' => (float) $h['qty_in'],
                'qty_out' => (float) $h['qty_out'],
                'unit_cost' => (float) $h['unit_cost'],
                'balance_after' => (float) $h['balance_after'],
                'remarks' => $h['remarks'],
            ];
        }, $hStmt->fetchAll());

        $sStmt = $pdo->prepare($summarySql);
        $sStmt->execute($params);
        $sm = $sStmt->fetch();

        return $this->apiSuccess($response, [
            'material' => [
                'id' => (int) $m['id'],
                'code' => $m['code'],
                'name' => $m['name'],
                'category' => $m['category_name'] ?? '-',
                'unit_code' => $m['unit_code'] ?? '-',
                'unit_name' => $m['unit_name'] ?? '-',
                'min_stock' => (float) $m['min_stock'],
                'max_stock' => (float) $m['max_stock'],
                'rack_location' => $m['rack_location'] ?? '-',
                'current_stock' => (float) $m['qty_on_hand'],
                'qty_available' => $m['balance_id'] !== null ? (float) $m['qty_available'] : (float) $m['qty_on_hand'],
                'qty_outstanding_in' => (float) $m['qty_outstanding_in'],
                'qty_outstanding_out' => (float) $m['qty_outstanding_out'],
                'avg_unit_cost' => (float) $m['avg_unit_cost'],
                'stock_status' => $this->resolveStatus($m['balance_id'] !== null, (float) $m['qty_on_hand'], (float) $m['min_stock'], (float) $m['max_stock']),
            ],
            'history' => $history,
            'summary_mutation' => [
                'total_in' => (float) ($sm['total_in'] ?? 0),
                'total_out' => (float) ($sm['total_out'] ?? 0),
                'total_trx' => (int) ($sm['total_trx'] ?? 0),
                'days' => $days,
            ],
        ]);
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function fetchMaterials(PDO $pdo, int $warehouseId): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.id, m.code, m.name, c.name AS category_name, u.code AS unit_code, u.name AS unit_name,
                    m.is_stockable,
                    COALESCE(mw.min_stock, 0) AS min_stock, COALESCE(mw.max_stock, 0) AS max_stock,
                    sb.id AS balance_id, COALESCE(sb.qty_on_hand, 0) AS qty_on_hand,
                    COALESCE(sb.qty_available, 0) AS qty_available,
                    COALESCE(sb.qty_outstanding_in, 0) AS qty_outstanding_in,
                    COALESCE(sb.qty_outstanding_out, 0) AS qty_outstanding_out,
                    COALESCE(sb.avg_unit_cost, 0) AS avg_unit_cost
             FROM wh_m_material m
             LEFT JOIN wh_m_material_category c ON c.id = m.category_id
             LEFT JOIN shared_m_unit u ON u.id = m.unit_id
             LEFT JOIN wh_m_material_warehouse mw ON mw.material_id = m.id AND mw.warehouse_id = :wh1
             LEFT JOIN wh_t_stock_balance sb ON sb.material_id = m.id AND sb.warehouse_id = :wh2
             WHERE m.is_active = 1 AND m.deleted_at IS NULL
             ORDER BY m.code'
        );
        $stmt->execute(['wh1' => $warehouseId, 'wh2' => $warehouseId]);
        return $stmt->fetchAll();
    }

    /** @return array<int,float> [material_id => total] */
    private function fetchMutationTotals(PDO $pdo, int $warehouseId, string $qtyCol, ?string $dateFrom): array
    {
        $sql = "SELECT material_id, SUM({$qtyCol}) AS total FROM wh_log_stock_mutation WHERE warehouse_id = :wh AND {$qtyCol} > 0";
        $params = ['wh' => $warehouseId];
        if ($dateFrom !== null) {
            $sql .= ' AND mutation_date >= :from';
            $params['from'] = $dateFrom;
        }
        $sql .= ' GROUP BY material_id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['material_id']] = (float) $r['total'];
        }
        return $out;
    }

    /** @return array<int,float> [material_id => total_po] */
    private function fetchPoOutstanding(PDO $pdo, int $warehouseId, ?string $dateFrom): array
    {
        $placeholders = implode(',', array_fill(0, count(self::OUTSTANDING_PO_STATUSES), '?'));
        $sql = "SELECT pod.material_id, SUM(pod.qty_ordered - pod.qty_received) AS total
                FROM pur_t_purchase_order_detail pod
                JOIN pur_t_purchase_order po ON po.id = pod.purchase_order_id
                WHERE po.warehouse_id = ? AND po.status IN ({$placeholders}) AND po.deleted_at IS NULL
                  AND pod.qty_received < pod.qty_ordered";
        $params = array_merge([$warehouseId], self::OUTSTANDING_PO_STATUSES);
        if ($dateFrom !== null) {
            $sql .= ' AND po.created_at >= ?';
            $params[] = $dateFrom;
        }
        $sql .= ' GROUP BY pod.material_id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['material_id']] = (float) $r['total'];
        }
        return $out;
    }

    /** @return array<int,float> [material_id => total_req] */
    private function fetchReqOutstanding(PDO $pdo, int $warehouseId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::ISSUABLE_REQ_STATUSES), '?'));
        $sql = "SELECT rmd.material_id, SUM(rmd.qty_approved - rmd.qty_issued) AS total
                FROM prd_t_req_material_detail rmd
                JOIN prd_t_req_material rm ON rm.id = rmd.req_material_id
                WHERE rm.warehouse_id = ? AND rm.status IN ({$placeholders}) AND rm.deleted_at IS NULL
                  AND rmd.qty_approved > rmd.qty_issued AND rmd.qty_approved > 0
                GROUP BY rmd.material_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$warehouseId], self::ISSUABLE_REQ_STATUSES));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['material_id']] = (float) $r['total'];
        }
        return $out;
    }

    /** @return array<int,float> [material_id => total_pr] */
    private function fetchPrOutstanding(PDO $pdo, int $warehouseId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::PENDING_PR_STATUSES), '?'));
        $sql = "SELECT prd.material_id, SUM(prd.qty_requested - prd.qty_ordered) AS total
                FROM pur_t_purchase_request_detail prd
                JOIN pur_t_purchase_request pr ON pr.id = prd.purchase_request_id
                WHERE pr.warehouse_id = ? AND pr.status IN ({$placeholders}) AND pr.deleted_at IS NULL
                  AND prd.qty_ordered < prd.qty_requested
                GROUP BY prd.material_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$warehouseId], self::PENDING_PR_STATUSES));
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['material_id']] = (float) $r['total'];
        }
        return $out;
    }

    private function buildMaterialItem(array $m, array $inData, array $outData, array $poData, array $reqData, array $prData): array
    {
        $id = (int) $m['id'];
        $hasBalance = $m['balance_id'] !== null;
        $stok = (float) $m['qty_on_hand'];
        $minStok = (float) $m['min_stock'];
        $maxStok = (float) $m['max_stock'];
        $outIn = (float) $m['qty_outstanding_in'];
        $outOut = $hasBalance ? (float) $m['qty_outstanding_out'] : 0.0;
        $totalReq = (float) ($reqData[$id] ?? 0);
        $totalPr = (float) ($prData[$id] ?? 0);
        $poOutstanding = (float) ($poData[$id] ?? 0);

        $status = $this->resolveStatus($hasBalance, $stok, $minStok, $maxStok);

        $totalIn = (float) ($inData[$id] ?? 0);
        $totalOut = (float) ($outData[$id] ?? 0);
        $stockPeriod = $totalIn - $totalOut;
        $isStockable = (bool) $m['is_stockable'];

        return [
            'id' => $id,
            'code' => $m['code'],
            'name' => $m['name'],
            'category' => $m['category_name'] ?? '-',
            'unit_code' => $m['unit_code'] ?? '-',
            'unit_name' => $m['unit_name'] ?? '-',
            'is_stockable' => $isStockable,
            'min_stock' => $minStok,
            'max_stock' => $maxStok,
            'current_stock' => $stok,
            'stock_period' => $stockPeriod,
            'qty_available' => $hasBalance ? (float) $m['qty_available'] : $stok,
            'qty_outstanding_in' => $outIn,
            'qty_outstanding_out' => $outOut,
            'avg_unit_cost' => $hasBalance ? (float) $m['avg_unit_cost'] : 0.0,
            'stock_status' => $status,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'total_po' => $poOutstanding,
            'total_req' => $totalReq,
            'total_pr' => $totalPr,
            'has_pending_pr' => $totalPr > 0,
            'butuh_po' => !$isStockable
                ? max(0, $totalReq - $stok - $poOutstanding + $outOut)
                : ($minStok > 0
                    ? max(0, $minStok - $stok - $poOutstanding + $outOut + $totalReq)
                    : 0),
        ];
    }

    /**
     * Port dari StockBalance::resolveStatus() + fallback DashboardService
     * ketika belum ada baris wh_t_stock_balance sama sekali (material belum
     * pernah kena mutasi): resolveStatus SELALU 'empty' kalau qty<=0 (tidak
     * peduli min_stock), tapi fallback "belum ada balance row" beda -- 'empty'
     * HANYA kalau min_stock>0, else 'ok'. Kedua kode asli (DashboardService &
     * MaterialService) sama-sama punya percabangan ini, disatukan di sini.
     */
    private function resolveStatus(bool $hasBalance, float $qty, float $min, float $max): string
    {
        if (!$hasBalance) {
            return $min > 0 ? 'empty' : 'ok';
        }
        if ($qty <= 0) {
            return 'empty';
        }
        if ($min > 0 && $qty < $min) {
            return 'low';
        }
        if ($max > 0 && $qty > $max) {
            return 'overstock';
        }
        return 'ok';
    }
}
