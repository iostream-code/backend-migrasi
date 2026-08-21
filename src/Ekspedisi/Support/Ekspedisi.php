<?php

declare(strict_types=1);

namespace App\Ekspedisi\Support;

use PDO;

/**
 * CRUD ekspedisi_m_ekspedisi -- master data perusahaan ekspedisi EKSTERNAL
 * (dipakai dropdown opsional "Perusahaan Ekspedisi" saat Tambah Supir
 * Eksternal, & sekarang bisa dikelola penuh -- create/update/nonaktifkan --
 * dari app ini sendiri). Tabel MILIK app ini, INDEPENDEN dari `m_expedisi`
 * (backend-production, yang sebenarnya sudah py CRUD sendiri tapi tidak
 * dipakai frontend manapun di workspace ini) -- lihat komentar lengkap di
 * database/01_schema.sql soal alasannya.
 */
class Ekspedisi
{
    /**
     * $includeInactive=false (default): cuma yang aktif, dipakai dropdown
     * "Perusahaan Ekspedisi". true: SEMUA (termasuk nonaktif), dipakai layar
     * kelola perusahaan ekspedisi.
     */
    public static function list(PDO $pdo, bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : 'WHERE is_active = 1';
        $stmt = $pdo->query("SELECT * FROM ekspedisi_m_ekspedisi {$where} ORDER BY nama_ekspedisi");

        return $stmt->fetchAll();
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_m_ekspedisi WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(PDO $pdo, array $data): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO ekspedisi_m_ekspedisi (kode_ekspedisi, nama_ekspedisi, pic, alamat, no_telp)
             VALUES (:kode_ekspedisi, :nama_ekspedisi, :pic, :alamat, :no_telp)'
        );
        $insert->execute([
            'kode_ekspedisi' => $data['kode_ekspedisi'] ?? null,
            'nama_ekspedisi' => $data['nama_ekspedisi'],
            'pic' => $data['pic'] ?? null,
            'alamat' => $data['alamat'] ?? null,
            'no_telp' => $data['no_telp'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Field opsional (update parsial) -- termasuk `is_active` buat
     * nonaktifkan/aktifkan lagi tanpa hapus baris (riwayat supir/trip yang
     * pernah ditautkan tetap utuh).
     */
    public static function update(PDO $pdo, int $id, array $data): void
    {
        $fields = ['kode_ekspedisi', 'nama_ekspedisi', 'pic', 'alamat', 'no_telp'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $set[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $set[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if (!$set) {
            return;
        }

        $pdo->prepare('UPDATE ekspedisi_m_ekspedisi SET ' . implode(', ', $set) . ' WHERE id = :id')
            ->execute($params);
    }
}
