<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Jwt;
use App\Support\SupirProfile;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends Controller
{
    /**
     * POST /login
     * Validasi kredensial terhadap shared_m_users (tabel pegawai bersama, database
     * produksi yang sama dipakai backend-production) -- supir & admin pakai akun
     * pegawai yang sudah ada, tidak ada akun baru khusus app ini.
     */
    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->error($response, 'username dan password wajib diisi.');
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT user_id, password_hash, nama_lengkap, status_pegawai
             FROM shared_m_users
             WHERE username = :username AND user_active = 1
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            return $this->error($response, 'Username tidak ditemukan atau akun tidak aktif.', 401);
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->error($response, 'Password salah.', 401);
        }

        if ($user['status_pegawai'] !== 'AKTIF') {
            return $this->error($response, 'Status pegawai tidak aktif.', 403);
        }

        $userId = (int) $user['user_id'];
        $isAdmin = $this->isAdmin($pdo, $userId);

        $driverProfileId = null;
        if (!$isAdmin) {
            $driverProfileId = SupirProfile::ensure($pdo, $userId);
        }

        $token = Jwt::issue($userId, $isAdmin ? 'admin' : 'driver');

        return $this->json($response, [
            'token' => $token,
            'role' => $isAdmin ? 'admin' : 'driver',
            'user' => [
                'id' => $isAdmin ? $userId : $driverProfileId,
                'name' => $user['nama_lengkap'],
            ],
        ]);
    }

    /**
     * POST /logout
     * Token stateless (JWT) -- tidak ada yang perlu dihapus di server, endpoint
     * ini cuma dipertahankan untuk kompatibilitas kontrak frontend (fire-and-forget).
     */
    public function logout(Request $request, Response $response): Response
    {
        return $this->json($response, ['ok' => true]);
    }

    private function isAdmin(\PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM ekspedisi_m_admin_access WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}
