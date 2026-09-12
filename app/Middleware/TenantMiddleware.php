<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class TenantMiddleware
{
    public function handle(Request $request, array $params = []): void
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!$user || empty($user['organization_id'])) {
            Response::error('Tenant context missing or invalid organization.', 403);
        }

        // Attach tenant organization_id to global request state
        $GLOBALS['organization_id'] = (int) $user['organization_id'];
    }
}
