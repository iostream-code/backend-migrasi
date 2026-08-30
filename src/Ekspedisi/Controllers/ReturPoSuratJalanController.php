<?php

declare(strict_types=1);

namespace App\Ekspedisi\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\PhotoStorage;
use App\Ekspedisi\Support\ReturPoSuratJalan;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "Retur PO" (2026-08-30) -- HTTP layer tipis di atas
 * `App\Ekspedisi\Support\ReturPoSuratJalan` (semua logika/SQL ada di sana).
 * Pola SAMA PERSIS dgn `PoSuratJalanController` (sibling-nya).
 */
class ReturPoSuratJalanController extends Controller
{
    /**
     * GET /admin/sj-retur-po/outstanding-po
     */
    public function outstandingRetur(Request $request, Response $response): Response
    {
        return $this->json($response, ReturPoSuratJalan::outstandingRetur(Database::connection()));
    }

    /**
     * POST /admin/sj-retur-po
     * multipart: photo (file, WAJIB), items (string JSON, wajib),
     * driver_name?, vehicle_number?, notes?
     */
    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $items = json_decode((string) ($body['items'] ?? '[]'), true);
        if (empty($items) || !is_array($items)) {
            return $this->error($response, 'items wajib diisi minimal 1.');
        }

        $uploadToken = uniqid('sjretur', true);
        $dir = dirname(__DIR__, 3) . "/public/uploads/sj-po-receive/{$uploadToken}";
        $relativePath = PhotoStorage::save($request, 'photo', $dir, "uploads/sj-po-receive/{$uploadToken}", 'bukti-terima');
        if ($relativePath === null) {
            return $this->error($response, 'Foto bukti terima wajib diunggah, maksimal 8MB.');
        }

        try {
            $result = ReturPoSuratJalan::createAndReceive(Database::connection(), [
                'items' => $items,
                'driver_name' => $body['driver_name'] ?? null,
                'vehicle_number' => $body['vehicle_number'] ?? null,
                'notes' => $body['notes'] ?? null,
                'receive_photo_path' => $relativePath,
                'created_by' => (int) $request->getAttribute('user_id'),
            ]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error($response, $e->getMessage());
        }

        return $this->json($response, $result, 201);
    }

    /**
     * GET /admin/sj-retur-po
     * query: status (csv, opsional -- kosong = semua status)
     */
    public function index(Request $request, Response $response): Response
    {
        $statusRaw = (string) (($request->getQueryParams())['status'] ?? '');
        $statuses = $statusRaw !== '' ? array_map('trim', explode(',', $statusRaw)) : [];

        return $this->json($response, ReturPoSuratJalan::list(Database::connection(), $statuses));
    }

    /**
     * GET /admin/sj-retur-po/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $sj = ReturPoSuratJalan::find(Database::connection(), (int) $args['id']);
        if (!$sj) {
            return $this->error($response, 'Surat Jalan tidak ditemukan.', 404);
        }

        return $this->json($response, $sj);
    }
}
