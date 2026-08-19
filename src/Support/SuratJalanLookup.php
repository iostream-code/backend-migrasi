<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Query READ-ONLY ke tabel surat_jalan milik backend-production (database
 * produksi yang sama) -- dipakai buat validasi & menampilkan info singkat
 * saat admin menautkan ekspedisi_t_trip.no_surat_jalan ke SJ asli. TIDAK PERNAH
 * menulis ke surat_jalan atau tabel manapun di luar ekspedisi_* dari sini.
 *
 * no_surat_jalan BUKAN kolom unik di surat_jalan -- 1 nomor SJ bisa punya
 * banyak baris (1 baris per item produk dalam pengiriman itu). Query ini
 * cuma ambil 1 baris representatif (kendaraan/plat/pengirim/tanggal sama
 * persis di semua baris utk 1 no_surat_jalan yang sama, lihat db_dump.sql).
 */
class SuratJalanLookup
{
    public static function find(PDO $pdo, string $noSuratJalan): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sj.no_surat_jalan, sj.tanggal, sj.kendaraan, sj.plat, sj.pengirim, sj.valid_cs,
                    h.penjualan_id, c.client_nama, c.client_alamat
             FROM surat_jalan sj
             JOIN t_penjualan_detail_performa p ON p.penjualan_detail_performa_id = sj.penjualan_detail_performa_id
             JOIN t_penjualan_header h ON h.penjualan_id = p.penjualan_id
             LEFT JOIN m_client c ON c.client_id = h.client_id
             WHERE sj.no_surat_jalan = :no_surat_jalan
             ORDER BY sj.id
             LIMIT 1'
        );
        $stmt->execute(['no_surat_jalan' => $noSuratJalan]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
