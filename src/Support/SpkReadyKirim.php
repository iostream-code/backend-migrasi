<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Query READ-ONLY ke t_penjualan_header milik backend-production (database
 * produksi yang sama) -- daftar SPK yang SUDAH disetujui utk dikirim
 * (shipment_status='approved', lihat komentar panjang di
 * ServiceController::updateAlamatKirim() & approveShipment() di
 * backend-production utk alur lengkapnya) tapi belum selesai dikirim &
 * belum diplot ke supir manapun di app ini. TIDAK PERNAH menulis ke
 * t_penjualan_header atau tabel manapun di luar ekspedisi_* dari sini.
 *
 * Query ini meniru App\Http\Controllers\API\Ekspedisi\EkspedisiController::
 * getSpkReadyKirim() di backend-production (endpoint itu sendiri TIDAK
 * dipanggil app manapun yang ter-checkout di workspace ini) -- versi di sini
 * disederhanakan (tanpa estimasi ukuran/berat, tidak perlu utk plotting
 * supir).
 *
 * Dua varian daftar, beda kriteria "belum" (dipakai 2 layar beda di
 * ekspedisi-apk -- lihat komentar tiap method):
 * - list(): belum ada ekspedisi_t_trip -- dipakai layar "Plot SPK ke Supir"
 *   (tab Ekspedisi).
 * - listBelumSj(): belum ada ekspedisi_t_surat_jalan -- dipakai tab SPK
 *   (landing page admin) buat nunjukin SPK yang siap tapi belum dibikinkan
 *   SJ sama sekali.
 * Keduanya independen -- 1 SPK bisa saja sudah diplot ke supir (hilang dari
 * list()) tapi SJ-nya belum dibuat (masih muncul di listBelumSj()), atau
 * sebaliknya.
 */
class SpkReadyKirim
{
    private const BASE_WHERE = "p.penjualan_status = 'Aktif'
               AND p.shipment_status = 'approved'
               AND p.status_pengirman = 'belum_selesai'";

    /**
     * Belum diplot ke supir manapun (ekspedisi_t_trip.driver_id bisa nunjuk
     * ke supir internal MAUPUN eksternal, lihat ekspedisi_m_supir.tipe --
     * satu tabel, satu pengecualian, tidak ada tabel terpisah lagi utk
     * ekspedisi luar).
     */
    public static function list(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT p.penjualan_id, p.no_spk, c.client_nama, p.lokasi_pabrik AS kota_asal,
                    k.nama_kota AS kota_tujuan, p.penjualan_tanggal_kirim, p.tgl_cs_deadline,
                    p.penjualan_total_qty
             FROM t_penjualan_header p
             JOIN m_client c ON c.client_id = p.client_id
             LEFT JOIN m_kota k ON k.id_kota = p.kode_kota
             WHERE " . self::BASE_WHERE . "
               AND NOT EXISTS (
                   SELECT 1 FROM ekspedisi_t_trip t WHERE t.penjualan_id = p.penjualan_id
               )
             ORDER BY p.penjualan_tanggal_kirim ASC"
        );

        return $stmt->fetchAll();
    }

    /**
     * Belum ada SJ sama sekali -- dicek dari DUA jalur (2026-08-20: 1 SJ
     * sekarang boleh berisi lini produk dari BEBERAPA SPK sekaligus, jadi
     * `ekspedisi_t_surat_jalan.penjualan_id` header SENDIRIAN tidak lagi cukup
     * buat tahu SPK mana saja yang sudah tersentuh):
     * 1. `ekspedisi_t_surat_jalan.penjualan_id` -- jalur lama/trip-linked
     *    (upsertFromTripPhoto(), selalu 1 SPK per trip).
     * 2. `ekspedisi_t_surat_jalan_item` JOIN `t_penjualan_detail_performa` --
     *    jalur manual dgn breakdown per produk (SuratJalanController::store()),
     *    penjualan_id-nya cuma ada di level lini produk, bukan di header SJ.
     * Begitu SPK ini tersentuh SALAH SATU dari dua jalur itu (bahkan
     * sebagian), hilang dari daftar ini (sisa qty per lini produk yang belum
     * terkirim tetap kelihatan lewat "Cek SPK" di form Buat Surat Jalan --
     * lihat App\Support\PenjualanItemLookup).
     *
     * $search (2026-08-20, opsional) -- cocokkan ke nama client atau no SPK.
     * $page/$perPage -- LIMIT/OFFSET, di-cast int eksplisit sebelum
     * diinterpolasi ke SQL (bukan dari input mentah, aman dari injection),
     * PDO MySQL kadang rewel soal bind LIMIT/OFFSET sbg parameter bertipe.
     * Return: { data, total, page, per_page } -- total dipakai frontend buat
     * hitung jumlah halaman.
     */
    public static function listBelumSj(PDO $pdo, ?string $search = null, int $page = 1, int $perPage = 20): array
    {
        $where = self::BASE_WHERE . "
               AND NOT EXISTS (
                   SELECT 1 FROM ekspedisi_t_surat_jalan sj WHERE sj.penjualan_id = p.penjualan_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM ekspedisi_t_surat_jalan_item sji
                   JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = sji.penjualan_detail_performa_id
                   WHERE pdp.penjualan_id = p.penjualan_id
               )";
        $params = [];
        if ($search !== null && $search !== '') {
            // :q1/:q2 (bukan :q dipakai 2x) -- PDO native prepares
            // (ATTR_EMULATE_PREPARES=false) tidak izinkan named placeholder
            // yang sama dipakai berkali-kali dlm 1 query, lihat Database.php.
            $where .= ' AND (c.client_nama LIKE :q1 OR p.no_spk LIKE :q2)';
            $params['q1'] = "%{$search}%";
            $params['q2'] = "%{$search}%";
        }

        $from = 'FROM t_penjualan_header p
                 JOIN m_client c ON c.client_id = p.client_id
                 LEFT JOIN m_kota k ON k.id_kota = p.kode_kota';

        $countStmt = $pdo->prepare("SELECT COUNT(*) {$from} WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT p.penjualan_id, p.no_spk, c.client_nama, p.lokasi_pabrik AS kota_asal,
                    k.nama_kota AS kota_tujuan, p.penjualan_tanggal_kirim, p.tgl_cs_deadline,
                    p.penjualan_total_qty
             {$from}
             WHERE {$where}
             ORDER BY p.penjualan_tanggal_kirim ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return ['data' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function find(PDO $pdo, string $penjualanId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT p.penjualan_id, p.no_spk, c.client_nama, p.lokasi_pabrik AS kota_asal,
                    k.nama_kota AS kota_tujuan, p.penjualan_tanggal_kirim
             FROM t_penjualan_header p
             JOIN m_client c ON c.client_id = p.client_id
             LEFT JOIN m_kota k ON k.id_kota = p.kode_kota
             WHERE p.penjualan_id = :penjualan_id
             LIMIT 1'
        );
        $stmt->execute(['penjualan_id' => $penjualanId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
