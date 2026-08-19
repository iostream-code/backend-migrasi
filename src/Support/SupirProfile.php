<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Helper kecil dipakai AuthController & DriverController -- ambil/buat baris
 * ekspedisi_m_supir milik user_id yang sedang login.
 */
class SupirProfile
{
    /**
     * Ambil id ekspedisi_m_supir milik user_id ini, buat otomatis kalau belum ada
     * (pertama kali user itu pakai app tracking).
     */
    public static function ensure(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('SELECT id FROM ekspedisi_m_supir WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $pdo->prepare('INSERT INTO ekspedisi_m_supir (user_id, driver_status) VALUES (:user_id, \'offline\')');
        $insert->execute(['user_id' => $userId]);
        return (int) $pdo->lastInsertId();
    }
}
