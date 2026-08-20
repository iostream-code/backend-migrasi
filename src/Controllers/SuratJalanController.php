<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\PenjualanItemLookup;
use App\Support\PhotoStorage;
use App\Support\SuratJalan;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Modul surat jalan MILIK app ini sendiri (ekspedisi_t_surat_jalan) --
 * independen dari tabel surat_jalan lama milik backend-production (lihat
 * App\Support\SuratJalanLookup, cuma read-only ke sana, tidak berhubungan
 * dgn controller ini).
 */
class SuratJalanController extends Controller
{
    /**
     * GET /admin/sj
     * query opsional: status, penjualan_id, q (cari no_surat_jalan/tujuan/
     * penerima/nama supir/no SPK), page (default 1), per_page (default 20, maks 100)
     * Return: { data, total, page, per_page } -- lihat App\Support\SuratJalan::list().
     */
    public function index(Request $request, Response $response): Response
    {
        $filters = $request->getQueryParams();
        return $this->json($response, SuratJalan::list(Database::connection(), $filters));
    }

    /**
     * GET /admin/sj/{id}
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $sj = SuratJalan::find(Database::connection(), (int) $args['id']);
        if (!$sj) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        return $this->json($response, $sj);
    }

    /**
     * GET /admin/sj/spk/{penjualan_id}/items
     * Daftar lini produk 1 SPK + sisa qty yang belum terkirim (READ-ONLY ke
     * t_penjualan_detail_performa, lihat App\Support\PenjualanItemLookup) --
     * dipakai form "Buat Surat Jalan" begitu admin isi/cek nomor SPK, buat
     * nampilin breakdown per produk sebelum submit.
     *
     * {penjualan_id} boleh diisi ID asli persis ("INV_01811-2") ATAU cuma
     * angkanya ("1811"/"1811-2") ATAU format tampilan ("SPK-1811-2") --
     * lihat resolvePenjualanId() (2026-08-20, sebelumnya WAJIB persis "INV_..."
     * apa adanya, tidak praktis diketik admin dari HP tiap kali).
     */
    public function spkItems(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $penjualanId = self::resolvePenjualanId($pdo, trim((string) $args['penjualan_id']));

        if ($penjualanId === null) {
            return $this->error($response, 'SPK/penjualan_id tidak ditemukan, cek lagi penulisannya.', 404);
        }

        return $this->json($response, PenjualanItemLookup::lines($pdo, $penjualanId));
    }

    /**
     * Cari penjualan_id ASLI (persis kolom t_penjualan_header.penjualan_id)
     * dari input admin yang boleh berupa beberapa bentuk:
     * 1. ID asli persis, mis. "INV_01811-2" -- exact match langsung (paling
     *    umum kalau nomornya di-paste dari tempat lain).
     * 2. Cuma angkanya, dgn/tanpa leading zero & prefix "INV_"/"SPK-", mis.
     *    "1811", "01811", "1811-2", "SPK-1811-2" -- dicocokkan via REGEXP
     *    ANCHORED (^...$, bukan substring/LIKE) ke angka & suffix urutannya
     *    PERSIS, supaya "23" tidak ikut nyangkut ke "INV_00123" (beda angka,
     *    kebetulan "23" jadi akhiran string "123"). Match harus PERSIS 1 baris
     *    -- ambigu (jarang terjadi, tapi jaga-jaga) dianggap tidak ketemu
     *    daripada nebak salah.
     */
    private static function resolvePenjualanId(\PDO $pdo, string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        $exact = $pdo->prepare('SELECT penjualan_id FROM t_penjualan_header WHERE penjualan_id = :id LIMIT 1');
        $exact->execute(['id' => $input]);
        $found = $exact->fetchColumn();
        if ($found !== false) {
            return $found;
        }

        if (!preg_match('/^(?:INV_?|SPK-?)?0*(\d+)(-(\d+))?$/i', $input, $m)) {
            return null;
        }
        $num = $m[1];
        $suffix = $m[3] ?? null;
        $pattern = $suffix !== null
            ? '^INV_?0*' . $num . '-' . $suffix . '$'
            : '^INV_?0*' . $num . '$';

        $fuzzy = $pdo->prepare('SELECT penjualan_id FROM t_penjualan_header WHERE penjualan_id REGEXP :pattern LIMIT 2');
        $fuzzy->execute(['pattern' => $pattern]);
        $rows = $fuzzy->fetchAll(\PDO::FETCH_COLUMN);

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * POST /admin/sj
     * Bikin SJ manual dari layar admin -- trip_id opsional (boleh tidak
     * terkait trip manapun), TAPI driver_id WAJIB (supir bukan lagi opsional,
     * lihat komentar validasi di bawah).
     * body: { trip_id?, driver_id (wajib), tujuan?, kendaraan?, plat?, penerima?, jumlah_kirim?, tgl_kirim?, catatan?, items?: [{penjualan_detail_performa_id, jumlah_kirim}, ...] }
     *
     * `items` OPSIONAL, dan BOLEH berisi lini produk dari BEBERAPA SPK
     * BERBEDA sekaligus (2026-08-20 -- realitas di lapangan: 1 SJ fisik bisa
     * sekali jalan angkut pesanan dari lebih dari 1 SPK). Makanya validasi di
     * sini per-ITEM, bukan per-SPK lagi -- setiap
     * `penjualan_detail_performa_id` dicek satu-satu lewat
     * PenjualanItemLookup::findLine() (bukan percaya `sisa` yang dikirim
     * client, bisa basi kalau ada SJ lain masuk di antara admin buka form &
     * submit), SPK-nya sendiri baru ketahuan dari situ (tidak perlu dikirim
     * terpisah lagi lewat body['penjualan_id']). Kalau `items` kosong, tetap
     * boleh bikin SJ freeform tanpa SPK sama sekali (mis. sampel/transfer
     * internal) -- `jumlah_kirim` manual dari body.
     *
     * **Auto-bikin trip utk supir INTERNAL (2026-08-20)** -- dulu supir
     * dapat tugas checkpoint foto lewat langkah terpisah "Plot SPK ke Supir"
     * (bikin `ekspedisi_t_trip` SEBELUM SJ ada). Langkah itu DIHAPUS (tab
     * "Ekspedisi" sekarang murni monitoring, lihat AdminController::drivers())
     * -- assignment supir sekarang cukup lewat `driver_id` di SJ ini. Supaya
     * supir INTERNAL tetap bisa lihat tugasnya & checkpoint foto sendiri
     * lewat app-nya, `store()` OTOMATIS bikin trip kalau `trip_id` tidak
     * dikirim eksplisit & supirnya internal (lihat blok di bawah) -- supir
     * EKSTERNAL sengaja TIDAK dibikinkan trip (tidak bisa login/checkpoint
     * apa pun), status "sedang mengirim"-nya cukup dibaca dari status SJ ini
     * langsung.
     */
    public function store(Request $request, Response $response): Response
    {
        $pdo = Database::connection();
        $body = (array) $request->getParsedBody();

        $tripId = !empty($body['trip_id']) ? (int) $body['trip_id'] : null;
        if ($tripId !== null) {
            $exists = $pdo->prepare('SELECT 1 FROM ekspedisi_t_trip WHERE id = :id LIMIT 1');
            $exists->execute(['id' => $tripId]);
            if (!$exists->fetchColumn()) {
                return $this->error($response, 'Trip tidak ditemukan.');
            }
        }

        // Supir WAJIB (2026-08-20) -- dulu opsional ("SJ boleh dibuat dulu,
        // supirnya belakangan"), tapi itu bikin ambigu siapa yang bawa
        // dokumen fisiknya. Kalau nanti supirnya memang belum ada (mis. SPK
        // baru diplot belakangan), buat SJ-nya belakangan juga setelah ada
        // supir -- bukan buat SJ tanpa supir lalu susulan.
        if (empty($body['driver_id'])) {
            return $this->error($response, 'Supir wajib dipilih.');
        }
        $driverId = (int) $body['driver_id'];
        $driverStmt = $pdo->prepare('SELECT tipe FROM ekspedisi_m_supir WHERE id = :id LIMIT 1');
        $driverStmt->execute(['id' => $driverId]);
        $driverTipe = $driverStmt->fetchColumn();
        if ($driverTipe === false) {
            return $this->error($response, 'Supir tidak ditemukan.');
        }

        $items = [];
        $touchedSpkIds = [];
        $jumlahKirim = !empty($body['jumlah_kirim']) ? (int) $body['jumlah_kirim'] : null;

        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $raw) {
                $lineId = (int) ($raw['penjualan_detail_performa_id'] ?? 0);
                $qty = (int) ($raw['jumlah_kirim'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $line = PenjualanItemLookup::findLine($pdo, $lineId);
                if ($line === null) {
                    return $this->error($response, "Item produk #{$lineId} tidak ditemukan.");
                }
                if ($qty > $line['sisa']) {
                    return $this->error($response, "Jumlah kirim {$line['penjualan_jenis']} ({$qty}) melebihi sisa yang belum terkirim ({$line['sisa']}).");
                }
                $items[] = ['penjualan_detail_performa_id' => $lineId, 'jumlah_kirim' => $qty];
                $touchedSpkIds[$line['penjualan_id']] = true;
            }

            if (empty($items)) {
                return $this->error($response, 'Isi jumlah kirim minimal untuk 1 item produk.');
            }
            $jumlahKirim = array_sum(array_column($items, 'jumlah_kirim'));
        }

        if ($tripId === null && $driverTipe === 'internal') {
            $spkIds = array_keys($touchedSpkIds);
            $tripInsert = $pdo->prepare(
                "INSERT INTO ekspedisi_t_trip (driver_id, destination, penjualan_id, status, started_at)
                 VALUES (:driver_id, :destination, :penjualan_id, 'in_progress', :now)"
            );
            $tripInsert->execute([
                'driver_id' => $driverId,
                'destination' => $body['tujuan'] ?? null,
                // Cuma diisi kalau SJ ini nyentuh TEPAT 1 SPK -- kalau lintas
                // beberapa SPK sekaligus, tautan logis trip.penjualan_id
                // (singular) tidak cukup mewakili, biarkan NULL (SPK-nya
                // tetap lengkap tercatat per-item di ekspedisi_t_surat_jalan_item).
                'penjualan_id' => count($spkIds) === 1 ? $spkIds[0] : null,
                'now' => date('Y-m-d H:i:s'),
            ]);
            $tripId = (int) $pdo->lastInsertId();
        }

        $id = SuratJalan::create($pdo, [
            'trip_id' => $tripId,
            'driver_id' => $driverId,
            'tujuan' => $body['tujuan'] ?? null,
            'kendaraan' => $body['kendaraan'] ?? null,
            'plat' => $body['plat'] ?? null,
            'penerima' => $body['penerima'] ?? null,
            'jumlah_kirim' => $jumlahKirim,
            'tgl_kirim' => !empty($body['tgl_kirim']) ? $body['tgl_kirim'] : null,
            'catatan' => $body['catatan'] ?? null,
            'created_by' => (int) $request->getAttribute('user_id'),
            'items' => $items,
        ]);

        return $this->json($response, SuratJalan::find($pdo, $id), 201);
    }

    /**
     * POST /admin/sj/{id}/photo
     * multipart: photo (file) -- lampirkan/ganti foto SJ, dipakai form
     * "Buat Surat Jalan" (admin) supaya SJ manual juga bisa punya foto tanpa
     * lewat jalur checkpoint supir. Begitu terisi, status naik ke 'terkirim'
     * (lihat App\Support\SuratJalan::attachPhoto()).
     */
    public function uploadPhoto(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        if (!SuratJalan::find($pdo, $id)) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        $relativePath = $this->savePhoto($request, $id, 'bukti');
        if ($relativePath === null) {
            return $this->error($response, 'File photo wajib diunggah, maksimal 8MB.');
        }

        SuratJalan::attachPhoto($pdo, $id, $relativePath);

        return $this->json($response, SuratJalan::find($pdo, $id));
    }

    /**
     * POST /admin/sj/{id}/validasi
     * multipart: photo (file) -- ADMIN mengupload foto SJ fisik final (sudah
     * ditandatangani penerima, dibawa balik supir) sekaligus menandai
     * pengiriman ini tervalidasi. Ini langkah PENUTUP alur SJ -- beda dari
     * /photo (isi foto_surat_jalan, bukti lapangan), ini isi foto_validasi
     * + status 'tervalidasi' + siapa & kapan (lihat App\Support\SuratJalan::validate()).
     */
    public function validasi(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        $sj = SuratJalan::find($pdo, $id);
        if (!$sj) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }
        if ($sj['status'] === 'tervalidasi') {
            return $this->error($response, 'Surat jalan ini sudah tervalidasi.', 422);
        }

        $relativePath = $this->savePhoto($request, $id, 'validasi');
        if ($relativePath === null) {
            return $this->error($response, 'Foto SJ final wajib diunggah, maksimal 8MB.');
        }

        SuratJalan::validate($pdo, $id, $relativePath, (int) $request->getAttribute('user_id'));

        return $this->json($response, SuratJalan::find($pdo, $id));
    }

    /**
     * PUT /admin/sj/{id}
     * Lengkapi/koreksi field SJ (mis. yang auto-dibuat dari checkpoint foto
     * supir, biasanya minim data -- admin isi kendaraan/plat/jumlah_kirim belakangan).
     * body: { tujuan?, kendaraan?, plat?, penerima?, jumlah_kirim?, tgl_kirim?, catatan? }
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $pdo = Database::connection();
        $id = (int) $args['id'];

        if (!SuratJalan::find($pdo, $id)) {
            return $this->error($response, 'Surat jalan tidak ditemukan.', 404);
        }

        $body = (array) $request->getParsedBody();
        SuratJalan::update($pdo, $id, $body);

        return $this->json($response, SuratJalan::find($pdo, $id));
    }

    /**
     * Simpan file upload 'photo' dari multipart request ke
     * public/uploads/sj/{id}/, dipakai bareng oleh uploadPhoto() & validasi()
     * -- cuma beda `$kind` (nama file, jadi juga beda field mana yang
     * di-update di ekspedisi_t_surat_jalan) & konversi WEBP (lihat
     * App\Support\PhotoStorage). `$kind`: 'bukti' (foto_surat_jalan, checkpoint
     * lapangan) atau 'validasi' (foto_validasi, closing bertandatangan) --
     * disimpan sbg nama file berbeda supaya tidak saling menimpa DAN jelas
     * perannya cuma dari nama filenya di disk.
     * Return path relatif, atau null kalau tidak ada file/melebihi batas ukuran.
     */
    private function savePhoto(Request $request, int $id, string $kind): ?string
    {
        $dir = dirname(__DIR__, 2) . "/public/uploads/sj/{$id}";

        return PhotoStorage::save($request, 'photo', $dir, "uploads/sj/{$id}", $kind);
    }
}
