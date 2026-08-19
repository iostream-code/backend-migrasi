<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DriverController;
use App\Database;
use App\Middleware\AdminOnlyMiddleware;
use App\Middleware\AuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

// Parse JSON body otomatis jadi array asosiatif (request multipart tetap
// ditangani PSR-7 lewat getUploadedFiles(), tidak lewat sini).
$app->addBodyParsingMiddleware();

// CORS sederhana: izinkan semua origin, TANPA kredensial (auth pakai header
// Authorization Bearer, bukan cookie -- jadi tidak butuh Allow-Credentials
// ataupun whitelist origin eksplisit seperti backend-production).
$app->add(function (Request $request, $handler): Response {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept');
});
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

$auth = new AuthController();
$driver = new DriverController();
$admin = new AdminController();

$app->post('/login', [$auth, 'login']);

$app->group('', function ($group) use ($auth, $driver, $admin) {
    $group->post('/logout', [$auth, 'logout']);

    // Dipertahankan untuk kompatibilitas kontrak lama (driver-apk versi awal
    // memanggil /driver/whoami terpisah setelah login) -- role & user sebenarnya
    // sudah dikembalikan langsung di response POST /login.
    $group->get('/driver/whoami', function (Request $request, Response $response) {
        $pdo = Database::connection();
        $userId = (int) $request->getAttribute('user_id');
        $role = (string) $request->getAttribute('role');

        $stmt = $pdo->prepare('SELECT nama_lengkap FROM shared_m_users WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $name = $stmt->fetchColumn();

        $id = $userId;
        if ($role === 'driver') {
            $id = \App\Support\SupirProfile::ensure($pdo, $userId);
        }

        $payload = json_encode(['role' => $role, 'user' => ['id' => $id, 'name' => $name]]);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    });

    // --- Supir ---
    $group->get('/driver/me', [$driver, 'me']);
    $group->post('/driver/status', [$driver, 'updateStatus']);
    $group->post('/driver/location', [$driver, 'storeLocation']);
    $group->get('/driver/trip/{trip}', [$driver, 'showTrip']);
    $group->post('/driver/trip/{trip}/photo', [$driver, 'uploadPhoto']);
    $group->post('/driver/trip/{trip}/complete', [$driver, 'completeTrip']);

    // --- Admin / Dispatcher (digerbangi AdminOnlyMiddleware juga) ---
    $group->group('', function ($adminGroup) use ($admin) {
        $adminGroup->get('/admin/drivers', [$admin, 'drivers']);
        $adminGroup->post('/admin/drivers', [$admin, 'createDriver']);
        $adminGroup->get('/admin/drivers/{driver}', [$admin, 'driverDetail']);
        $adminGroup->post('/admin/drivers/{driver}/trip', [$admin, 'createTrip']);
        $adminGroup->get('/admin/surat-jalan/{no}', [$admin, 'lookupSuratJalan']);
        $adminGroup->get('/admin/spk-ready-kirim', [$admin, 'spkReadyKirim']);
    })->add(new AdminOnlyMiddleware());
})->add(new AuthMiddleware());

return $app;
