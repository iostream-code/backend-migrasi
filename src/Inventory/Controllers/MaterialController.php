<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Database;
use App\Support\DocumentNumber;
use App\Support\PhotoStorage;
use App\Inventory\Support\ApiEnvelope;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * Port dari backend-production App\Http\Controllers\API\Inventory\MaterialController
 * (+ MaterialService + MaterialRepository, Eloquent) ke Slim/PDO polos. Field
 * request/response SENGAJA dikutip apa adanya dari sana (stock_status,
 * unit_abbr alias unit_code, dst) supaya kompatibel kalau inventory-apk
 * suatu saat di-pointing kesini -- lihat catatan di masing-masing method
 * untuk file/baris sumbernya.
 *
 * TIDAK diport di pass ini (lihat plan): download-template/import-material
 * (Excel bulk import) -- fitur terpisah, tidak ada yang bergantung padanya.
 *
 * user_id SELALU dari JWT (`$request->getAttribute('user_id')`), TIDAK
 * PERNAH dari body -- beda dari versi asli yang menerima override user_id
 * dari body request (BaseMaterialRequest::userId()); di sini identitas
 * cuma boleh datang dari token, bukan diklaim sendiri oleh client.
 */
class MaterialController
{
    use ApiEnvelope;

    private const BARCODE_PREFIX = '8991001';

    public function getMaterials(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            return $this->apiError($response, 'warehouse_id wajib diisi.', 422);
        }
        $search = self::nullableString($body['search'] ?? null);
        // Bug ditemukan & diperbaiki (2026-08-22, susulan): akses ganda ke
        // $body['status_filter'] -- kalau key-nya TIDAK ADA sama sekali (FE
        // omit key ini saat filter "all", lihat material.js), null-coalesce
        // pertama bikin in_array() lolos pakai fallback 'all', tapi branch
        // true-nya balik akses $body['status_filter'] mentah (undefined ->
        // null), bukan fallback-nya -- hasil akhirnya statusFilter jadi NULL,
        // bukan 'all', bikin filter di bawah salah nolak semua baris.
        $rawStatusFilter = $body['status_filter'] ?? 'all';
        $statusFilter = in_array($rawStatusFilter, ['ok', 'low', 'empty', 'overstock', 'all'], true)
            ? $rawStatusFilter
            : 'all';

        $pdo = Database::connection();

        $sql = 'SELECT
                    m.id, m.code, m.name, m.category_id, c.name AS category_name, c.code AS category_code,
                    m.unit_id, u.code AS unit_code, u.name AS unit_name,
                    m.barcode, m.is_stockable, m.photo,
                    COALESCE(mw.min_stock, 0)   AS min_stock,
                    COALESCE(mw.max_stock, 0)   AS max_stock,
                    mw.rack_location,
                    COALESCE(sb.qty_on_hand, 0) AS current_stock
                FROM wh_m_material m
                LEFT JOIN wh_m_material_category c ON c.id = m.category_id
                LEFT JOIN shared_m_unit u ON u.id = m.unit_id
                LEFT JOIN wh_m_material_warehouse mw ON mw.material_id = m.id AND mw.warehouse_id = :wh1
                LEFT JOIN wh_t_stock_balance sb ON sb.material_id = m.id AND sb.warehouse_id = :wh2
                WHERE m.is_active = 1 AND m.deleted_at IS NULL';
        $params = ['wh1' => $warehouseId, 'wh2' => $warehouseId];

        if ($search !== null) {
            $sql .= ' AND (m.code LIKE :s1 OR m.name LIKE :s2 OR c.name LIKE :s3)';
            $like = "%{$search}%";
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
        }
        $sql .= ' ORDER BY m.code';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $m) {
            $row = self::formatMaterialRow($m);
            if ($statusFilter !== 'all' && $row['stock_status'] !== $statusFilter) {
                continue;
            }
            $rows[] = $row;
        }

        return $this->apiSuccess($response, ['materials' => $rows]);
    }

    public function getUnits(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, code, name FROM shared_m_unit WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name'
        );
        return $this->apiSuccess($response, ['units' => $stmt->fetchAll()]);
    }

    public function getCategories(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $stmt = $pdo->query(
            'SELECT id, code, name FROM wh_m_material_category WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name'
        );
        return $this->apiSuccess($response, ['categories' => $stmt->fetchAll()]);
    }

    public function storeMaterial(Request $request, Response $response): Response
    {
        $payload = $this->extractPayload($request);

        try {
            $this->validateCommon($payload);
        } catch (InvalidArgumentException $e) {
            return $this->apiError($response, $e->getMessage(), 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // code SELALU auto-generate (barcode/code dari body diabaikan saat
            // create) -- sama seperti MaterialService::createMaterial() asli,
            // yang juga tidak pernah pakai $payload['barcode'] di create.
            $code = $this->generateUniqueCode($pdo);
            $barcode = $this->generateBarcode($pdo);
            $photoPath = $this->uploadPhoto($request);

            // :now1/:now2 (bukan :now dipakai dua kali) -- PDO_MySQL dgn real
            // prepared statement (ATTR_EMULATE_PREPARES=false, lihat Database.php)
            // MENOLAK named placeholder yang sama dipakai >1 kali dlm satu query
            // ("Invalid parameter number"), beda dari mode emulated. Berlaku di
            // semua query baru di file ini/OpnameController/StockPosting.
            $now = date('Y-m-d H:i:s');
            $ins = $pdo->prepare(
                'INSERT INTO wh_m_material
                    (code, name, category_id, unit_id, barcode, is_stockable, default_unit_cost, photo, is_active, created_by, created_at, updated_at)
                 VALUES
                    (:code, :name, :category_id, :unit_id, :barcode, :stockable, 0, :photo, 1, :created_by, :now1, :now2)'
            );
            $ins->execute([
                'code' => $code,
                'name' => $payload['name'],
                'category_id' => $payload['category_id'],
                'unit_id' => $payload['unit_id'],
                'barcode' => $barcode,
                'stockable' => $payload['is_stockable'],
                'photo' => $photoPath,
                'created_by' => $payload['user_id'],
                'now1' => $now,
                'now2' => $now,
            ]);
            $materialId = (int) $pdo->lastInsertId();

            $this->upsertMaterialWarehouse($pdo, $materialId, $payload);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['material_id' => $materialId, 'code' => $code],
                "Material {$code} berhasil ditambahkan",
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

    public function updateMaterial(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $materialId = (int) ($body['material_id'] ?? 0);
        if ($materialId <= 0) {
            return $this->apiError($response, 'material_id wajib diisi.', 422);
        }

        $payload = $this->extractPayload($request);
        try {
            $this->validateCommon($payload);
        } catch (InvalidArgumentException $e) {
            return $this->apiError($response, $e->getMessage(), 422);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM wh_m_material WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $materialId]);
            $material = $stmt->fetch();
            if (!$material) {
                throw new RuntimeException('Material tidak ditemukan');
            }

            $photoPath = $this->uploadPhoto($request);
            if ($photoPath === null) {
                $photoPath = $material['photo']; // tidak upload baru -> pertahankan foto lama
            }

            $barcode = ($body['barcode'] ?? null) !== null && trim((string) $body['barcode']) !== ''
                ? trim((string) $body['barcode'])
                : null;

            $upd = $pdo->prepare(
                'UPDATE wh_m_material
                 SET name = :name, category_id = :category_id, unit_id = :unit_id, barcode = :barcode,
                     is_stockable = :stockable, photo = :photo, updated_by = :updated_by, updated_at = :now
                 WHERE id = :id'
            );
            $upd->execute([
                'name' => $payload['name'],
                'category_id' => $payload['category_id'],
                'unit_id' => $payload['unit_id'],
                'barcode' => $barcode,
                'stockable' => $payload['is_stockable'],
                'photo' => $photoPath,
                'updated_by' => $payload['user_id'],
                'now' => date('Y-m-d H:i:s'),
                'id' => $materialId,
            ]);

            $this->upsertMaterialWarehouse($pdo, $materialId, $payload);

            $pdo->commit();

            return $this->apiSuccess(
                $response,
                ['material_id' => $materialId, 'code' => $material['code']],
                "Material {$material['code']} berhasil diupdate"
            );
        } catch (InvalidArgumentException | RuntimeException $e) {
            $pdo->rollBack();
            $code = ($e instanceof RuntimeException && $e->getMessage() === 'Material tidak ditemukan') ? 404 : 422;
            return $this->apiError($response, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteMaterial(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $materialId = (int) ($body['material_id'] ?? 0);
        if ($materialId <= 0) {
            return $this->apiError($response, 'material_id wajib diisi.', 422);
        }
        $userId = (int) $request->getAttribute('user_id');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM wh_m_material WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $materialId]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('Material tidak ditemukan');
            }

            // Soft delete -- tidak sentuh wh_m_material_warehouse (history per-warehouse tetap ada),
            // sama seperti MaterialService::deleteMaterial() asli.
            $upd = $pdo->prepare(
                'UPDATE wh_m_material SET is_active = 0, updated_by = :uid, updated_at = :now1, deleted_at = :now2 WHERE id = :id'
            );
            $now = date('Y-m-d H:i:s');
            $upd->execute(['uid' => $userId, 'now1' => $now, 'now2' => $now, 'id' => $materialId]);

            $pdo->commit();
            return $this->apiSuccess($response, ['deleted' => true], 'Material berhasil dihapus');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            return $this->apiError($response, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ─── Private helpers ────────────────────────────────────────────

    private function extractPayload(Request $request): array
    {
        $body = (array) $request->getParsedBody();
        return [
            'warehouse_id' => (int) ($body['warehouse_id'] ?? 0),
            'name' => is_string($body['name'] ?? null) ? trim($body['name']) : null,
            'category_id' => !empty($body['category_id']) ? (int) $body['category_id'] : null,
            'unit_id' => (int) ($body['unit_id'] ?? 0),
            'is_stockable' => isset($body['is_stockable']) ? (((int) $body['is_stockable']) ? 1 : 0) : 1,
            'min_stock' => (float) ($body['min_stock'] ?? 0),
            'max_stock' => (float) ($body['max_stock'] ?? 0),
            'rack_location' => self::nullableString($body['rack_location'] ?? null),
            'user_id' => (int) $request->getAttribute('user_id'),
        ];
    }

    /** Sama persis MaterialService::validateCommon(). */
    private function validateCommon(array $payload): void
    {
        if (empty($payload['name'])) {
            throw new InvalidArgumentException('Nama material wajib diisi');
        }
        if (empty($payload['unit_id'])) {
            throw new InvalidArgumentException('Satuan wajib diisi');
        }
        if (empty($payload['warehouse_id'])) {
            throw new InvalidArgumentException('Warehouse wajib diisi');
        }
        $min = $payload['min_stock'];
        $max = $payload['max_stock'];
        if ($min < 0 || $max < 0) {
            throw new InvalidArgumentException('Min/Max stok tidak boleh negatif');
        }
        if ($max > 0 && $max < $min) {
            throw new InvalidArgumentException('Max stok harus lebih besar atau sama dengan Min stok');
        }
    }

    private function upsertMaterialWarehouse(PDO $pdo, int $materialId, array $payload): void
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM wh_m_material_warehouse WHERE material_id = :m AND warehouse_id = :w'
        );
        $stmt->execute(['m' => $materialId, 'w' => $payload['warehouse_id']]);
        $existing = $stmt->fetch();

        $now = date('Y-m-d H:i:s');
        if ($existing) {
            $upd = $pdo->prepare(
                'UPDATE wh_m_material_warehouse
                 SET min_stock = :min, max_stock = :max, rack_location = :rack, updated_by = :uid, updated_at = :now
                 WHERE id = :id'
            );
            $upd->execute([
                'min' => $payload['min_stock'], 'max' => $payload['max_stock'], 'rack' => $payload['rack_location'],
                'uid' => $payload['user_id'], 'now' => $now, 'id' => $existing['id'],
            ]);
            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO wh_m_material_warehouse
                (material_id, warehouse_id, min_stock, max_stock, rack_location, is_allowed, created_by, created_at, updated_at)
             VALUES (:m, :w, :min, :max, :rack, 1, :uid, :now1, :now2)'
        );
        $ins->execute([
            'm' => $materialId, 'w' => $payload['warehouse_id'], 'min' => $payload['min_stock'],
            'max' => $payload['max_stock'], 'rack' => $payload['rack_location'], 'uid' => $payload['user_id'], 'now1' => $now, 'now2' => $now,
        ]);
    }

    /** Generate kode unik via cfg_m_doc_number (doc_type MAT, format MAT-{NNNNN}), sync ke max aktual dulu. */
    private function generateUniqueCode(PDO $pdo): string
    {
        $row = $pdo->query("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) AS max_num FROM wh_m_material WHERE code LIKE 'MAT-%'")->fetch();
        $maxNum = (int) ($row['max_num'] ?? 0);
        if ($maxNum > 0) {
            DocumentNumber::syncToAtLeast($pdo, 'MAT', $maxNum);
        }

        $code = DocumentNumber::next($pdo, 'MAT');

        $exists = $pdo->prepare('SELECT 1 FROM wh_m_material WHERE code = :c LIMIT 1');
        $exists->execute(['c' => $code]);
        if ($exists->fetch()) {
            throw new RuntimeException('Gagal generate kode material unik -- race condition terdeteksi, silakan coba lagi.');
        }

        return $code;
    }

    /** Port dari MaterialRepository::getNextBarcodeSequence('8991001'). */
    private function generateBarcode(PDO $pdo): string
    {
        $prefixLen = strlen(self::BARCODE_PREFIX);
        $stmt = $pdo->prepare(
            "SELECT barcode FROM wh_m_material WHERE barcode LIKE :p AND barcode IS NOT NULL
             ORDER BY CAST(SUBSTRING(barcode, :len1) AS UNSIGNED) DESC LIMIT 1"
        );
        $stmt->execute(['p' => self::BARCODE_PREFIX . '%', 'len1' => $prefixLen + 1]);
        $last = $stmt->fetchColumn();

        $seq = $last ? ((int) substr((string) $last, $prefixLen)) + 1 : 1;
        return self::BARCODE_PREFIX . str_pad((string) $seq, max(3, strlen((string) $seq)), '0', STR_PAD_LEFT);
    }

    private function uploadPhoto(Request $request): ?string
    {
        $baseDir = __DIR__ . '/../../../public/uploads/materials';
        return PhotoStorage::save($request, 'photo', $baseDir, 'uploads/materials', 'material_' . uniqid());
    }

    /** Port dari MaterialService::formatMaterialRow() + computeStockStatus(). */
    private static function formatMaterialRow(array $m): array
    {
        $current = (float) $m['current_stock'];
        $min = (float) $m['min_stock'];
        $max = (float) $m['max_stock'];

        if ($current <= 0) {
            $status = 'empty';
        } elseif ($min > 0 && $current < $min) {
            $status = 'low';
        } elseif ($max > 0 && $current > $max) {
            $status = 'overstock';
        } else {
            $status = 'ok';
        }

        $photoUrl = $m['photo'] ? ('uploads/materials/' . $m['photo']) : null;

        return [
            'id' => (int) $m['id'],
            'code' => $m['code'],
            'name' => $m['name'],
            'category_id' => $m['category_id'] !== null ? (int) $m['category_id'] : null,
            'category' => $m['category_name'],
            'category_code' => $m['category_code'],
            'unit_id' => (int) $m['unit_id'],
            'unit_code' => $m['unit_code'] ?? '-',
            'unit_abbr' => $m['unit_code'] ?? '-',
            'unit_name' => $m['unit_name'] ?? '-',
            'barcode' => $m['barcode'],
            'is_stockable' => (bool) $m['is_stockable'],
            'min_stock' => $min,
            'max_stock' => $max,
            'current_stock' => $current,
            'rack_location' => $m['rack_location'],
            'stock_status' => $status,
            'photo' => $m['photo'],
            'photo_url' => $photoUrl,
        ];
    }

    private static function nullableString($v): ?string
    {
        return (is_string($v) && trim($v) !== '') ? trim($v) : null;
    }
}
