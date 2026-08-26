<?php

declare(strict_types=1);

// Route table modul Ekspedisi.
//
// [BERUBAH 2026-08-22] Semua path SEKARANG diprefix '/ekspedisi' (dulu flat
// tanpa prefix -- lihat histori git kalau perlu bentuk lamanya). Dipicu bug
// nyata: inventory-apk (app BEDA, modul Inventory) sempat salah memanggil
// `/login` polos dan malah kena AuthController milik modul INI (karena
// waktu itu memang tanpa prefix, jadi Slim mencocokkan ke sini duluan) --
// login inventory-apk "berhasil" tersambung ke server tapi balikin bentuk
// response Ekspedisi (role driver/admin, bukan AdminGudang/StaffGudang),
// diam-diam gagal redirect. Prefix per-modul (sama pola dgn Inventory yang
// dari awal MEMANG sudah `/inventory/*`) menghilangkan kelas bug ini sama
// sekali -- dua modul tidak akan PERNAH bentrok path lagi, tidak peduli
// urutan mount di bootstrap.php. Frontend (ekspedisi-apk) ikut disesuaikan
// di sesi yang sama (lihat src/js/{api,auth,versionCheck}.js sana).
//
// PENTING kalau app ekspedisi-apk yang SUDAH TER-INSTALL di device sopir/
// admin masih pakai build lama (path tanpa prefix): build itu akan mulai
// gagal connect begitu backend ini dgn prefix baru di-deploy, sampai device
// itu update ke build baru. Perlu dikoordinasikan (rilis serentak / grace
// period), BUKAN otomatis aman hanya karena source code-nya sudah disamakan.

use App\Ekspedisi\Controllers\AdminController;
use App\Ekspedisi\Controllers\AuthController;
use App\Ekspedisi\Controllers\ConfigController;
use App\Ekspedisi\Controllers\DriverController;
use App\Ekspedisi\Controllers\EkspedisiController;
use App\Ekspedisi\Controllers\PoSuratJalanController;
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
    $poSuratJalan = new PoSuratJalanController();
    $ekspedisi = new EkspedisiController();
    $config = new ConfigController();

    $app->group('/ekspedisi', function ($mod) use ($auth, $driver, $admin, $suratJalan, $poSuratJalan, $ekspedisi, $config) {
        $mod->post('/login', [$auth, 'login']);
        // Publik (di LUAR AuthMiddleware, sama seperti /login) -- app perlu bisa cek
        // versi SEBELUM/TANPA tergantung sesi valid (mis. token sudah expired, atau
        // dicek sesaat app baru dibuka sebelum sempat login).
        $mod->post('/config/check-version', [$config, 'checkVersion']);

        $mod->group('', function ($group) use ($auth, $driver, $admin, $suratJalan, $poSuratJalan, $ekspedisi) {
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
            $group->group('', function ($adminGroup) use ($admin, $suratJalan, $poSuratJalan, $ekspedisi) {
                $adminGroup->get('/admin/drivers', [$admin, 'drivers']);
                $adminGroup->post('/admin/drivers', [$admin, 'createDriver']);
                $adminGroup->get('/admin/drivers/{driver}', [$admin, 'driverDetail']);
                $adminGroup->post('/admin/drivers/{driver}/documents', [$admin, 'uploadDriverDocuments']);
                $adminGroup->post('/admin/drivers/{driver}/trip', [$admin, 'createTrip']);
                $adminGroup->post('/admin/trips/{trip}/complete', [$admin, 'completeTripManual']);
                $adminGroup->get('/admin/surat-jalan/{no}', [$admin, 'lookupSuratJalan']);
                // GET /admin/spk-belum-sj DIHAPUS (2026-08-23, bareng tab "SPK" di FE) --
                // app disederhanakan jadi 2 halaman admin (SJ/Ekspedisi), lihat AdminController.php
                // & SpkReadyKirim.php (listBelumSj() ikut dihapus, find() masih dipakai createTrip()).
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

                // --- SJ TARIK (submenu "PO", pur_t_surat_jalan -- SKEMA BEDA dari
                // ekspedisi_t_surat_jalan di atas, lihat docblock PoSuratJalanController) ---
                // outstanding-po WAJIB sebelum '/admin/sj-po/{id}', pola sama dgn
                // '/admin/sj/years' vs '/admin/sj/{id}' di atas.
                $adminGroup->get('/admin/sj-po/outstanding-po', [$poSuratJalan, 'outstandingPo']);
                $adminGroup->get('/admin/sj-po', [$poSuratJalan, 'index']);
                $adminGroup->post('/admin/sj-po', [$poSuratJalan, 'store']);
                $adminGroup->get('/admin/sj-po/{id}', [$poSuratJalan, 'show']);
                $adminGroup->post('/admin/sj-po/{id}/confirm', [$poSuratJalan, 'confirm']);
            })->add(new AdminOnlyMiddleware());
        })->add(new AuthMiddleware());
    });
};
