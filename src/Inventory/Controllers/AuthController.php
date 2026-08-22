<?php

declare(strict_types=1);

namespace App\Inventory\Controllers;

use App\Controllers\Controller;
use App\Database;
use App\Support\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Login modul Inventory -- HANYA pegawai divisi Gudang/Warehouse
 * (shared_m_divisi.divisi_id=8, kode='WH') yang boleh masuk, sama seperti
 * modul Ekspedisi: validasi terhadap shared_m_users (akun pegawai bersama,
 * database produksi yang sama dipakai backend-production), TIDAK ada akun
 * baru khusus modul ini.
 *
 * Role ditentukan dari jabatan (BUKAN dari tabel akses terpisah seperti
 * ekspedisi_m_admin_access -- gudang tidak punya whitelist admin manual,
 * cukup baca jabatan_id pegawai):
 *   - MANAJER atau SPV (Supervisor) -> 'AdminGudang'
 *   - STAFF                          -> 'StaffGudang'
 *   - jabatan lain (Owner/Direktur/Super Admin/Harian/Magang, dst)
 *     -> ditolak, bukan berarti "lebih tinggi dari Manajer jadi otomatis
 *     admin" -- daftar jabatan yang REALISTIS ada di roster Gudang cuma tiga
 *     itu, di luar itu dianggap salah divisi/jabatan, bukan role baru.
 *
 * [SEMENTARA, 2026-08-21] Manajer & SPV disamakan aksesnya (sama-sama
 * 'AdminGudang') atas permintaan -- kalau nanti Manajer perlu privilege
 * lebih (mis. approve yang SPV tidak boleh), pisahkan role-nya di sini,
 * BUKAN di frontend (frontend cuma percaya string role dari sini).
 */
class AuthController extends Controller
{
    private const DIVISI_GUDANG_ID = 8;
    private const DIVISI_GUDANG_KODE = 'WH';

    private const JABATAN_TO_ROLE = [
        'MANAJER' => 'AdminGudang',
        'SPV' => 'AdminGudang',
        'STAFF' => 'StaffGudang',
    ];

    /**
     * POST /inventory/login
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

        // LEFT JOIN (bukan INNER) sengaja -- kalau jabatan_id/divisi_id pegawai
        // NULL atau baris masternya kebetulan hilang, query tetap balikin baris
        // user-nya (supaya pesan errornya "jabatan/divisi tidak sesuai" yang
        // jelas, BUKAN "username tidak ditemukan" yang menyesatkan).
        $stmt = $pdo->prepare(
            'SELECT
                u.user_id, u.password_hash, u.nama_lengkap, u.status_pegawai,
                u.divisi_id, d.kode AS divisi_kode,
                u.jabatan_id, j.kode AS jabatan_kode,
                u.pabrik_id, p.nama_pabrik
             FROM shared_m_users u
             LEFT JOIN shared_m_divisi d ON d.divisi_id = u.divisi_id
             LEFT JOIN shared_m_jabatan j ON j.jabatan_id = u.jabatan_id
             LEFT JOIN shared_m_pabrik p ON p.pabrik_id = u.pabrik_id
             WHERE u.username = :username AND u.user_active = 1
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

        // divisi_id DAN kode dicek berdua -- divisi_id itu kebenaran otoritatif
        // (FK), kode cuma jaga-jaga kalau suatu saat baris master WH di-re-seed
        // dgn id beda (harusnya tidak pernah, tapi murah utk dicek).
        if ((int) $user['divisi_id'] !== self::DIVISI_GUDANG_ID || $user['divisi_kode'] !== self::DIVISI_GUDANG_KODE) {
            return $this->error($response, 'Akun ini bukan divisi Gudang/Warehouse, tidak bisa akses aplikasi ini.', 403);
        }

        $role = self::JABATAN_TO_ROLE[$user['jabatan_kode']] ?? null;
        if ($role === null) {
            return $this->error($response, 'Jabatan tidak sesuai untuk mengakses aplikasi ini.', 403);
        }

        $userId = (int) $user['user_id'];
        $token = Jwt::issue($userId, $role);

        return $this->json($response, [
            'token' => $token,
            'role' => $role,
            'user' => [
                'id' => $userId,
                'name' => $user['nama_lengkap'],
                'username' => $username,
                'jabatan' => $user['jabatan_kode'],
                'divisi' => $user['divisi_kode'],
                'pabrik' => $user['nama_pabrik'],
            ],
        ]);
    }

    /**
     * POST /inventory/logout
     * Token stateless (JWT) -- tidak ada yang perlu dihapus di server, sama
     * seperti modul Ekspedisi (lihat App\Ekspedisi\Controllers\AuthController).
     */
    public function logout(Request $request, Response $response): Response
    {
        return $this->json($response, ['ok' => true]);
    }
}
