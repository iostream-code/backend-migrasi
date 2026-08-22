<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Controllers\Controller;
use App\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Cek versi app -- pola & tabel SAMA PERSIS dgn App\Ekspedisi\Controllers\ConfigController
 * (tabel `config` di database produksi yang sama, dibaca READ-ONLY). config_id
 * = 'VERSION_INVENTORY_PUSAT' (2026-08-22, dikoreksi dari 'VERSION_INVENTORY' --
 * baris yg BENAR-BENAR ada di produksi pakai akhiran '_PUSAT', SUDAH di-seed
 * (config_value_minimal 1.00000 / config_value_string '1.00', cocok dgn
 * inventory-apk versi launching 1.0.0 / android-versionCode 1 di config.xml) --
 * dikonfirmasi langsung lewat query, bukan lagi "kemungkinan sudah ada".
 * Mapping app_name='inventory' di backend-production
 * (VersionController::$configIdMap) juga sudah dikoreksi ke config_id yg sama.
 */
class ConfigController extends Controller
{
    private const CONFIG_ID = 'VERSION_INVENTORY_PUSAT';

    /**
     * POST /inventory/config/check-version
     * body: { current_version_code } -- integer, android-versionCode (lihat
     * inventory-apk/src/lib/app-version.js, auto-generate oleh bump-version.cjs).
     * Return: { status: 'success', is_valid: bool, config: {...}|null }
     * `is_valid` fail-open (true) kalau baris config belum ada -- sama seperti
     * modul Ekspedisi, jangan sampai config kosong mengunci semua orang keluar.
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
