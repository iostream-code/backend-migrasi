<?php

declare(strict_types=1);

// Route table modul Partner -- port dari backend-production
// App\Http\Controllers\API\Partner\* (Route::prefix('partner'), routes/api.php),
// dipakai inventory-apk (src/pages/partner/partner.js). Prefix '/partner' SAMA
// dengan backend-production (sengaja, bukan konvensi baru) -- path-nya sendiri
// TIDAK berubah dari sana, cuma base host-nya yang pindah ke backend-migrasi.
//
// [BEDA dari backend-production] Di sana endpoint2 ini TIDAK ADA auth sama
// sekali (siapa saja yang tahu URL bisa baca/tulis). Di sini DIGERBANGI JWT
// (AuthMiddleware, sama seperti Ekspedisi/Inventory) -- keputusan sadar user
// saat porting (2026-08-22), BUKAN penemuan/asumsi otomatis. inventory-apk
// (src/lib/auth.js::initAuthInterceptor) sudah diperluas scope-nya supaya
// header Authorization ikut ter-attach ke path '/partner/' juga.
//
// TIDAK diport (lihat PartnerController.php docblock): get-partner-data,
// approve, add-payment, transaksi/{id}/status, delete, get-partner-summary --
// tidak dipanggil inventory-apk.

use App\Partner\Controllers\DeliveryController;
use App\Partner\Controllers\MaterialController;
use App\Partner\Controllers\PartnerController;
use App\Partner\Controllers\ReturController;
use App\Middleware\AuthMiddleware;
use Slim\App;

return function (App $app): void {
    $app->group('/partner', function ($mod) {
        $partner = new PartnerController();
        $material = new MaterialController();
        $delivery = new DeliveryController();
        $retur = new ReturController();

        $mod->get('', [$partner, 'index']);
        $mod->get('/', [$partner, 'index']);

        $mod->group('/material', function ($m) use ($material) {
            $m->post('', [$material, 'index']);
            $m->post('/', [$material, 'index']);
            $m->post('/add-partner-material', [$material, 'store']);
        });

        $mod->group('/delivery', function ($d) use ($delivery) {
            $d->post('', [$delivery, 'index']);
            $d->post('/', [$delivery, 'index']);
            $d->post('/add-delivery', [$delivery, 'store']);
        });

        $mod->group('/retur', function ($r) use ($retur) {
            $r->post('/input-retur', [$retur, 'inputRetur']);
            $r->post('/input-penerimaan-retur', [$retur, 'inputPenerimaanRetur']);
            $r->get('/get-retur-by-pengiriman/{idDetailPengiriman}', [$retur, 'byPengiriman']);
        });
    })->add(new AuthMiddleware());
};
