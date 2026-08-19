<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Pengajuan biaya admin ke finance utk 1 ekspedisi_t_trip (supir internal
 * maupun eksternal/ekspedisi). Tabel milik app ini sendiri (bukan tautan ke
 * backend-production) -- lihat database/09_create_ekspedisi_t_pengajuan_biaya.sql.
 */
class PengajuanBiaya
{
    public static function create(PDO $pdo, int $tripId, float $nominal, ?string $keterangan, int $createdBy): int
    {
        $insert = $pdo->prepare(
            "INSERT INTO ekspedisi_t_pengajuan_biaya (trip_id, nominal_diajukan, keterangan, status, created_by)
             VALUES (:trip_id, :nominal, :keterangan, 'diajukan', :created_by)"
        );
        $insert->execute([
            'trip_id' => $tripId,
            'nominal' => $nominal,
            'keterangan' => $keterangan,
            'created_by' => $createdBy,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public static function listForTrip(PDO $pdo, int $tripId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_t_pengajuan_biaya WHERE trip_id = :trip_id ORDER BY id DESC');
        $stmt->execute(['trip_id' => $tripId]);

        return $stmt->fetchAll();
    }
}
