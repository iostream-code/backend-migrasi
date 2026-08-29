<?php

declare(strict_types=1);

namespace App\Ekspedisi\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\PhotoStorage;
use App\Ekspedisi\Support\PoSuratJalan;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * "SJ Tarik" (2026-08-29) -- HTTP layer tipis di atas
 * `App\Ekspedisi\Support\PoSuratJalan` (semua logika/SQL ada di sana, baca
 * docblock kelas itu utk konteks lengkap kenapa modul ini ada & apa yg
 * di-port dari mana).
 */
class PoSuratJalanController extends Controller
{
    /**
     * GET /admin/sj-po/approved-po
     */
    public function approvedPo(Request $request, Response $response): Response
    {
        return $this->json($response, PoSuratJalan::approvedPo(Database::connection()));
    }

    /**
     * POST /admin/sj-po/po/{id}/ready
     * multipart: photo (file, WAJIB -- lihat PoSuratJalan::markReady())
     */
    public function markReady(Request $request, Response $response, array $args): Response
    {
        $poId = (int) $args['id'];

        // dirname(__DIR__, 3) -- BUKAN 2, pola sama dgn DriverController/
        // SuratJalanController (lihat komentar lengkap di DriverController.php,
        // bug path lama yg sudah diperbaiki 2026-08-24).
        $dir = dirname(__DIR__, 3) . "/public/uploads/po-readiness/{$poId}";
        $relativePath = PhotoStorage::save($request, 'photo', $dir, "uploads/po-readiness/{$poId}", 'siap-kirim-' . time());
        if ($relativePath === null) {
            return $this->error($response, 'Foto wajib diunggah, maksimal 8MB.');
        }

        // URL ABSOLUT (bukan path relatif) -- pola sama dgn foto migrasi_legacy
        // (SuratJalan) & dokumen supir (AdminController::driverDetail()): kolom
        // `pur_t_po_readiness.photo_path` ini dibaca lintas host (kalau nanti
        // ada konsumen lain yg baca langsung dari tabel produksi), jadi disimpan
        // sbg URL lengkap ke domain backend-migrasi INI, bukan path relatif yg
        // cuma valid dari sudut pandang backend-production.
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $photoUrl = $appUrl . '/' . $relativePath;

        try {
            $result = PoSuratJalan::markReady(Database::connection(), $poId, $photoUrl, (int) $request->getAttribute('user_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->error($response, $e->getMessage());
        }

        return $this->json($response, $result);
    }

    /**
     * GET /admin/sj-po/outstanding-po
     */
    public function outstandingPo(Request $request, Response $response): Response
    {
        return $this->json($response, PoSuratJalan::outstandingPo(Database::connection()));
    }

    /**
     * POST /admin/sj-po
     * body: { driver_name?, vehicle_number?, notes?, items: [{po_detail_id, qty}, ...] }
     */
    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (empty($body['items']) || !is_array($body['items'])) {
            return $this->error($response, 'items wajib diisi minimal 1.');
        }

        try {
            $result = PoSuratJalan::create(Database::connection(), [
                'items' => $body['items'],
                'driver_name' => $body['driver_name'] ?? null,
                'vehicle_number' => $body['vehicle_number'] ?? null,
                'notes' => $body['notes'] ?? null,
                'created_by' => (int) $request->getAttribute('user_id'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($response, $e->getMessage());
        }

        return $this->json($response, $result, 201);
    }

    /**
     * GET /admin/sj-po
     * query: status (csv, opsional -- kosong = semua status)
     */
    public function index(Request $request, Response $response): Response
    {
        $statusRaw = (string) (($request->getQueryParams())['status'] ?? '');
        $statuses = $statusRaw !== '' ? array_map('trim', explode(',', $statusRaw)) : [];

        return $this->json($response, PoSuratJalan::list(Database::connection(), $statuses));
    }

    /**
     * GET /admin/sj-po/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $sj = PoSuratJalan::find(Database::connection(), (int) $args['id']);
        if (!$sj) {
            return $this->error($response, 'Surat Jalan tidak ditemukan.', 404);
        }

        return $this->json($response, $sj);
    }

    /**
     * POST /admin/sj-po/{id}/confirm
     */
    public function confirm(Request $request, Response $response, array $args): Response
    {
        try {
            $result = PoSuratJalan::confirm(Database::connection(), (int) $args['id'], (int) $request->getAttribute('user_id'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error($response, $e->getMessage());
        }

        return $this->json($response, $result);
    }
}
