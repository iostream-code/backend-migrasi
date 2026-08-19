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
 * t_penjualan_header atau tabel manapun di luar driver_* dari sini.
 *
 * Query ini meniru App\Http\Controllers\API\Ekspedisi\EkspedisiController::
 * getSpkReadyKirim() di backend-production (endpoint itu sendiri TIDAK
 * dipanggil app manapun yang ter-checkout di workspace ini) -- versi di sini
 * disederhanakan (tanpa estimasi ukuran/berat, tidak perlu utk plotting
 * supir) dan filter tambahan: sudah py driver_t_trip ATAU driver_t_ekspedisi
 * dikecualikan juga (bukan cuma yang ada di t_pengiriman_detail, krn app ini
 * tidak pakai t_pengiriman sama sekali) -- satu SPK cuma boleh masuk salah
 * satu jalur (supir internal ATAU ekspedisi luar), tidak dua-duanya.
 */
class SpkReadyKirim
{
    public static function list(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT p.penjualan_id, p.no_spk, c.client_nama, p.lokasi_pabrik AS kota_asal,
                    k.nama_kota AS kota_tujuan, p.penjualan_tanggal_kirim, p.tgl_cs_deadline,
                    p.penjualan_total_qty
             FROM t_penjualan_header p
             JOIN m_client c ON c.client_id = p.client_id
             LEFT JOIN m_kota k ON k.id_kota = p.kode_kota
             WHERE p.penjualan_status = 'Aktif'
               AND p.shipment_status = 'approved'
               AND p.status_pengirman = 'belum_selesai'
               AND NOT EXISTS (
                   SELECT 1 FROM driver_t_trip t WHERE t.penjualan_id = p.penjualan_id
               )
               AND NOT EXISTS (
                   SELECT 1 FROM driver_t_ekspedisi e WHERE e.penjualan_id = p.penjualan_id AND e.status != 'batal'
               )
             ORDER BY p.penjualan_tanggal_kirim ASC"
        );

        return $stmt->fetchAll();
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
