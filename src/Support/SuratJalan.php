<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * CRUD ke ekspedisi_t_surat_jalan -- tabel MILIK app ini sendiri (bukan
 * tautan ke backend-production, beda dari SuratJalanLookup yang read-only ke
 * tabel surat_jalan lama). Dua jalur pengisian: otomatis dari checkpoint foto
 * "sj" (lihat upsertFromTripPhoto(), dipanggil DriverController::uploadPhoto())
 * dan manual dari admin (lihat SuratJalanController).
 */
class SuratJalan
{
    public static function list(PDO $pdo, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'sj.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['penjualan_id'])) {
            $where[] = 'sj.penjualan_id = :penjualan_id';
            $params['penjualan_id'] = $filters['penjualan_id'];
        }

        $sql = "SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir
                FROM ekspedisi_t_surat_jalan sj
                LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
                LEFT JOIN shared_m_users u ON u.user_id = s.user_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sj.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir
             FROM ekspedisi_t_surat_jalan sj
             LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
             LEFT JOIN shared_m_users u ON u.user_id = s.user_id
             WHERE sj.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function findByTrip(PDO $pdo, int $tripId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_t_surat_jalan WHERE trip_id = :trip_id LIMIT 1');
        $stmt->execute(['trip_id' => $tripId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Dipanggil admin lewat POST /admin/sj -- bikin SJ manual, trip_id
     * boleh NULL (tidak terkait trip manapun).
     */
    public static function create(PDO $pdo, array $data): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO ekspedisi_t_surat_jalan
                (trip_id, penjualan_id, driver_id, tujuan, kendaraan, plat, jumlah_kirim, catatan, created_by)
             VALUES
                (:trip_id, :penjualan_id, :driver_id, :tujuan, :kendaraan, :plat, :jumlah_kirim, :catatan, :created_by)'
        );
        $insert->execute([
            'trip_id' => $data['trip_id'] ?? null,
            'penjualan_id' => $data['penjualan_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'tujuan' => $data['tujuan'] ?? null,
            'kendaraan' => $data['kendaraan'] ?? null,
            'plat' => $data['plat'] ?? null,
            'jumlah_kirim' => $data['jumlah_kirim'] ?? null,
            'catatan' => $data['catatan'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();

        self::assignNomor($pdo, $id);

        return $id;
    }

    public static function update(PDO $pdo, int $id, array $data): void
    {
        $fields = ['tujuan', 'kendaraan', 'plat', 'jumlah_kirim', 'catatan'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $set[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        if (!$set) {
            return;
        }

        $pdo->prepare('UPDATE ekspedisi_t_surat_jalan SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);
    }

    /**
     * Dipanggil DriverController::uploadPhoto() saat type=sj -- upsert (by
     * trip_id) supaya re-upload checkpoint yang sama menimpa baris lama,
     * bukan bikin baris baru. driver_id/penjualan_id/tujuan diisi otomatis
     * dari data trip; admin bisa lengkapi kendaraan/plat/jumlah_kirim
     * belakangan lewat PUT /admin/sj/{id}.
     */
    public static function upsertFromTripPhoto(PDO $pdo, array $trip, int $driverId, string $photoPath): int
    {
        $existing = self::findByTrip($pdo, (int) $trip['id']);
        if ($existing) {
            $pdo->prepare(
                "UPDATE ekspedisi_t_surat_jalan SET foto_surat_jalan = :path, status = 'terkirim' WHERE id = :id"
            )->execute(['path' => $photoPath, 'id' => $existing['id']]);

            return (int) $existing['id'];
        }

        $insert = $pdo->prepare(
            "INSERT INTO ekspedisi_t_surat_jalan
                (trip_id, penjualan_id, driver_id, tujuan, foto_surat_jalan, status)
             VALUES
                (:trip_id, :penjualan_id, :driver_id, :tujuan, :foto, 'terkirim')"
        );
        $insert->execute([
            'trip_id' => $trip['id'],
            'penjualan_id' => $trip['penjualan_id'] ?? null,
            'driver_id' => $driverId,
            'tujuan' => $trip['destination'] ?? null,
            'foto' => $photoPath,
        ]);
        $id = (int) $pdo->lastInsertId();

        self::assignNomor($pdo, $id);

        return $id;
    }

    private static function assignNomor(PDO $pdo, int $id): void
    {
        $nomor = 'SJ-' . date('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE ekspedisi_t_surat_jalan SET no_surat_jalan = :nomor WHERE id = :id')
            ->execute(['nomor' => $nomor, 'id' => $id]);
    }
}
