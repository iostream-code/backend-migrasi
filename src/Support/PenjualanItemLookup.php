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
 */
class PenjualanItemLookup
{
    public static function lines(PDO $pdo, string $penjualanId): array
    {
        $stmt = $pdo->prepare(
            "SELECT pdp.penjualan_detail_performa_id, pdp.penjualan_jenis, pdp.penjualan_qty,
                    COALESCE(legacy.total, 0) AS terkirim_legacy,
                    COALESCE(eks.total, 0) AS terkirim_ekspedisi
             FROM t_penjualan_detail_performa pdp
             LEFT JOIN (
                 SELECT penjualan_detail_performa_id, SUM(jumlah_kirim) AS total
                 FROM surat_jalan GROUP BY penjualan_detail_performa_id
             ) legacy ON legacy.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             LEFT JOIN (
                 SELECT penjualan_detail_performa_id, SUM(jumlah_kirim) AS total
                 FROM ekspedisi_t_surat_jalan_item GROUP BY penjualan_detail_performa_id
             ) eks ON eks.penjualan_detail_performa_id = pdp.penjualan_detail_performa_id
             WHERE pdp.penjualan_id = :penjualan_id
             ORDER BY pdp.penjualan_detail_performa_id"
        );
        $stmt->execute(['penjualan_id' => $penjualanId]);

        return array_map(static function (array $row): array {
            $terkirim = (int) $row['terkirim_legacy'] + (int) $row['terkirim_ekspedisi'];

            return [
                'penjualan_detail_performa_id' => (int) $row['penjualan_detail_performa_id'],
                'penjualan_jenis' => $row['penjualan_jenis'],
                'penjualan_qty' => (int) $row['penjualan_qty'],
                'terkirim' => $terkirim,
                'sisa' => max(0, (int) $row['penjualan_qty'] - $terkirim),
            ];
        }, $stmt->fetchAll());
    }
}
