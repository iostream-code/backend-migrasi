<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Parse JSON body otomatis jadi array asosiatif (request multipart tetap
// ditangani PSR-7 lewat getUploadedFiles(), tidak lewat sini).
$app->addBodyParsingMiddleware();

$app->options('/{routes:.*}', function (Request $request, Response $response) {
    return $response;
});

$errorMiddleware = $app->addErrorMiddleware((bool) ($_ENV['APP_DEBUG'] ?? false), true, true);
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails
) use ($app): Response {
    $status = $exception instanceof \Slim\Exception\HttpException ? $exception->getCode() : 500;
    $response = $app->getResponseFactory()->createResponse($status ?: 500);
    $payload = ['message' => $exception->getMessage() ?: 'Terjadi kesalahan pada server.'];
    if ($displayErrorDetails) {
        $payload['exception'] = get_class($exception);
        $payload['trace'] = explode("\n", $exception->getTraceAsString());
    }
    $response->getBody()->write(json_encode($payload));
    return $response->withHeader('Content-Type', 'application/json');
});

// CORS sederhana: izinkan semua origin, TANPA kredensial (auth pakai header
// Authorization Bearer, bukan cookie -- jadi tidak butuh Allow-Credentials
// ataupun whitelist origin eksplisit seperti backend-production). SENGAJA
// ditambahkan SETELAH addErrorMiddleware() -- middleware Slim jalan LIFO
// (yang terakhir ditambahkan jadi PALING LUAR), jadi ini WAJIB paling
// terakhir supaya CORS membungkus error middleware juga. Kalau kebalik
// (seperti sebelumnya, CORS ditambah duluan), respons error (500/exception
// tak tertangkap dari route manapun) lolos tanpa header CORS sama sekali --
// browser laporannya jadi "blocked by CORS policy", padahal error aslinya
// beda (mis. tabel belum ada krn migration belum dijalankan) dan pesan
// aslinya jadi tersembunyi.
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
});

// bootstrap.php SEKARANG cuma composition root: bikin $app + middleware global
// (di atas), lalu mount route table tiap modul. Modul baru = tambah satu baris
// di sini + folder src/<NamaModul>/ baru (lihat src/Inventory/routes.php utk
// contoh skeleton modul kosong). Route TABLE-nya sendiri (endpoint per modul)
// hidup di src/<Modul>/routes.php masing-masing, BUKAN di sini -- supaya file
// ini tetap pendek & tidak ikut membengkak setiap ada modul baru.
(require __DIR__ . '/Ekspedisi/routes.php')($app);
(require __DIR__ . '/Inventory/routes.php')($app);
(require __DIR__ . '/Partner/routes.php')($app);
(require __DIR__ . '/Purchasing/routes.php')($app);

return $app;
