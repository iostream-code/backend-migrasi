<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Cek header Authorization: Bearer <token>, taruh user_id & role hasil decode
 * sebagai request attribute ('user_id', 'role') supaya Controller tinggal
 * $request->getAttribute('user_id') -- tidak perlu decode ulang.
 */
class AuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $this->unauthorized('Header Authorization tidak ada / salah format.');
        }

        $claims = Jwt::verify($m[1]);
        if ($claims === null) {
            return $this->unauthorized('Token tidak valid atau sudah kedaluwarsa.');
        }

        $request = $request
            ->withAttribute('user_id', (int) $claims['sub'])
            ->withAttribute('role', (string) $claims['role']);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = (new ResponseFactory())->createResponse(401);
        $response->getBody()->write(json_encode(['message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
