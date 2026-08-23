<?php

declare(strict_types=1);

namespace App\Ekspedisi\Support;

use PDO;

/**
 * Query READ-ONLY ke t_penjualan_header milik backend-production. TIDAK
 * PERNAH menulis ke t_penjualan_header atau tabel manapun di luar ekspedisi_*
 * dari sini.
 *
 * **Riwayat (2026-08-20):** sempat ada 2 varian daftar -- list() ("belum
 * diplot ke supir manapun", dipakai layar "Plot SPK ke Supir" di tab
 * Ekspedisi) dan listBelumSj() ("belum ada SJ sama sekali", dipakai tab SPK).
 * "Plot SPK ke Supir" DIHAPUS (tab Ekspedisi diputuskan jadi murni
 * monitoring supir yang sedang mengirim, bukan tempat assignment lagi --
 * assignment sekarang cukup lewat driver_id di POST /admin/sj, lihat
 * SuratJalanController::store()) -- list() ikut dihapus krn tidak dipanggil
 * lagi dari mana pun.
 *
 * **[DIHAPUS 2026-08-23] listBelumSj()** -- tab "SPK" (ekspedisi-apk) & route
 * GET /admin/spk-belum-sj yang memakainya sudah dihapus (app disederhanakan
 * jadi 2 halaman admin: SJ/Ekspedisi, lihat routes.php). Class ini
 * DIPERTAHANKAN cuma buat find() di bawah, masih dipakai
 * AdminController::createTrip() buat validasi `penjualan_id` opsional di
 * form "Perjalanan Baru". Riwayat listBelumSj() lengkap tetap ada di git log
 * kalau perlu ditelusuri lagi.
 */
class SpkReadyKirim
{
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
