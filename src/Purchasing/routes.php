<?php

declare(strict_types=1);

// Route table modul Purchasing -- port dari backend-production
// App\Http\Controllers\API\PurchasingController (3 method itu doang, TIDAK
// ada method lain di controller aslinya). Dipakai inventory-apk halaman Logo
// (src/pages/logo/logo.js). Di backend-production route-nya top-level tanpa
// prefix sama sekali (get-data-purchase, dst) -- di sini diprefix '/purchasing'
// (konvensi baru per-modul, sama pola dgn Inventory/Ekspedisi/Partner) supaya
// tidak pernah bentrok sama modul lain.
//
// Digerbangi JWT (AuthMiddleware) -- di backend-production TIDAK ada auth
// sama sekali di endpoint ini, keputusan sadar user saat porting (2026-08-22).

use App\Purchasing\Controllers\LogoController;
use App\Middleware\AuthMiddleware;
use Slim\App;

return function (App $app): void {
    $app->group('/purchasing', function ($mod) {
        $logo = new LogoController();
        $mod->post('/get-data-purchase', [$logo, 'getDataPurchase']);
        $mod->post('/update-file-foto-purchasing', [$logo, 'updateFileFotoPurchasing']);
        $mod->post('/update-file-resin-purchasing', [$logo, 'updateFileResinPurchasing']);
    })->add(new AuthMiddleware());
};
