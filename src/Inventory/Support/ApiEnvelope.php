<?php

declare(strict_types=1);

namespace App\Inventory\Support;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Bentuk response Material/Opname sengaja MENIRU App\Helpers\ApiResponse di
 * backend-production ({status:1|0, message, data?, errors?}) -- BUKAN
 * json()/error() bawaan App\Controllers\Controller (bentuknya beda, dipakai
 * modul Ekspedisi). Alasan: field/response shape di sini dikutip apa adanya
 * dari controller asli supaya kompatibel kalau inventory-apk suatu saat
 * di-pointing ke sini; base Controller punya kontrak sendiri yang TIDAK
 * boleh diubah (dipakai Ekspedisi juga).
 */
trait ApiEnvelope
{
    protected function apiSuccess(Response $response, $data = null, string $message = 'OK', int $code = 200, array $extra = []): Response
    {
        $payload = ['status' => 1, 'message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        $payload = array_merge($payload, $extra);

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($code);
    }

    protected function apiError(Response $response, string $message = 'Terjadi kesalahan', int $code = 400, $errors = null): Response
    {
        $payload = ['status' => 0, 'message' => $message];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($code);
    }

    protected function apiNotFound(Response $response, string $message = 'Data tidak ditemukan'): Response
    {
        return $this->apiError($response, $message, 404);
    }
}
