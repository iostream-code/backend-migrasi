<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Ekspedisi;
use App\Support\PengajuanBiaya;
use App\Support\PhotoStorage;
use App\Support\SpkReadyKirim;
use App\Support\SupirProfile;
use App\Support\SuratJalanLookup;
use App\Support\TripPresenter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController extends Controller
{
    private const SJ_STEP_LABEL = ['draft' => 'Menunggu bukti kirim', 'terkirim' => 'Dalam pengiriman'];

    /**
     * GET /admin/drivers
     * Daftar supir yang SEDANG MELAKUKAN PENGIRIMAN + posisi & status
     * terakhir, dipakai marker di peta tab "Ekspedisi" (2026-08-20: tab ini
     * diputuskan jadi MURNI monitoring, bukan lagi tempat plotting supir ke
     * SPK -- lihat "Plot SPK ke Supir" yang dihapus & komentar
     * SuratJalanController::store() soal auto-bikin trip). Dulu nampilin
     * SEMUA supir tanpa syarat; sekarang di-filter cuma yang "sedang
     * mengirim", ditentukan lewat DUA jalur independen:
     * 1. Py trip aktif (`ekspedisi_t_trip.status='in_progress'`) -- jalur
     *    supir INTERNAL (trip auto-dibikin saat SJ dibuat dgn driver
     *    internal), atau trip lama peninggalan "Plot SPK ke Supir" sebelum
     *    dihapus yang masih aktif (backward-compat, tidak tiba-tiba hilang
     *    dari monitoring).
     * 2. Py SJ yang belum tervalidasi (`ekspedisi_t_surat_jalan.status` IN
     *    draft/terkirim) -- jalur supir EKSTERNAL, yang sejak 2026-08-20
     *    TIDAK PERNAH dibikinkan trip lagi (tidak bisa login/checkpoint apa
     *    pun) -- status "sedang mengirim"-nya cukup dibaca dari SJ langsung.
     * NB: {driver} pada endpoint ini adalah id dari ekspedisi_m_supir, bukan user_id shared_m_users.
     */
    public function drivers(Request $request, Response $response): Response
    {
        $pdo = Database::connection();

        // LEFT JOIN (bukan JOIN) -- supir tipe='eksternal' tidak punya baris
        // shared_m_users sama sekali, namanya diambil dari nama_eksternal.
        $stmt = $pdo->query(
            "SELECT s.id, s.tipe, s.driver_status, s.last_lat, s.last_lng, s.last_ping_at,
                    COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama
             FROM ekspedisi_m_supir s
             LEFT JOIN shared_m_users u ON u.user_id = s.user_id
             WHERE EXISTS (
                 SELECT 1 FROM ekspedisi_t_trip t WHERE t.driver_id = s.id AND t.status = 'in_progress'
             ) OR EXISTS (
                 SELECT 1 FROM ekspedisi_t_surat_jalan sj WHERE sj.driver_id = s.id AND sj.status IN ('draft', 'terkirim')
             )
             ORDER BY nama"
        );
        $drivers = $stmt->fetchAll();

        $tripStmt = $pdo->prepare(
            "SELECT * FROM ekspedisi_t_trip WHERE driver_id = :driver_id AND status = 'in_progress' ORDER BY id DESC LIMIT 1"
        );
        $sjStmt = $pdo->prepare(
            "SELECT status FROM ekspedisi_t_surat_jalan WHERE driver_id = :driver_id AND status IN ('draft', 'terkirim') ORDER BY id DESC LIMIT 1"
        );

        $result = array_map(function ($driver) use ($pdo, $tripStmt, $sjStmt) {
            $tripStmt->execute(['driver_id' => $driver['id']]);
            $activeTrip = $tripStmt->fetch();

            if ($activeTrip) {
                $stepLabel = TripPresenter::nextStepLabel(TripPresenter::completedSteps($pdo, (int) $activeTrip['id']));
            } else {
                // Supir eksternal (atau internal yg SJ-nya kebetulan tidak
                // py trip) -- tidak ada checkpoint utk dibaca, tampilkan
                // status SJ-nya langsung sbg gantinya.
                $sjStmt->execute(['driver_id' => $driver['id']]);
                $sjStatus = $sjStmt->fetchColumn();
                $stepLabel = $sjStatus ? (self::SJ_STEP_LABEL[$sjStatus] ?? $sjStatus) : null;
            }

            return [
                'id' => (int) $driver['id'],
                'tipe' => $driver['tipe'],
                'name' => $driver['nama'],
                'status' => $driver['driver_status'],
                'lat' => $driver['last_lat'] !== null ? (float) $driver['last_lat'] : null,
                'lng' => $driver['last_lng'] !== null ? (float) $driver['last_lng'] : null,
                'last_ping_at' => $driver['last_ping_at'],
                'current_step_label' => $stepLabel,
            ];
        }, $drivers);

        return $this->json($response, $result);
    }

    /**
     * POST /admin/drivers
     * Tambah supir baru -- INTERNAL (pegawai, cari akun di shared_m_users lewat
     * username, sama seperti admin -- lihat AuthController) atau EKSTERNAL
     * (bukan pegawai, tidak bisa login ke app ini sama sekali -- murni catatan
     * dispatch, opsional ditautkan ke perusahaan ekspedisi dari m_expedisi).
     * multipart (2026-08-20, dulu JSON polos -- sekarang WAJIB bawa dokumen):
     * body internal:  { tipe: 'internal', username } + file `foto_sim` (WAJIB)
     * body eksternal: { tipe: 'eksternal', nama, telepon?, id_expedisi? } + file
     *                 `foto_ktp`, `foto_sim`, `foto_stnk` (KETIGANYA WAJIB)
     * Lihat database/01_schema.sql & App\Support\PhotoStorage.
     */
    public function createDriver(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $body = (array) $request->getParsedBody();
        $tipe = ($body['tipe'] ?? 'internal') === 'eksternal' ? 'eksternal' : 'internal';

        if ($tipe === 'eksternal') {
            return $this->createDriverEksternal($request, $pdo, $response, $body);
        }

        $username = trim((string) ($body['username'] ?? ''));
        if ($username === '') {
            return $this->error($response, 'username wajib diisi.');
        }

        // Cek kelengkapan file SEBELUM menyentuh DB sama sekali -- supaya
        // tidak ada baris setengah jadi kalau validasi dokumen gagal (profil
        // supir INTERNAL yang sudah ke-provision otomatis lewat login
        // pertama TETAP boleh dilengkapi SIM-nya di sini, lihat SupirProfile::ensure()).
        $files = $request->getUploadedFiles();
        if (empty($files['foto_sim']) || $files['foto_sim']->getError() !== UPLOAD_ERR_OK) {
            return $this->error($response, 'Foto SIM wajib diunggah.');
        }

        $stmt = $pdo->prepare(
            'SELECT user_id, nama_lengkap FROM shared_m_users WHERE username = :username AND user_active = 1 LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->error($response, 'Username tidak ditemukan atau akun tidak aktif.', 404);
        }

        $driverId = SupirProfile::ensure($pdo, (int) $user['user_id']);

        $dir = dirname(__DIR__, 2) . "/public/uploads/drivers/{$driverId}";
        $fotoSim = PhotoStorage::save($request, 'foto_sim', $dir, "uploads/drivers/{$driverId}", 'sim');
        $pdo->prepare('UPDATE ekspedisi_m_supir SET foto_sim = :path WHERE id = :id')
            ->execute(['path' => $fotoSim, 'id' => $driverId]);

        $statusStmt = $pdo->prepare('SELECT driver_status FROM ekspedisi_m_supir WHERE id = :id');
        $statusStmt->execute(['id' => $driverId]);

        return $this->json($response, [
            'id' => $driverId,
            'name' => $user['nama_lengkap'],
            'status' => $statusStmt->fetchColumn(),
            'tipe' => 'internal',
            'foto_sim' => $fotoSim,
        ], 201);
    }

    /**
     * Supir eksternal WAJIB bawa KETIGA dokumen sekaligus (KTP, SIM, STNK) --
     * beda dari internal yang cukup SIM (identitas KTP-nya sendiri sudah
     * terverifikasi lewat status kepegawaian, kendaraannya juga biasanya
     * aset perusahaan, bukan milik pribadi supir).
     */
    private function createDriverEksternal(Request $request, \PDO $pdo, Response $response, array $body): Response
    {
        $nama = trim((string) ($body['nama'] ?? ''));
        $telepon = trim((string) ($body['telepon'] ?? ''));
        $idExpedisi = !empty($body['id_expedisi']) ? (int) $body['id_expedisi'] : null;

        if ($nama === '') {
            return $this->error($response, 'Nama supir eksternal wajib diisi.');
        }
        if ($idExpedisi !== null && !Ekspedisi::find($pdo, $idExpedisi)) {
            return $this->error($response, 'Perusahaan ekspedisi tidak ditemukan atau tidak aktif.');
        }

        $files = $request->getUploadedFiles();
        foreach (['foto_ktp' => 'KTP', 'foto_sim' => 'SIM', 'foto_stnk' => 'STNK'] as $field => $label) {
            if (empty($files[$field]) || $files[$field]->getError() !== UPLOAD_ERR_OK) {
                return $this->error($response, "Foto {$label} wajib diunggah.");
            }
        }

        $insert = $pdo->prepare(
            "INSERT INTO ekspedisi_m_supir (tipe, nama_eksternal, telepon_eksternal, id_expedisi, driver_status)
            VALUES ('eksternal', :nama, :telepon, :id_expedisi, 'offline')"
        );
        $insert->execute([
            'nama' => $nama,
            'telepon' => $telepon ?: null,
            'id_expedisi' => $idExpedisi,
        ]);
        $driverId = (int) $pdo->lastInsertId();

        $dir = dirname(__DIR__, 2) . "/public/uploads/drivers/{$driverId}";
        $fotoKtp = PhotoStorage::save($request, 'foto_ktp', $dir, "uploads/drivers/{$driverId}", 'ktp');
        $fotoSim = PhotoStorage::save($request, 'foto_sim', $dir, "uploads/drivers/{$driverId}", 'sim');
        $fotoStnk = PhotoStorage::save($request, 'foto_stnk', $dir, "uploads/drivers/{$driverId}", 'stnk');
        $pdo->prepare('UPDATE ekspedisi_m_supir SET foto_ktp = :ktp, foto_sim = :sim, foto_stnk = :stnk WHERE id = :id')
            ->execute(['ktp' => $fotoKtp, 'sim' => $fotoSim, 'stnk' => $fotoStnk, 'id' => $driverId]);

        return $this->json($response, [
            'id' => $driverId,
            'name' => $nama,
            'status' => 'offline',
            'tipe' => 'eksternal',
            'foto_ktp' => $fotoKtp,
            'foto_sim' => $fotoSim,
            'foto_stnk' => $fotoStnk,
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
            'SELECT s.id, s.tipe, s.driver_status, s.foto_sim, s.foto_ktp, s.foto_stnk,
                    COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama,
                    COALESCE(u.hp, s.telepon_eksternal) AS hp
            FROM ekspedisi_m_supir s
            LEFT JOIN shared_m_users u ON u.user_id = s.user_id
            WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $driverId]);
        $driver = $stmt->fetch();

        if (!$driver) {
            return $this->error($response, 'Supir tidak ditemukan.', 404);
        }

        $tripsStmt = $pdo->prepare('SELECT * FROM ekspedisi_t_trip WHERE driver_id = :driver_id ORDER BY id DESC');
        $tripsStmt->execute(['driver_id' => $driverId]);
        $trips = $tripsStmt->fetchAll();

        $photosStmt = $pdo->prepare('SELECT type, path FROM ekspedisi_t_trip_photo WHERE trip_id = :trip_id');
        $statusLabels = ['in_progress' => 'Sedang Berjalan', 'completed' => 'Selesai'];
        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $docUrl = fn ($path) => $path ? $appUrl . '/' . $path : null;

        $trips = array_map(function ($trip) use ($photosStmt, $statusLabels, $appUrl) {
            $photosStmt->execute(['trip_id' => $trip['id']]);
            $photos = array_map(fn($p) => [
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
            'tipe' => $driver['tipe'],
            'name' => $driver['nama'],
            'phone' => $driver['hp'],
            'status' => $driver['driver_status'],
            'foto_sim' => $docUrl($driver['foto_sim']),
            'foto_ktp' => $docUrl($driver['foto_ktp']),
            'foto_stnk' => $docUrl($driver['foto_stnk']),
            'trips' => $trips,
        ]);
    }

    /**
     * POST /admin/drivers/{driver}/documents
     * Upload/lengkapi/ganti dokumen (KTP/SIM/STNK) supir yang SUDAH ADA --
     * dipakai buat melengkapi profil supir INTERNAL yang ke-provision
     * otomatis lewat login pertama (SupirProfile::ensure(), tanpa lewat form
     * "Tambah Supir" sama sekali sehingga tidak pernah py dokumen), atau
     * ganti foto yang salah/kadaluarsa. multipart: `foto_sim`?, `foto_ktp`?,
     * `foto_stnk`? -- SEMUANYA opsional di endpoint ini (beda dari POST
     * /admin/drivers yang mewajibkan sesuai tipe supir saat pembuatan awal),
     * isi field mana pun yang mau dilengkapi/diganti, minimal 1.
     */
    public function uploadDriverDocuments(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $driverId = (int) $args['driver'];

        $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_m_supir WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $driverId]);
        if (!$exists->fetchColumn()) {
            return $this->error($response, 'Supir tidak ditemukan.', 404);
        }

        $dir = dirname(__DIR__, 2) . "/public/uploads/drivers/{$driverId}";
        $slots = ['foto_sim' => 'sim', 'foto_ktp' => 'ktp', 'foto_stnk' => 'stnk'];

        $set = [];
        $params = ['id' => $driverId];
        foreach ($slots as $column => $slot) {
            $path = PhotoStorage::save($request, $column, $dir, "uploads/drivers/{$driverId}", $slot);
            if ($path !== null) {
                $set[] = "{$column} = :{$column}";
                $params[$column] = $path;
            }
        }

        if (!$set) {
            return $this->error($response, 'Tidak ada file dokumen yang diunggah.');
        }

        $pdo->prepare('UPDATE ekspedisi_m_supir SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);

        $stmt = $pdo->prepare('SELECT foto_sim, foto_ktp, foto_stnk FROM ekspedisi_m_supir WHERE id = :id');
        $stmt->execute(['id' => $driverId]);
        $row = $stmt->fetch();

        $appUrl = rtrim($_ENV['APP_URL'], '/');
        $docUrl = fn ($path) => $path ? $appUrl . '/' . $path : null;

        return $this->json($response, [
            'foto_sim' => $docUrl($row['foto_sim']),
            'foto_ktp' => $docUrl($row['foto_ktp']),
            'foto_stnk' => $docUrl($row['foto_stnk']),
        ]);
    }

    /**
     * POST /admin/trips/{trip}/complete
     * Admin menandai perjalanan SELESAI secara manual -- SATU-SATUNYA cara
     * menyelesaikan trip supir EKSTERNAL (tidak punya akun, tidak bisa
     * checkpoint foto lewat /driver/trip/{trip}/photo & /complete seperti
     * supir internal). Sengaja ditolak kalau supirnya internal -- supir
     * internal tetap WAJIB checkpoint foto lewat app, admin tidak boleh
     * membypass itu dari sisi sini.
     */
    public function completeTripManual(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $tripId = (int) $args['trip'];

        $stmt = $pdo->prepare(
            'SELECT t.*, s.tipe AS driver_tipe FROM ekspedisi_t_trip t
             JOIN ekspedisi_m_supir s ON s.id = t.driver_id
             WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();

        if (!$trip) {
            return $this->error($response, 'Perjalanan tidak ditemukan.', 404);
        }
        if ($trip['driver_tipe'] !== 'eksternal') {
            return $this->error($response, 'Supir internal wajib menyelesaikan checkpoint foto lewat app, tidak bisa ditandai selesai manual dari admin.', 422);
        }
        if ($trip['status'] === 'completed') {
            return $this->error($response, 'Perjalanan ini sudah selesai.', 422);
        }

        $update = $pdo->prepare(
            "UPDATE ekspedisi_t_trip SET status = 'completed', completed_at = :now WHERE id = :id"
        );
        $update->execute(['now' => date('Y-m-d H:i:s'), 'id' => $tripId]);

        $trip['status'] = 'completed';
        return $this->json($response, TripPresenter::format($pdo, $trip));
    }

    /**
     * GET /admin/spk-belum-sj
     * Daftar SPK ready-kirim yang BELUM ADA SJ sama sekali -- dipakai
     * tab "SPK" (landing page admin) di ekspedisi-apk. Lihat
     * App\Support\SpkReadyKirim::listBelumSj(). ("Belum diplot ke supir"
     * sbg kriteria terpisah, dulu dipakai "Plot SPK ke Supir", SUDAH
     * DIHAPUS 2026-08-20 -- tab Ekspedisi sekarang murni monitoring, lihat
     * komentar drivers() & SuratJalanController::store().)
     * query opsional: q (cari nama client/no SPK), page, per_page (default 20, maks 100)
     */
    public function spkBelumSj(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $result = SpkReadyKirim::listBelumSj(
            Database::connection(),
            !empty($q['q']) ? (string) $q['q'] : null,
            !empty($q['page']) ? (int) $q['page'] : 1,
            !empty($q['per_page']) ? (int) $q['per_page'] : 20
        );

        return $this->json($response, $result);
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
     * Admin menugaskan perjalanan baru ke supir tertentu -- dipakai layar
     * "Perjalanan Baru" (`adminNewTrip.js`, drill-down dari detail supir),
     * jalur MANUAL yang independen dari SJ/SPK sama sekali (mis. errand
     * internal). BUKAN jalur assignment utama lagi sejak 2026-08-20 -- itu
     * sekarang cukup lewat `driver_id` di `POST /admin/sj` (lihat
     * SuratJalanController::store(), auto-bikin trip utk supir internal).
     * body: { destination, no_surat_jalan?, penjualan_id? }
     *
     * no_surat_jalan & penjualan_id (keduanya opsional) ditautkan LOGIS ke
     * surat_jalan.no_surat_jalan / t_penjualan_header.penjualan_id (tabel lama
     * milik backend-production) -- kalau diisi, WAJIB cocok dengan baris yang
     * benar-benar ada (dicegah typo), tapi tidak pernah menulis balik ke tabel
     * itu sendiri.
     */
    public function createTrip(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $driverId = (int) $args['driver'];

        $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_m_supir WHERE id = :id LIMIT 1');
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
            "INSERT INTO ekspedisi_t_trip (driver_id, destination, no_surat_jalan, penjualan_id, status, started_at)
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

    // GET /admin/ekspedisi dipindah ke EkspedisiController (2026-08-20) --
    // sekarang CRUD penuh (create/update/nonaktifkan) ke tabel lokal
    // ekspedisi_m_ekspedisi, bukan cuma baca m_expedisi backend-production
    // lagi. Lihat App\Support\Ekspedisi & database/01_schema.sql.

    /**
     * POST /admin/trips/{trip}/pengajuan-biaya
     * Admin mengajukan biaya ke finance utk 1 perjalanan (supir internal
     * MAUPUN eksternal). nominal_diajukan SENGAJA input manual admin, bukan
     * hasil hitungan sistem.
     * body: { nominal_diajukan, keterangan? }
     */
    public function createPengajuanBiaya(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $tripId = (int) $args['trip'];

        $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_t_trip WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $tripId]);
        if (!$exists->fetchColumn()) {
            return $this->error($response, 'Perjalanan tidak ditemukan.', 404);
        }

        $body = (array) $request->getParsedBody();
        $nominal = isset($body['nominal_diajukan']) ? (float) $body['nominal_diajukan'] : 0.0;
        if ($nominal <= 0) {
            return $this->error($response, 'nominal_diajukan wajib diisi, harus lebih dari 0.');
        }

        $keterangan = !empty($body['keterangan']) ? trim((string) $body['keterangan']) : null;
        $id = PengajuanBiaya::create($pdo, $tripId, $nominal, $keterangan, (int) $request->getAttribute('user_id'));

        return $this->json($response, [
            'id' => $id,
            'trip_id' => $tripId,
            'nominal_diajukan' => $nominal,
            'keterangan' => $keterangan,
            'status' => 'diajukan',
        ], 201);
    }

    /**
     * GET /admin/trips/{trip}/pengajuan-biaya
     * Riwayat pengajuan biaya utk 1 perjalanan.
     */
    public function listPengajuanBiaya(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        return $this->json($response, PengajuanBiaya::listForTrip($pdo, (int) $args['trip']));
    }
}
