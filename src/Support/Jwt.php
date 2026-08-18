<?php

declare(strict_types=1);

namespace App\Support;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;

/**
 * Token stateless (HS256) -- tidak ada tabel penyimpan token sama sekali,
 * server cukup verifikasi signature+expiry tiap request. Konsekuensi: token
 * tidak bisa di-revoke satu-satu sebelum expired (beda dari token opaque
 * tersimpan di DB); kalau butuh revoke paksa, ganti JWT_SECRET (mem-invalidate
 * SEMUA token yang beredar) atau tambah blacklist terpisah nanti kalau perlu.
 */
class Jwt
{
    public static function issue(int $userId, string $role): string
    {
        $now = time();
        $ttl = (int) ($_ENV['JWT_TTL_HOURS'] ?? 720) * 3600;

        $payload = [
            'sub' => $userId,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return FirebaseJwt::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }

    /**
     * @return array{sub:int,role:string,iat:int,exp:int}|null null kalau token invalid/expired.
     */
    public static function verify(string $token): ?array
    {
        try {
            $decoded = FirebaseJwt::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
            return (array) $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
