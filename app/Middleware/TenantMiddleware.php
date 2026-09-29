<?php

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class TenantMiddleware
{
    public function handle(Request $request, array $params = []): void
    {
        $user = $GLOBALS['auth_user'] ?? null;
        if (!$user) {
            Response::error('Authentication required.', 401);
        }

        $orgId = !empty($user['organization_id']) ? (int)$user['organization_id'] : null;

        // Fallback 1: Check users table for organization_id
        if (!$orgId && !empty($user['user_id'])) {
            $db = \App\Config\Database::getConnection();
            $stmt = $db->prepare("SELECT organization_id, role FROM users WHERE id = :uid LIMIT 1");
            $stmt->execute([':uid' => (int)$user['user_id']]);
            $u = $stmt->fetch();
            if ($u && !empty($u['organization_id'])) {
                $orgId = (int)$u['organization_id'];
            }
        }

        // Fallback 2: Check institute_id parameter or X-Institute-Id header
        $instId = trim((string)($request->get('institute_id') ?: $request->getHeader('X-Institute-Id')));
        if (!$orgId && !empty($instId)) {
            $db = \App\Config\Database::getConnection();
            $stmtInst = $db->prepare("SELECT id FROM organizations WHERE institute_id = :iid1 OR id = :iid2 LIMIT 1");
            $stmtInst->execute([':iid1' => $instId, ':iid2' => $instId]);
            $inst = $stmtInst->fetch();
            if ($inst) {
                $orgId = (int)$inst['id'];
            }
        }

        // Fallback 3: Check explicit organization_id / org_id parameter
        $reqOrgId = (int)($request->get('organization_id') ?: $request->get('org_id'));
        if (!$orgId && $reqOrgId > 0) {
            $db = \App\Config\Database::getConnection();
            $stmtOrg = $db->prepare("SELECT id FROM organizations WHERE id = :id LIMIT 1");
            $stmtOrg->execute([':id' => $reqOrgId]);
            if ($stmtOrg->fetch()) {
                $orgId = $reqOrgId;
            }
        }

        // Fallback 4: If superadmin, allow fallback to first active organization
        if (!$orgId && !empty($user['role']) && ($user['role'] === 'superadmin' || $user['role'] === 'super_admin')) {
            $db = \App\Config\Database::getConnection();
            $firstOrg = $db->query("SELECT id FROM organizations ORDER BY id ASC LIMIT 1")->fetch();
            if ($firstOrg) {
                $orgId = (int)$firstOrg['id'];
            }
        }

        if (!$orgId) {
            Response::error('Tenant context missing or invalid organization.', 403);
        }

        // Attach tenant organization_id to global request state
        $GLOBALS['organization_id'] = $orgId;
    }
}
