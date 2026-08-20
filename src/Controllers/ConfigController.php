<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cek versi app -- pola yang sama dipakai app lain di workspace ini
 * (absensi-apk, finance-apk, admin-finance-apk, dst): tabel `config`
 * (key-value, PK `config_id`) di database PRODUKSI YANG SAMA (backend-production
 * -- lihat db_dump.sql), dibaca READ-ONLY dari sini, TIDAK PERNAH ditulis.
 * Satu baris per app, `config_id` = 'VERSION_<NAMA_APP>_PUSAT' -- utk app ini
 * `VERSION_EKSPEDISI_PUSAT` (belum ada barisnya di produksi, WAJIB di-seed
 * manual dulu -- lihat README.md bagian "Cek versi app").
 *
 * Ikut konvensi TERBARU yang dipakai finance-apk/admin-finance-apk
 * (`API\Config\VersionController` di backend-production, bukan pola lama
 * `AbsenController::checkInternetAbsen()` yang exact-match string & digabung
 * sama urusan lain kayak ijin/last_login) -- `current_version_code` INTEGER
 * (Android versionCode, naik 1 tiap rilis) dibandingkan `>=` ke
 * `config_value_minimal`, BUKAN exact-match ke `config_value_string`. Lebih
 * fleksibel: admin bisa naikkan syarat minimal tanpa harus tahu versi
 * PERSIS yang beredar di device masing-masing.
 */
class ConfigController extends Controller
{
    private const CONFIG_ID = 'VERSION_EKSPEDISI_PUSAT';

    /**
     * POST /config/check-version
     * body: { current_version_code } -- integer, dari android-versionCode
     * config.xml (lihat src/js/app-version.js, auto-generate oleh
     * bump-version.js di ekspedisi-apk).
     * Return: { status: 'success', is_valid: bool, config: {...}|null }
     * `config` null kalau baris `VERSION_EKSPEDISI_PUSAT` belum di-seed --
     * `is_valid` tetap `true` (fail-open, bukan fail-closed) supaya admin
     * yang lupa setup config tidak sampai mengunci semua orang keluar app.
     */
    public function checkVersion(Request $request, Response $response): Response
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM config WHERE config_id = :config_id LIMIT 1');
        $stmt->execute(['config_id' => self::CONFIG_ID]);
        $config = $stmt->fetch();

        if (!$config) {
            return $this->json($response, ['status' => 'success', 'is_valid' => true, 'config' => null]);
        }

        $body = (array) $request->getParsedBody();
        $myVersion = (int) ($body['current_version_code'] ?? 0);
        $minVersion = (int) $config['config_value_minimal'];

        return $this->json($response, [
            'status' => 'success',
            'is_valid' => $myVersion >= $minVersion,
            'config' => $config,
        ]);
    }
}
