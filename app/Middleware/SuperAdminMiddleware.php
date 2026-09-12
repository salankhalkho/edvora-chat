<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, array $params = []): void
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!$user || ($user['role'] ?? '') !== 'superadmin') {
            Response::error('Forbidden. Super admin access required.', 403);
        }
    }
}
