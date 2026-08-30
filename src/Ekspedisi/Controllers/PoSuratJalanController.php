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
     * POST /admin/sj-po -- BARU (rombak alur Retur/PO 2026-08-30): multipart
     * (bukan JSON polos lagi) karena sekarang wajib sertakan foto bukti
     * terima. `items` dikirim sbg field terpisah berisi JSON string (pola
     * sama dgn StockInController::submitStockInManual() di modul Inventory),
     * bukan bracket-notation, supaya tetap 1 field array utuh yang gampang
     * di-decode.
     *
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

        // SJ belum punya id (belum di-insert) -- pakai token unik sbg nama
        // folder sementara, pola sama dgn folder terpisah per konteks yang
        // sudah dipakai di tempat lain (mis. stockin_manual/{adjId}).
        $uploadToken = uniqid('sj', true);
        $dir = dirname(__DIR__, 3) . "/public/uploads/sj-po-receive/{$uploadToken}";
        $relativePath = PhotoStorage::save($request, 'photo', $dir, "uploads/sj-po-receive/{$uploadToken}", 'bukti-terima');
        if ($relativePath === null) {
            return $this->error($response, 'Foto bukti terima wajib diunggah, maksimal 8MB.');
        }

        try {
            $result = PoSuratJalan::create(Database::connection(), [
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
