<?php

declare(strict_types=1);

namespace App\Ekspedisi\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\PhotoStorage;
use App\Ekspedisi\Support\SupirProfile;
use App\Ekspedisi\Support\SuratJalan;
use App\Ekspedisi\Support\TripPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DriverController extends Controller
{
    /**
     * GET /driver/me
     * Info supir yang sedang login (dari token) + SEMUA trip aktif (bisa lebih
     * dari 1, karena trip ditugaskan admin, bukan dibuat supir sendiri).
     */
    public function me(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $userId = (int) $request->getAttribute('user_id');
        $driverId = SupirProfile::ensure($pdo, $userId);

        $driver = $this->findDriver($pdo, $driverId);
        $name = $this->userName($pdo, $userId);

        $stmt = $pdo->prepare(
            "SELECT * FROM ekspedisi_t_trip WHERE driver_id = :driver_id AND status = 'in_progress' ORDER BY id DESC"
        );
        $stmt->execute(['driver_id' => $driverId]);
        $trips = $stmt->fetchAll();

        return $this->json($response, [
            'id' => $driverId,
            'name' => $name,
            'status' => $driver['driver_status'],
            'active_trips' => array_map(fn ($t) => TripPresenter::format($pdo, $t), $trips),
        ]);
    }

    /**
     * POST /driver/status
     * body: { status: 'online'|'resting'|'offline' }
     */
    public function updateStatus(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $status = (string) ($body['status'] ?? '');

        if (!in_array($status, ['online', 'resting', 'offline'], true)) {
            return $this->error($response, "status harus salah satu dari: online, resting, offline.");
        }

        $pdo = Database::connection();
        $driverId = SupirProfile::ensure($pdo, (int) $request->getAttribute('user_id'));

        $stmt = $pdo->prepare('UPDATE ekspedisi_m_supir SET driver_status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $driverId]);

        return $this->json($response, ['status' => $status]);
    }

    /**
     * POST /driver/location
     * body: { lat, lng, speed, heading, accuracy, recorded_at }
     * Dipanggil berkala (tiap ~30 detik) dari frontend saat status online.
     */
    public function storeLocation(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        if (!isset($body['lat']) || !isset($body['lng']) || !is_numeric($body['lat']) || !is_numeric($body['lng'])) {
            return $this->error($response, 'lat dan lng wajib diisi angka.');
        }

        $pdo = Database::connection();
        $driverId = SupirProfile::ensure($pdo, (int) $request->getAttribute('user_id'));
        $recordedAt = !empty($body['recorded_at'])
            ? date('Y-m-d H:i:s', strtotime((string) $body['recorded_at']))
            : date('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO ekspedisi_t_location (driver_id, lat, lng, speed, heading, accuracy, recorded_at)
             VALUES (:driver_id, :lat, :lng, :speed, :heading, :accuracy, :recorded_at)'
        );
        $insert->execute([
            'driver_id' => $driverId,
            'lat' => $body['lat'],
            'lng' => $body['lng'],
            'speed' => $body['speed'] ?? null,
            'heading' => $body['heading'] ?? null,
            'accuracy' => $body['accuracy'] ?? null,
            'recorded_at' => $recordedAt,
        ]);

        // Duplikat posisi terakhir di ekspedisi_m_supir supaya query admin/drivers
        // cepat (tanpa perlu subquery/join ke riwayat ekspedisi_t_location tiap saat).
        $update = $pdo->prepare(
            'UPDATE ekspedisi_m_supir SET last_lat = :lat, last_lng = :lng, last_ping_at = :now WHERE id = :id'
        );
        $update->execute(['lat' => $body['lat'], 'lng' => $body['lng'], 'now' => date('Y-m-d H:i:s'), 'id' => $driverId]);

        return $this->json($response, ['ok' => true]);
    }

    /**
     * GET /driver/trip/{trip}
     */
    public function showTrip(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $trip = $this->authorizeTripOwner($pdo, (int) $args['trip'], (int) $request->getAttribute('user_id'));
        if ($trip === null) {
            return $this->error($response, 'Perjalanan tidak ditemukan atau bukan milik kamu.', 403);
        }

        return $this->json($response, TripPresenter::format($pdo, $trip));
    }

    /**
     * POST /driver/trip/{trip}/photo
     * multipart: photo (file), type (berangkat|serah_terima|sj), lat, lng
     */
    public function uploadPhoto(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $trip = $this->authorizeTripOwner($pdo, (int) $args['trip'], (int) $request->getAttribute('user_id'));
        if ($trip === null) {
            return $this->error($response, 'Perjalanan tidak ditemukan atau bukan milik kamu.', 403);
        }

        $body = (array) $request->getParsedBody();
        $type = (string) ($body['type'] ?? '');
        if (!in_array($type, TripPresenter::STEPS, true)) {
            return $this->error($response, 'type harus salah satu dari: ' . implode(', ', TripPresenter::STEPS));
        }

        $tripId = (int) $trip['id'];
        $dir = dirname(__DIR__, 2) . "/public/uploads/trips/{$tripId}";
        // Nama file = $type ("berangkat"/"serah_terima"/"sj") -- bukan
        // timestamp, jadi re-upload checkpoint yang sama TIMPA file lama di
        // disk (konsisten dgn UNIQUE trip_id+type di DB, bukan numpuk file).
        $relativePath = PhotoStorage::save($request, 'photo', $dir, "uploads/trips/{$tripId}", $type);
        if ($relativePath === null) {
            return $this->error($response, 'File photo wajib diunggah, maksimal 8MB.');
        }

        // Satu checkpoint cuma boleh py 1 foto aktif per trip -- upload ulang
        // checkpoint yang sama menimpa baris lama (UNIQUE trip_id+type di schema.sql).
        $upsert = $pdo->prepare(
            'INSERT INTO ekspedisi_t_trip_photo (trip_id, type, path, lat, lng)
             VALUES (:trip_id, :type, :path, :lat, :lng)
             ON DUPLICATE KEY UPDATE path = VALUES(path), lat = VALUES(lat), lng = VALUES(lng)'
        );
        $upsert->execute([
            'trip_id' => $tripId,
            'type' => $type,
            'path' => $relativePath,
            'lat' => $body['lat'] ?? null,
            'lng' => $body['lng'] ?? null,
        ]);

        // Checkpoint "sj" jadi/melengkapi baris di modul surat jalan MILIK app
        // ini sendiri (ekspedisi_t_surat_jalan) -- lihat App\Support\SuratJalan.
        // Sama sekali TIDAK menyentuh tabel surat_jalan lama backend-production.
        if ($type === 'sj') {
            SuratJalan::upsertFromTripPhoto($pdo, $trip, (int) $trip['driver_id'], $relativePath);
        }

        return $this->json($response, [
            'ok' => true,
            'completed_steps' => TripPresenter::completedSteps($pdo, $tripId),
        ]);
    }

    /**
     * POST /driver/trip/{trip}/complete
     * Dipanggil setelah ketiga foto checkpoint terkirim.
     */
    public function completeTrip(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $trip = $this->authorizeTripOwner($pdo, (int) $args['trip'], (int) $request->getAttribute('user_id'));
        if ($trip === null) {
            return $this->error($response, 'Perjalanan tidak ditemukan atau bukan milik kamu.', 403);
        }

        $tripId = (int) $trip['id'];
        $completed = TripPresenter::completedSteps($pdo, $tripId);
        if (count(array_diff(TripPresenter::STEPS, $completed)) > 0) {
            return $this->json($response, [
                'message' => 'Belum semua checkpoint difoto.',
                'completed_steps' => $completed,
            ], 422);
        }

        $update = $pdo->prepare(
            "UPDATE ekspedisi_t_trip SET status = 'completed', completed_at = :now WHERE id = :id"
        );
        $update->execute(['now' => date('Y-m-d H:i:s'), 'id' => $tripId]);

        $trip['status'] = 'completed';
        return $this->json($response, TripPresenter::format($pdo, $trip));
    }

    /**
     * Ambil trip HANYA kalau benar-benar milik profil Supir dari user yang sedang login.
     */
    private function authorizeTripOwner(\PDO $pdo, int $tripId, int $userId): ?array
    {
        $driverId = SupirProfile::ensure($pdo, $userId);

        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_t_trip WHERE id = :id AND driver_id = :driver_id LIMIT 1');
        $stmt->execute(['id' => $tripId, 'driver_id' => $driverId]);
        $trip = $stmt->fetch();

        return $trip ?: null;
    }

    private function findDriver(\PDO $pdo, int $driverId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_m_supir WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $driverId]);
        return $stmt->fetch();
    }

    private function userName(\PDO $pdo, int $userId): ?string
    {
        $stmt = $pdo->prepare('SELECT nama_lengkap FROM shared_m_users WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchColumn() ?: null;
    }
}
