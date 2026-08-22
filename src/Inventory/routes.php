<?php

declare(strict_types=1);

// Route table modul Inventory (2026-08-21) -- migrasi bertahap dari
// backend-production (app/Http/Controllers/API/Inventory/*, prefix
// Route::prefix('inventory') di routes/api.php sana). Prefix '/inventory'
// SENGAJA dipakai (beda dari modul Ekspedisi yang path-nya flat tanpa
// prefix) supaya kedua modul tidak pernah bentrok path.
//
// Auth SUDAH REAL (login + resolusi role gudang) -- lihat
// App\Inventory\Controllers\AuthController. Material + Opname (2026-08-21)
// + Home Dashboard/Stock In/Stock Out (2026-08-22, subset yang dipakai FE
// saja -- lihat catatan di masing-masing Controller) SUDAH diport. Excel
// import/export, Manual Stock In/Out, retur/replacement BELUM, itu backlog
// (lihat inventory-apk/ROADMAP.md). /ping tetap dipertahankan sbg
// placeholder ringan pembuktian login+JWT+AuthMiddleware nyambung, tidak
// mengganggu apa pun kalau dihapus nanti.

use App\Inventory\Controllers\AuthController;
use App\Inventory\Controllers\ConfigController;
use App\Inventory\Controllers\HomeController;
use App\Inventory\Controllers\MaterialController;
use App\Inventory\Controllers\OpnameController;
use App\Inventory\Controllers\StockInController;
use App\Inventory\Controllers\StockOutController;
use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app): void {
    $app->group('/inventory', function ($group) {
        $auth = new AuthController();
        $config = new ConfigController();

        // Publik (di LUAR AuthMiddleware, sama seperti modul Ekspedisi) --
        // app perlu bisa cek versi SEBELUM/TANPA tergantung sesi valid.
        $group->post('/login', [$auth, 'login']);
        $group->post('/config/check-version', [$config, 'checkVersion']);

        $group->group('', function ($authed) use ($auth) {
            $authed->post('/logout', [$auth, 'logout']);

            $authed->get('/ping', function (Request $request, Response $response) {
                $payload = json_encode([
                    'module' => 'inventory',
                    'user_id' => $request->getAttribute('user_id'),
                    'role' => $request->getAttribute('role'),
                ]);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type', 'application/json');
            });

            $material = new MaterialController();
            $authed->group('/material', function ($m) use ($material) {
                $m->post('/get-materials', [$material, 'getMaterials']);
                $m->post('/get-units', [$material, 'getUnits']);
                $m->post('/get-categories', [$material, 'getCategories']);
                $m->post('/store-material', [$material, 'storeMaterial']);
                $m->post('/update-material', [$material, 'updateMaterial']);
                $m->post('/delete-material', [$material, 'deleteMaterial']);
            });

            $opname = new OpnameController();
            $authed->group('/opname', function ($o) use ($opname) {
                $o->post('/get-sessions', [$opname, 'getSessions']);
                $o->post('/get-session-detail', [$opname, 'getSessionDetail']);
                $o->post('/lookup-material', [$opname, 'lookupMaterial']);
                $o->post('/create-session', [$opname, 'createSession']);
                $o->post('/save-scan', [$opname, 'saveScan']);
                $o->post('/submit-session', [$opname, 'submitSession']);
                $o->post('/approve-session', [$opname, 'approveSession']);
                $o->post('/reject-session', [$opname, 'rejectSession']);
                $o->post('/delete-session', [$opname, 'deleteSession']);
            });

            $home = new HomeController();
            $authed->group('/home-dashboard', function ($h) use ($home) {
                $h->post('/get-dashboard', [$home, 'getDashboard']);
                $h->post('/get-material-detail', [$home, 'getMaterialDetail']);
                $h->post('/create-purchase-request', [$home, 'createPurchaseRequest']);
                $h->post('/list-purchase-request', [$home, 'listPurchaseRequest']);
            });

            $stockIn = new StockInController();
            $authed->group('/stock-in', function ($si) use ($stockIn) {
                $si->post('/get-stockin-active', [$stockIn, 'getStockInActive']);
                $si->post('/get-stockin-po-items', [$stockIn, 'getStockInPoItems']);
                $si->post('/get-stockin-po-detail', [$stockIn, 'getStockInPoDetail']);
                $si->post('/submit-stockin-receive', [$stockIn, 'submitStockInReceive']);
            });

            $stockOut = new StockOutController();
            $authed->group('/stock-out', function ($so) use ($stockOut) {
                $so->post('/get-stockout-active', [$stockOut, 'getStockOutActive']);
                $so->post('/get-stockout-req-items', [$stockOut, 'getStockOutReqItems']);
                $so->post('/get-stockout-req-detail', [$stockOut, 'getStockOutReqDetail']);
                $so->post('/submit-stockout', [$stockOut, 'submitStockOut']);
            });
        })->add(new AuthMiddleware());
    });
};
