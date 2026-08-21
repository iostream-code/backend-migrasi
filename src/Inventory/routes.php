<?php

declare(strict_types=1);

// Skeleton modul Inventory (2026-08-21) -- migrasi bertahap dari
// backend-production (app/Http/Controllers/API/Inventory/*, prefix
// Route::prefix('inventory') di routes/api.php sana). BELUM ada endpoint
// bisnis nyata di sini -- itu pekerjaan sesi lanjutan (lihat ROADMAP kalau
// sudah ada). Prefix '/inventory' SENGAJA dipakai (beda dari modul Ekspedisi
// yang path-nya flat tanpa prefix) supaya kedua modul tidak pernah
// bentrok path walau nanti nama endpoint kebetulan sama (mis. sama-sama
// mau pakai '/material').
//
// Auth: pakai AuthMiddleware yang SAMA (JWT generik, shared_m_users) seperti
// modul Ekspedisi -- tidak ada mekanisme login terpisah. Resolusi role
// (AdminGudang/StaffGudang dari jabatan+divisi, lihat inventory-apk/src/pages/
// login/login.js) BELUM dipindah ke sini, masih jadi tanggung jawab
// Controller/Middleware modul ini nanti, bukan di-generalize ke AuthMiddleware
// bersama (role Ekspedisi & Inventory beda bentuk -- lihat catatan di plan).

use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $app->group('/inventory', function ($group) {
        // Placeholder sementara -- bukti modul ke-mount & AuthMiddleware jalan,
        // GANTI/HAPUS begitu endpoint asli (Material/Opname/StockIn/StockOut/dst,
        // lihat backend-production API\Inventory\*Controller) mulai dipindah.
        $group->get('/ping', function (Request $request, Response $response) {
            $payload = json_encode([
                'module' => 'inventory',
                'user_id' => $request->getAttribute('user_id'),
                'role' => $request->getAttribute('role'),
            ]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        });
    })->add(new AuthMiddleware());
};
