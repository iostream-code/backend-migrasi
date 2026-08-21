<?php

declare(strict_types=1);

// Route table modul Ekspedisi -- dipindah apa adanya dari bootstrap.php lama
// (2026-08-21, restrukturisasi jadi multi-modul) TANPA mengubah satu path
// pun -- ekspedisi-apk (frontend) yang sudah live memanggil path-path ini
// tanpa prefix apa pun (lihat ekspedisi-apk/src/js/config.js), jadi harus
// tetap identik persis. Hanya lokasi file & namespace class yang berubah.

use App\Ekspedisi\Controllers\AdminController;
use App\Ekspedisi\Controllers\AuthController;
use App\Ekspedisi\Controllers\ConfigController;
use App\Ekspedisi\Controllers\DriverController;
use App\Ekspedisi\Controllers\EkspedisiController;
use App\Ekspedisi\Controllers\SuratJalanController;
use App\Ekspedisi\Middleware\AdminOnlyMiddleware;
use App\Ekspedisi\Support\SupirProfile;
use App\Database;
use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $auth = new AuthController();
    $driver = new DriverController();
    $admin = new AdminController();
    $suratJalan = new SuratJalanController();
    $ekspedisi = new EkspedisiController();
    $config = new ConfigController();

    $app->post('/login', [$auth, 'login']);
    // Publik (di LUAR AuthMiddleware, sama seperti /login) -- app perlu bisa cek
    // versi SEBELUM/TANPA tergantung sesi valid (mis. token sudah expired, atau
    // dicek sesaat app baru dibuka sebelum sempat login).
    $app->post('/config/check-version', [$config, 'checkVersion']);

    $app->group('', function ($group) use ($auth, $driver, $admin, $suratJalan, $ekspedisi) {
        $group->post('/logout', [$auth, 'logout']);

        // Dipertahankan untuk kompatibilitas kontrak lama (driver-apk versi awal
        // memanggil /driver/whoami terpisah setelah login) -- role & user sebenarnya
        // sudah dikembalikan langsung di response POST /login.
        $group->get('/driver/whoami', function (Request $request, Response $response) {
            $pdo = Database::connection();
            $userId = (int) $request->getAttribute('user_id');
            $role = (string) $request->getAttribute('role');

            $stmt = $pdo->prepare('SELECT nama_lengkap FROM shared_m_users WHERE user_id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $name = $stmt->fetchColumn();

            $id = $userId;
            if ($role === 'driver') {
                $id = SupirProfile::ensure($pdo, $userId);
            }

            $payload = json_encode(['role' => $role, 'user' => ['id' => $id, 'name' => $name]]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        });

        // --- Supir ---
        $group->get('/driver/me', [$driver, 'me']);
        $group->post('/driver/status', [$driver, 'updateStatus']);
        $group->post('/driver/location', [$driver, 'storeLocation']);
        $group->get('/driver/trip/{trip}', [$driver, 'showTrip']);
        $group->post('/driver/trip/{trip}/photo', [$driver, 'uploadPhoto']);
        $group->post('/driver/trip/{trip}/complete', [$driver, 'completeTrip']);

        // --- Admin / Dispatcher (digerbangi AdminOnlyMiddleware juga) ---
        $group->group('', function ($adminGroup) use ($admin, $suratJalan, $ekspedisi) {
            $adminGroup->get('/admin/drivers', [$admin, 'drivers']);
            $adminGroup->post('/admin/drivers', [$admin, 'createDriver']);
            $adminGroup->get('/admin/drivers/{driver}', [$admin, 'driverDetail']);
            $adminGroup->post('/admin/drivers/{driver}/documents', [$admin, 'uploadDriverDocuments']);
            $adminGroup->post('/admin/drivers/{driver}/trip', [$admin, 'createTrip']);
            $adminGroup->post('/admin/trips/{trip}/complete', [$admin, 'completeTripManual']);
            $adminGroup->get('/admin/surat-jalan/{no}', [$admin, 'lookupSuratJalan']);
            $adminGroup->get('/admin/spk-belum-sj', [$admin, 'spkBelumSj']);
            $adminGroup->post('/admin/trips/{trip}/pengajuan-biaya', [$admin, 'createPengajuanBiaya']);
            $adminGroup->get('/admin/trips/{trip}/pengajuan-biaya', [$admin, 'listPengajuanBiaya']);

            // --- Master data perusahaan ekspedisi eksternal (ekspedisi_m_ekspedisi) ---
            $adminGroup->get('/admin/ekspedisi', [$ekspedisi, 'index']);
            $adminGroup->post('/admin/ekspedisi', [$ekspedisi, 'store']);
            $adminGroup->put('/admin/ekspedisi/{id}', [$ekspedisi, 'update']);

            // --- Modul surat jalan MILIK app ini (ekspedisi_t_surat_jalan) ---
            $adminGroup->get('/admin/sj/spk/{penjualan_id}/items', [$suratJalan, 'spkItems']);
            // WAJIB didaftarkan SEBELUM '/admin/sj/{id}' -- segmen sama-sama 1 kata
            // (/admin/sj/years vs /admin/sj/{id}), kalau kebalik "years" ketangkep
            // sbg id (bukan literal), lihat pola yg sama di main.js FE utk /admin/sj/new.
            $adminGroup->get('/admin/sj/years', [$suratJalan, 'years']);
            $adminGroup->get('/admin/sj', [$suratJalan, 'index']);
            $adminGroup->post('/admin/sj', [$suratJalan, 'store']);
            $adminGroup->get('/admin/sj/{id}', [$suratJalan, 'show']);
            $adminGroup->put('/admin/sj/{id}', [$suratJalan, 'update']);
            $adminGroup->post('/admin/sj/{id}/photo', [$suratJalan, 'uploadPhoto']);
            $adminGroup->post('/admin/sj/{id}/validasi', [$suratJalan, 'validasi']);
        })->add(new AdminOnlyMiddleware());
    })->add(new AuthMiddleware());
};
