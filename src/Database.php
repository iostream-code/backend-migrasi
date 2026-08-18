<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Wrapper PDO tipis -- sengaja TIDAK pakai ORM (Eloquent/dll). Semua query
 * ditulis manual (prepared statement) di masing-masing Controller, mengikuti
 * permintaan: ikuti apa adanya skema di db_dump.sql, tanpa lapisan migration/ORM.
 */
class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['DB_HOST'],
                $_ENV['DB_PORT'] ?? '3306',
                $_ENV['DB_DATABASE']
            );

            self::$instance = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Samakan dgn koneksi backend-production (config/database.php) --
                // paksa SET NAMES utf8mb4 supaya konsisten walau charset koneksi
                // klien PHP beda default.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);
        }

        return self::$instance;
    }
}
