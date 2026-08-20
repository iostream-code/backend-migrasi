<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Query READ-ONLY ke t_penjualan_detail_performa milik backend-production --
 * daftar lini produk dalam 1 SPK + hitung sisa qty yang belum terkirim.
 * TIDAK PERNAH menulis ke t_penjualan_detail_performa atau tabel manapun di
 * luar ekspedisi_* dari sini.
 *
 * "Sisa" dihitung dari DUA sumber sekaligus -- surat_jalan (tabel lama milik
 * backend-production, dipakai surat-jalan-apk dkk) DAN ekspedisi_t_surat_jalan_item
 * (tabel baru milik app ini) -- supaya tidak dobel kirim kalau 1 SPK yang
 * sama pernah/sedang dikirim lewat KEDUA app itu. Ini query READ-ONLY, tidak
 * pernah menulis balik ke surat_jalan.
 *
 * Sisi `ekspedisi_t_surat_jalan_item` SENGAJA cuma hitung baris dari SJ
 * ber-`asal='native'` (kolom `asal` di database/01_schema.sql &
 * migrate_legacy_surat_jalan.php) -- baris hasil migrasi data historis
 * (`asal='migrasi_legacy'`) sudah kehitung lewat sisi `surat_jalan` di atas
 * (itu justru SUMBER datanya sebelum dimigrasi), jadi kalau ikut dihitung
 * lagi di sini jadi DOBEL & sisa qty keliru (lebih kecil dari seharusnya).
 */
class PenjualanItemLookup
{
    private const BASE_SELECT = "SELECT pdp.penjualan_detail_performa_id, pdp.penjualan_id, pdp.penjualan_jenis, pdp.penjualan_qty,
                    COALESCE(legacy.total, 0) AS terkirim_legacy,
                    COALESCE(eks.total, 0) AS terkirim_ekspedisi
             FROM t_penjualan_detail_performa pdp
             LEFT JOIN (
                 SELECT penjualan_detail_performa_id, SUM(jumlah_kirim) AS total
                 FROM surat_jalan GROUP BY penjualan_detail_performa_id
             ) legacy ON legacy.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             LEFT JOIN (
                 SELECT sji.penjualan_detail_performa_id, SUM(sji.jumlah_kirim) AS total
                 FROM ekspedisi_t_surat_jalan_item sji
                 JOIN ekspedisi_t_surat_jalan sj ON sj.id = sji.surat_jalan_id
                 WHERE sj.asal = 'native'
                 GROUP BY sji.penjualan_detail_performa_id
             ) eks ON eks.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id";

    /**
     * Semua lini produk dalam 1 SPK -- dipakai frontend begitu admin "Cek"
     * nomor SPK di form Buat Surat Jalan.
     */
    public static function lines(PDO $pdo, string $penjualanId): array
    {
        $stmt = $pdo->prepare(self::BASE_SELECT . ' WHERE pdp.penjualan_id = :penjualan_id ORDER BY pdp.penjualan_detail_performa_id');
        $stmt->execute(['penjualan_id' => $penjualanId]);

        return array_map([self::class, 'format'], $stmt->fetchAll());
    }

    /**
     * Satu lini produk by id, TERLEPAS dari SPK mana asalnya -- dipakai
     * SuratJalanController::store() buat validasi ULANG tiap item saat
     * submit (1 SJ sekarang boleh berisi lini produk dari beberapa SPK
     * sekaligus, jadi validasi per-item, bukan per-SPK lagi seperti dulu).
     */
    public static function findLine(PDO $pdo, int $penjualanDetailPerformaId): ?array
    {
        $stmt = $pdo->prepare(self::BASE_SELECT . ' WHERE pdp.penjualan_detail_performa_id = :id LIMIT 1');
        $stmt->execute(['id' => $penjualanDetailPerformaId]);
        $row = $stmt->fetch();

        return $row ? self::format($row) : null;
    }

    private static function format(array $row): array
    {
        $terkirim = (int) $row['terkirim_legacy'] + (int) $row['terkirim_ekspedisi'];

        return [
            'penjualan_detail_performa_id' => (int) $row['penjualan_detail_performa_id'],
            'penjualan_id' => $row['penjualan_id'],
            'penjualan_jenis' => $row['penjualan_jenis'],
            'penjualan_qty' => (int) $row['penjualan_qty'],
            'terkirim' => $terkirim,
            'sisa' => max(0, (int) $row['penjualan_qty'] - $terkirim),
        ];
    }
}
