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
    /**
     * $filters: status?, penjualan_id?, q? (cari no_surat_jalan/tujuan/penerima/
     * nama supir/nomor SPK yang disentuh -- lihat EXISTS ke item di bawah),
     * page? (default 1), per_page? (default 20, maks 100).
     * Return: { data, total, page, per_page } -- 2026-08-20, dulu array polos,
     * digantt krn tabel ini bisa py ribuan baris stlh migrate_legacy_surat_jalan.php,
     * fetch semua sekaligus tanpa batas jadi berat. total dipakai frontend
     * buat hitung jumlah halaman.
     */
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
        if (!empty($filters['q'])) {
            // Placeholder :q dipakai 6x di query yang sama -- PDO native prepares
            // (ATTR_EMULATE_PREPARES=false, lihat Database.php) TIDAK izinkan
            // named placeholder yang sama dipakai berkali-kali, jadi tiap
            // occurrence butuh nama beda meski nilainya sama persis.
            $where[] = "(sj.no_surat_jalan LIKE :q1 OR sj.tujuan LIKE :q2 OR sj.penerima LIKE :q3
                         OR u.nama_lengkap LIKE :q4 OR s.nama_eksternal LIKE :q5
                         OR EXISTS (
                             SELECT 1 FROM ekspedisi_t_surat_jalan_item sji
                             JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = sji.penjualan_detail_performa_id
                             WHERE sji.surat_jalan_id = sj.id AND pdp.penjualan_id LIKE :q6
                         ))";
            $qLike = '%' . $filters['q'] . '%';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $key) {
                $params[$key] = $qLike;
            }
        }

        $from = 'FROM ekspedisi_t_surat_jalan sj
                 LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
                 LEFT JOIN shared_m_users u ON u.user_id = s.user_id
                 LEFT JOIN shared_m_users v ON v.user_id = sj.divalidasi_oleh';
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) {$from} {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir, v.nama_lengkap AS nama_validator
             {$from} {$whereSql}
             ORDER BY sj.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['items'] = self::items($pdo, (int) $row['id']);
        }
        unset($row);

        return ['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT sj.*, COALESCE(u.nama_lengkap, s.nama_eksternal) AS nama_supir, v.nama_lengkap AS nama_validator
             FROM ekspedisi_t_surat_jalan sj
             LEFT JOIN ekspedisi_m_supir s ON s.id = sj.driver_id
             LEFT JOIN shared_m_users u ON u.user_id = s.user_id
             LEFT JOIN shared_m_users v ON v.user_id = sj.divalidasi_oleh
             WHERE sj.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['items'] = self::items($pdo, $id);

        return $row;
    }

    public static function findByTrip(PDO $pdo, int $tripId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ekspedisi_t_surat_jalan WHERE trip_id = :trip_id LIMIT 1');
        $stmt->execute(['trip_id' => $tripId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Breakdown per-lini produk dari 1 SJ (ekspedisi_t_surat_jalan_item),
     * di-JOIN ke t_penjualan_detail_performa (READ-ONLY, backend-production)
     * buat label produk (penjualan_jenis) DAN penjualan_id lini itu --
     * dipanggil find()/list() supaya GET /admin/sj sekalian bawa breakdown-nya.
     * penjualan_id per-item ini yang jadi sumber kebenaran "SJ ini menyentuh
     * SPK apa saja" (2026-08-20: 1 SJ boleh berisi lini produk dari BEBERAPA
     * SPK sekaligus -- kolom header ekspedisi_t_surat_jalan.penjualan_id
     * TIDAK CUKUP lagi buat itu, cuma dipakai jalur trip-linked lama yang
     * selalu 1 SPK per trip).
     */
    public static function items(PDO $pdo, int $suratJalanId): array
    {
        $stmt = $pdo->prepare(
            'SELECT i.id, i.penjualan_detail_performa_id, i.jumlah_kirim, pdp.penjualan_jenis, pdp.penjualan_id
             FROM ekspedisi_t_surat_jalan_item i
             LEFT JOIN t_penjualan_detail_performa pdp ON pdp.penjualan_detail_performa_id = i.penjualan_detail_performa_id
             WHERE i.surat_jalan_id = :surat_jalan_id
             ORDER BY i.id'
        );
        $stmt->execute(['surat_jalan_id' => $suratJalanId]);

        return $stmt->fetchAll();
    }

    /**
     * Dipanggil admin lewat POST /admin/sj -- bikin SJ manual, trip_id
     * boleh NULL (tidak terkait trip manapun). $data['items'] opsional --
     * array [{penjualan_detail_performa_id, jumlah_kirim}, ...], BOLEH berisi
     * lini produk dari beberapa SPK berbeda sekaligus (lihat
     * SuratJalanController::store(), yang sudah validasi sisa qty tiap item
     * SATU-SATU lewat App\Support\PenjualanItemLookup::findLine() sebelum
     * sampai sini -- makanya $data['penjualan_id'] SENGAJA tidak ada lagi di
     * sini, SPK-nya cuma bisa diketahui per-item lewat items()).
     */
    public static function create(PDO $pdo, array $data): int
    {
        $insert = $pdo->prepare(
            'INSERT INTO ekspedisi_t_surat_jalan
                (trip_id, penjualan_id, driver_id, tujuan, kendaraan, plat, penerima, jumlah_kirim, tgl_kirim, catatan, created_by)
             VALUES
                (:trip_id, :penjualan_id, :driver_id, :tujuan, :kendaraan, :plat, :penerima, :jumlah_kirim, :tgl_kirim, :catatan, :created_by)'
        );
        $insert->execute([
            'trip_id' => $data['trip_id'] ?? null,
            'penjualan_id' => $data['penjualan_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'tujuan' => $data['tujuan'] ?? null,
            'kendaraan' => $data['kendaraan'] ?? null,
            'plat' => $data['plat'] ?? null,
            'penerima' => $data['penerima'] ?? null,
            'jumlah_kirim' => $data['jumlah_kirim'] ?? null,
            'tgl_kirim' => $data['tgl_kirim'] ?? null,
            'catatan' => $data['catatan'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();

        if (!empty($data['items'])) {
            $itemInsert = $pdo->prepare(
                'INSERT INTO ekspedisi_t_surat_jalan_item (surat_jalan_id, penjualan_detail_performa_id, jumlah_kirim)
                 VALUES (:surat_jalan_id, :penjualan_detail_performa_id, :jumlah_kirim)'
            );
            foreach ($data['items'] as $item) {
                $itemInsert->execute([
                    'surat_jalan_id' => $id,
                    'penjualan_detail_performa_id' => $item['penjualan_detail_performa_id'],
                    'jumlah_kirim' => $item['jumlah_kirim'],
                ]);
            }
        }

        self::assignNomor($pdo, $id);

        return $id;
    }

    public static function update(PDO $pdo, int $id, array $data): void
    {
        $fields = ['tujuan', 'kendaraan', 'plat', 'penerima', 'jumlah_kirim', 'tgl_kirim', 'catatan'];
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
     * Dipanggil SuratJalanController::uploadPhoto() -- admin melampirkan foto
     * ke SJ yang dibuat manual (trip_id NULL, jadi tidak pernah lewat jalur
     * upsertFromTripPhoto()). Sama seperti checkpoint foto supir: begitu foto
     * terisi, status naik ke 'terkirim' -- KECUALI SJ ini sudah 'tervalidasi',
     * status akhir itu tidak boleh turun lagi cuma gara-gara ada foto baru.
     */
    public static function attachPhoto(PDO $pdo, int $id, string $photoPath): void
    {
        $pdo->prepare(
            "UPDATE ekspedisi_t_surat_jalan SET foto_surat_jalan = :path,
                status = IF(status = 'tervalidasi', status, 'terkirim') WHERE id = :id"
        )->execute(['path' => $photoPath, 'id' => $id]);
    }

    /**
     * Dipanggil SuratJalanController::validasi() -- ADMIN mengupload foto SJ
     * fisik final (sudah ditandatangani penerima, dibawa balik supir) sekaligus
     * menandai pengiriman ini tervalidasi. Beda dari attachPhoto()/
     * upsertFromTripPhoto() yang isi foto_surat_jalan (bukti lapangan) --
     * ini isi foto_validasi (bukti closing), status jadi 'tervalidasi', dan
     * dicatat siapa & kapan.
     */
    public static function validate(PDO $pdo, int $id, string $photoPath, int $userId): void
    {
        $pdo->prepare(
            "UPDATE ekspedisi_t_surat_jalan
             SET foto_validasi = :path, status = 'tervalidasi', divalidasi_oleh = :user_id, divalidasi_at = :now
             WHERE id = :id"
        )->execute([
            'path' => $photoPath,
            'user_id' => $userId,
            'now' => date('Y-m-d H:i:s'),
            'id' => $id,
        ]);
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
                "UPDATE ekspedisi_t_surat_jalan SET foto_surat_jalan = :path,
                    status = IF(status = 'tervalidasi', status, 'terkirim') WHERE id = :id"
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
