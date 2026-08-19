<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\SpkReadyKirim;
use App\Support\SupirProfile;
use App\Support\SuratJalanLookup;
use App\Support\TripPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController extends Controller
{
    /**
     * GET /admin/drivers
     * Daftar semua supir + posisi & status terakhir, dipakai untuk marker di peta.
     * NB: {driver} pada endpoint ini adalah id dari driver_m_supir, bukan user_id shared_m_users.
     */
    public function drivers(Request $request, Response $response): Response
    {
        $pdo = Database::connection();

        $stmt = $pdo->query(
            'SELECT s.id, s.driver_status, s.last_lat, s.last_lng, s.last_ping_at, u.nama_lengkap
             FROM driver_m_supir s
             JOIN shared_m_users u ON u.user_id = s.user_id
             ORDER BY u.nama_lengkap'
        );
        $drivers = $stmt->fetchAll();

        $tripStmt = $pdo->prepare(
            "SELECT * FROM driver_t_trip WHERE driver_id = :driver_id AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
        );

        $result = array_map(function ($driver) use ($pdo, $tripStmt) {
            $tripStmt->execute(['driver_id' => $driver['id']]);
            $activeTrip = $tripStmt->fetch();

            return [
                'id' => (int) $driver['id'],
                'name' => $driver['nama_lengkap'],
                'status' => $driver['driver_status'],
                'lat' => $driver['last_lat'] !== null ? (float) $driver['last_lat'] : null,
                'lng' => $driver['last_lng'] !== null ? (float) $driver['last_lng'] : null,
                'last_ping_at' => $driver['last_ping_at'],
                'current_step_label' => $activeTrip
                    ? TripPresenter::nextStepLabel(TripPresenter::completedSteps($pdo, (int) $activeTrip['id']))
                    : null,
            ];
        }, $drivers);

        return $this->json($response, $result);
    }

    /**
     * POST /admin/drivers
     * Tambah supir baru: cari akun pegawai di shared_m_users lewat username
     * (supir TIDAK punya akun terpisah, sama seperti admin -- lihat AuthController),
     * lalu pastikan ada profil driver_m_supir untuknya. Idempotent -- kalau pegawai
     * itu sudah py profil supir, yang sudah ada dikembalikan apa adanya (bukan error).
     * body: { username }
     */
    public function createDriver(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));

        if ($username === '') {
            return $this->error($response, 'username wajib diisi.');
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT user_id, nama_lengkap FROM shared_m_users WHERE username = :username AND user_active = 1 LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->error($response, 'Username tidak ditemukan atau akun tidak aktif.', 404);
        }

        $driverId = SupirProfile::ensure($pdo, (int) $user['user_id']);

        $statusStmt = $pdo->prepare('SELECT driver_status FROM driver_m_supir WHERE id = :id');
        $statusStmt->execute(['id' => $driverId]);

        return $this->json($response, [
            'id' => $driverId,
            'name' => $user['nama_lengkap'],
            'status' => $statusStmt->fetchColumn(),
        ], 201);
    }

    /**
     * GET /admin/drivers/{driver}
     * Detail satu supir: info + riwayat trip lengkap dengan foto checkpoint.
     */
    public function driverDetail(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $driverId = (int) $args['driver'];

        $stmt = $pdo->prepare(
            'SELECT s.id, s.driver_status, u.nama_lengkap, u.hp
             FROM driver_m_supir s
             JOIN shared_m_users u ON u.user_id = s.user_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $driverId]);
        $driver = $stmt->fetch();

        if (!$driver) {
            return $this->error($response, 'Supir tidak ditemukan.', 404);
        }

        $tripsStmt = $pdo->prepare('SELECT * FROM driver_t_trip WHERE driver_id = :driver_id ORDER BY id DESC');
        $tripsStmt->execute(['driver_id' => $driverId]);
        $trips = $tripsStmt->fetchAll();

        $photosStmt = $pdo->prepare('SELECT type, path FROM driver_t_trip_photo WHERE trip_id = :trip_id');
        $statusLabels = ['in_progress' => 'Sedang Berjalan', 'completed' => 'Selesai'];
        $appUrl = rtrim($_ENV['APP_URL'], '/');

        $trips = array_map(function ($trip) use ($photosStmt, $statusLabels, $appUrl) {
            $photosStmt->execute(['trip_id' => $trip['id']]);
            $photos = array_map(fn ($p) => [
                'type' => $p['type'],
                'url' => $appUrl . '/' . $p['path'],
            ], $photosStmt->fetchAll());

            return [
                'id' => (int) $trip['id'],
                'destination' => $trip['destination'],
                'no_surat_jalan' => $trip['no_surat_jalan'] ?? null,
                'penjualan_id' => $trip['penjualan_id'] ?? null,
                'status_label' => $statusLabels[$trip['status']] ?? $trip['status'],
                'created_at' => $trip['started_at'] ? date('d M Y H:i', strtotime($trip['started_at'])) : null,
                'photos' => $photos,
            ];
        }, $trips);

        return $this->json($response, [
            'id' => (int) $driver['id'],
            'name' => $driver['nama_lengkap'],
            'phone' => $driver['hp'],
            'status' => $driver['driver_status'],
            'trips' => $trips,
        ]);
    }

    /**
     * GET /admin/spk-ready-kirim
     * Daftar SPK yang sudah disetujui (shipment_status='approved') tapi belum
     * selesai dikirim & belum diplot ke supir manapun -- READ-ONLY ke
     * t_penjualan_header (backend-production). Lihat App\Support\SpkReadyKirim.
     */
    public function spkReadyKirim(Request $request, Response $response): Response
    {
        return $this->json($response, SpkReadyKirim::list(Database::connection()));
    }

    /**
     * GET /admin/surat-jalan/{no}
     * Cek nomor SJ asli (tabel surat_jalan milik backend-production, READ-ONLY)
     * sebelum ditautkan ke trip -- dipakai frontend buat preview/validasi di
     * form "Perjalanan Baru" sebelum submit.
     */
    public function lookupSuratJalan(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $found = SuratJalanLookup::find($pdo, (string) $args['no']);

        if (!$found) {
            return $this->error($response, 'Nomor Surat Jalan tidak ditemukan.', 404);
        }

        return $this->json($response, $found);
    }

    /**
     * POST /admin/drivers/{driver}/trip
     * Admin menugaskan perjalanan baru ke supir tertentu.
     * body: { destination, no_surat_jalan?, penjualan_id? }
     *
     * no_surat_jalan & penjualan_id (keduanya opsional) ditautkan LOGIS ke
     * surat_jalan.no_surat_jalan / t_penjualan_header.penjualan_id (tabel lama
     * milik backend-production) -- kalau diisi, WAJIB cocok dengan baris yang
     * benar-benar ada (dicegah typo), tapi tidak pernah menulis balik ke tabel
     * itu sendiri. penjualan_id dipakai buat plotting dari SPK ready-kirim
     * (lihat spkReadyKirim()) SEBELUM surat_jalan-nya ada; no_surat_jalan
     * biasanya ditautkan belakangan setelah SJ fisik dibuat.
     */
    public function createTrip(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $driverId = (int) $args['driver'];

        $exists = $pdo->prepare('SELECT 1 FROM driver_m_supir WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $driverId]);
        if (!$exists->fetchColumn()) {
            return $this->error($response, 'Supir tidak ditemukan.', 404);
        }

        $body = (array) $request->getParsedBody();
        $destination = isset($body['destination']) ? trim((string) $body['destination']) : null;
        $noSuratJalan = isset($body['no_surat_jalan']) ? trim((string) $body['no_surat_jalan']) : null;
        $penjualanId = isset($body['penjualan_id']) ? trim((string) $body['penjualan_id']) : null;

        if ($noSuratJalan !== null && $noSuratJalan !== '' && !SuratJalanLookup::find($pdo, $noSuratJalan)) {
            return $this->error($response, 'Nomor Surat Jalan tidak ditemukan, cek lagi penulisannya.');
        }
        if ($penjualanId !== null && $penjualanId !== '' && !SpkReadyKirim::find($pdo, $penjualanId)) {
            return $this->error($response, 'SPK/penjualan_id tidak ditemukan, cek lagi penulisannya.');
        }

        $insert = $pdo->prepare(
            "INSERT INTO driver_t_trip (driver_id, destination, no_surat_jalan, penjualan_id, status, started_at)
             VALUES (:driver_id, :destination, :no_surat_jalan, :penjualan_id, 'in_progress', :now)"
        );
        $insert->execute([
            'driver_id' => $driverId,
            'destination' => $destination ?: null,
            'no_surat_jalan' => $noSuratJalan ?: null,
            'penjualan_id' => $penjualanId ?: null,
            'now' => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, [
            'id' => (int) $pdo->lastInsertId(),
            'destination' => $destination,
            'no_surat_jalan' => $noSuratJalan ?: null,
            'penjualan_id' => $penjualanId ?: null,
            'status' => 'in_progress',
        ], 201);
    }
}
