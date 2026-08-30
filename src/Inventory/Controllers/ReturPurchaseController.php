<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Database;
use App\Inventory\Support\ApiEnvelope;
use App\Inventory\Support\ReturPurchase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "Ajukan Retur PO" (2026-08-30, BARU -- rombak alur Retur/PO) -- HTTP
 * layer tipis di atas `App\Inventory\Support\ReturPurchase` (semua logika/
 * SQL ada di sana). Pola SAMA PERSIS dgn `Ekspedisi\Controllers\PoSuratJalanController`.
 */
class ReturPurchaseController
{
    use ApiEnvelope;

    /**
     * GET /inventory/purchase-retur/eligible-po
     * query: warehouse_id
     */
    public function eligiblePo(Request $request, Response $response): Response
    {
        $warehouseId = (int) (($request->getQueryParams())['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }

        return $this->apiSuccess($response, ReturPurchase::eligiblePo(Database::connection(), $warehouseId));
    }

    /**
     * POST /inventory/purchase-retur/create
     * body: { purchase_order_id, notes, warehouse_id, items: [{po_detail_id, qty_returned, reason?, notes?}, ...] }
     */
    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        try {
            $result = ReturPurchase::create(Database::connection(), [
                'purchase_order_id' => $body['purchase_order_id'] ?? null,
                'notes' => $body['notes'] ?? null,
                'warehouse_id' => $body['warehouse_id'] ?? null,
                'items' => $body['items'] ?? [],
                'user_id' => (int) $request->getAttribute('user_id'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($response, $e->getMessage(), 422);
        }

        return $this->apiSuccess($response, $result, 'Retur berhasil diajukan.', 201);
    }

    /**
     * POST /inventory/purchase-retur/list
     * body: { warehouse_id }
     */
    public function list(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }

        return $this->apiSuccess($response, ReturPurchase::list(Database::connection(), $warehouseId));
    }
}
