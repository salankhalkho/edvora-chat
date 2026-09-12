<?php

namespace App\Helpers;

use App\Config\Database;
use Throwable;

class AuditLogger
{
    public static function log(string $action, string $resourceType, ?int $resourceId = null, ?array $metadata = null): void
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO audit_logs (organization_id, user_id, action, resource_type, resource_id, metadata, ip_address, created_at)
                VALUES (:org_id, :user_id, :action, :res_type, :res_id, :metadata, :ip, NOW())
            ");

            $user = $GLOBALS['auth_user'] ?? null;
            $orgId = $GLOBALS['organization_id'] ?? ($user['organization_id'] ?? null);
            $userId = $user['id'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            $stmt->execute([
                ':org_id' => $orgId,
                ':user_id' => $userId,
                ':action' => $action,
                ':res_type' => $resourceType,
                ':res_id' => $resourceId,
                ':metadata' => $metadata ? json_encode($metadata) : null,
                ':ip' => $ip
            ]);
        } catch (Throwable $e) {
            // Audit logging should never break primary user action if database insert fails
            error_log("AuditLog error: " . $e->getMessage());
        }
    }
}
