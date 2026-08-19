<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Query READ-ONLY ke m_expedisi/m_expedisi_tarif milik backend-production
 * (database produksi yang sama) -- daftar perusahaan ekspedisi aktif & tarif
 * per rute, dipakai admin memilih ekspedisi saat menyerahkan SPK ke pihak
 * ketiga (lihat driver_t_ekspedisi). TIDAK PERNAH menulis ke tabel manapun
 * di luar driver_* dari sini.
 */
class ExpedisiLookup
{
    public static function listActive(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT id_expedisi, kode_expedisi, nama_expedisi, pic, no_telp
             FROM m_expedisi
             WHERE is_active = 1
             ORDER BY nama_expedisi"
        );

        return $stmt->fetchAll();
    }

    public static function find(PDO $pdo, int $idExpedisi): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id_expedisi, kode_expedisi, nama_expedisi, pic, no_telp
             FROM m_expedisi
             WHERE id_expedisi = :id AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $idExpedisi]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Tarif per kg utk 1 rute (kota_asal -> kota_tujuan) dari ekspedisi tertentu,
     * kalau ada. Dipakai buat perkiraan biaya_kirim -- bukan wajib, admin tetap
     * bisa isi biaya_kirim manual kalau rute belum py tarif master.
     */
    public static function tarif(PDO $pdo, int $idExpedisi, string $kotaAsal, string $kotaTujuan): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT harga_per_kg, estimasi_hari
             FROM m_expedisi_tarif
             WHERE id_expedisi = :id_expedisi AND kota_asal = :kota_asal AND kota_tujuan = :kota_tujuan
               AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute(['id_expedisi' => $idExpedisi, 'kota_asal' => $kotaAsal, 'kota_tujuan' => $kotaTujuan]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
