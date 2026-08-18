<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Dipasang SETELAH AuthMiddleware di route group /admin/* -- tolak 403 kalau
 * role di token bukan 'admin'. Server-side, bukan cuma dicek di frontend.
 */
class AdminOnlyMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute('role') !== 'admin') {
            $response = (new ResponseFactory())->createResponse(403);
            $response->getBody()->write(json_encode(['message' => 'Khusus admin/dispatcher.']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
