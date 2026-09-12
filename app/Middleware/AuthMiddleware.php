<?php

namespace App\Middleware;

use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;

class AuthMiddleware
{
    public function handle(Request $request, array $params = []): void
    {
        $token = $request->getBearerToken();
        if (!$token) {
            Response::error('Authentication required. Missing Bearer token.', 401);
        }

        $payload = Jwt::decode($token);
        if (!$payload) {
            Response::error('Invalid or expired authentication token.', 401);
        }

        // Attach authenticated user details to global request state
        $GLOBALS['auth_user'] = $payload;
    }
}
