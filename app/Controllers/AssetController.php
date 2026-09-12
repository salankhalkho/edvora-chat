<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;
use Throwable;

class AssetController
{
    private function getOrgId(Request $request): ?int
    {
        $orgId = $GLOBALS['organization_id'] ?? $GLOBALS['auth_user']['organization_id'] ?? $request->get('organization_id');
        return $orgId ? (int)$orgId : null;
    }

    /**
     * Get user permission context (isAdmin vs assigned department IDs)
     */
    private function getUserContext(PDO $db, ?int $userId, int $orgId): array
    {
        if (!$userId) {
            return ['is_admin' => false, 'assigned_dept_ids' => [], 'role' => 'guest'];
        }

        $stmt = $db->prepare("SELECT role FROM users WHERE id = ? AND organization_id = ?");
        $stmt->execute([$userId, $orgId]);
        $user = $stmt->fetch();
        $role = $user['role'] ?? 'staff';

        $isAdmin = in_array($role, ['owner', 'superadmin', 'super_admin', 'admin', 'org_admin']);

        $assignedDeptIds = [];
        if (!$isAdmin) {
            $stmtDept = $db->prepare("SELECT department_id FROM department_staff WHERE user_id = ?");
            $stmtDept->execute([$userId]);
            $assignedDeptIds = $stmtDept->fetchAll(PDO::FETCH_COLUMN);
            $assignedDeptIds = array_map('intval', $assignedDeptIds);
        }

        return [
            'is_admin' => $isAdmin,
            'assigned_dept_ids' => $assignedDeptIds,
            'role' => $role
        ];
    }

    /**
     * GET /v1/assets — List assets for organization
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required.', 400);
            return;
        }

        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $db = Database::getConnection();
        $userContext = $this->getUserContext($db, $authUserId, $orgId);

        $deptFilter = $request->get('department_id');
        $categoryFilter = $request->get('category');

        $query = "
            SELECT a.id, a.organization_id, a.department_id, a.title, a.category, a.description,
                   a.file_path, a.file_name, a.file_size_bytes, a.mime_type, a.lead_intent_trigger,
                   a.is_active, a.downloads_count, a.created_by, a.created_at, a.updated_at,
                   d.name as department_name, d.icon as department_icon,
                   u.name as creator_name
            FROM lead_assets a
            LEFT JOIN departments d ON a.department_id = d.id
            LEFT JOIN users u ON a.created_by = u.id
            WHERE a.organization_id = :org_id
        ";

        $binds = [':org_id' => $orgId];

        // If staff, limit to org-wide assets OR their assigned departments
        if (!$userContext['is_admin']) {
            if (empty($userContext['assigned_dept_ids'])) {
                $query .= " AND a.department_id IS NULL";
            } else {
                $inClause = implode(',', $userContext['assigned_dept_ids']);
                $query .= " AND (a.department_id IS NULL OR a.department_id IN ($inClause))";
            }
        }

        // Optional department filter
        if ($deptFilter !== null && $deptFilter !== '') {
            if ($deptFilter === 'org') {
                $query .= " AND a.department_id IS NULL";
            } else {
                $query .= " AND a.department_id = :dept_filter";
                $binds[':dept_filter'] = (int)$deptFilter;
            }
        }

        // Optional category filter
        if (!empty($categoryFilter)) {
            $query .= " AND a.category = :cat_filter";
            $binds[':cat_filter'] = $categoryFilter;
        }

        $query .= " ORDER BY a.id DESC";

        $stmt = $db->prepare($query);
        $stmt->execute($binds);
        $assets = $stmt->fetchAll();

        // Format and compute readable size
        $formatted = array_map(function ($a) {
            $bytes = (int)$a['file_size_bytes'];
            if ($bytes >= 1048576) {
                $sizeFormatted = number_format($bytes / 1048576, 2) . ' MB';
            } elseif ($bytes >= 1024) {
                $sizeFormatted = number_format($bytes / 1024, 1) . ' KB';
            } else {
                $sizeFormatted = $bytes . ' B';
            }
            $a['file_size_formatted'] = $sizeFormatted;
            $a['is_org_wide'] = empty($a['department_id']);
            return $a;
        }, $assets);

        Response::success([
            'assets' => $formatted,
            'user_context' => [
                'is_admin' => $userContext['is_admin'],
                'assigned_dept_ids' => $userContext['assigned_dept_ids'],
                'role' => $userContext['role']
            ]
        ]);
    }

    /**
     * POST /v1/assets/upload — Upload document asset
     */
    public function upload(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required.', 400);
            return;
        }

        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $db = Database::getConnection();
        $userContext = $this->getUserContext($db, $authUserId, $orgId);

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Please select a valid file to upload.', 400);
            return;
        }

        $file = $_FILES['file'];
        $originalFilename = basename($file['name']);
        $fileSize = (int)$file['size'];
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        $allowedExtensions = ['pdf', 'docx', 'doc', 'pptx', 'xlsx', 'zip', 'txt'];
        if (!in_array($extension, $allowedExtensions)) {
            Response::error('Invalid file type. Allowed: .pdf, .docx, .doc, .pptx, .xlsx, .zip, .txt', 422);
            return;
        }

        if ($fileSize > 25 * 1024 * 1024) {
            Response::error('File size exceeds the 25MB limit.', 422);
            return;
        }

        $title = trim($request->get('title') ?? pathinfo($originalFilename, PATHINFO_FILENAME));
        if (empty($title)) {
            Response::error('Asset title is required.', 422);
            return;
        }

        $category = $request->get('category') ?? 'brochure';
        $validCategories = ['brochure', 'fee_structure', 'scholarship_guide', 'placement_report', 'curriculum', 'hostel_guide', 'exam_cutoff', 'international_guide', 'other'];
        if (!in_array($category, $validCategories)) {
            $category = 'brochure';
        }

        $description = trim($request->get('description') ?? '');
        $leadIntentTrigger = trim($request->get('lead_intent_trigger') ?? '');

        // Department mapping validation
        $rawDeptId = $request->get('department_id');
        $deptId = (!empty($rawDeptId) && $rawDeptId !== 'org' && $rawDeptId !== '0') ? (int)$rawDeptId : null;

        // Permission check for Staff
        if (!$userContext['is_admin']) {
            if ($deptId === null) {
                Response::error('Staff members cannot upload Org-wide assets. Please select your assigned department.', 403);
                return;
            }
            if (!in_array($deptId, $userContext['assigned_dept_ids'])) {
                Response::error('You are not authorized to upload assets for this department.', 403);
                return;
            }
        }

        // Save file to storage/uploads/assets/
        $storageDir = dirname(__DIR__, 2) . '/storage/uploads/assets/';
        if (!file_exists($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $savedFilename = 'asset_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $storageDir . $savedFilename;
        $relativePath = 'storage/uploads/assets/' . $savedFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('Failed to save asset on server.', 500);
            return;
        }

        $mimeType = $file['type'] ?? 'application/octet-stream';
        if ($extension === 'pdf') $mimeType = 'application/pdf';

        try {
            $stmt = $db->prepare("
                INSERT INTO lead_assets (
                    organization_id, department_id, title, category, description,
                    file_path, file_name, file_size_bytes, mime_type,
                    lead_intent_trigger, is_active, created_by, created_at, updated_at
                ) VALUES (
                    :org_id, :dept_id, :title, :category, :description,
                    :file_path, :file_name, :file_size_bytes, :mime_type,
                    :lead_intent_trigger, 1, :created_by, NOW(), NOW()
                )
            ");

            $stmt->execute([
                ':org_id' => $orgId,
                ':dept_id' => $deptId,
                ':title' => $title,
                ':category' => $category,
                ':description' => $description,
                ':file_path' => $relativePath,
                ':file_name' => $originalFilename,
                ':file_size_bytes' => $fileSize,
                ':mime_type' => $mimeType,
                ':lead_intent_trigger' => $leadIntentTrigger,
                ':created_by' => $authUserId
            ]);

            $assetId = (int)$db->lastInsertId();

            AuditLogger::log('lead_asset_uploaded', 'lead_asset', $assetId, [
                'title' => $title,
                'category' => $category,
                'department_id' => $deptId,
                'file_name' => $originalFilename
            ]);

            Response::success([
                'id' => $assetId,
                'title' => $title,
                'category' => $category,
                'department_id' => $deptId,
                'file_name' => $originalFilename,
                'file_size_bytes' => $fileSize
            ], 'Asset uploaded successfully!', 201);

        } catch (Throwable $e) {
            // Clean up uploaded file if DB insert fails
            if (file_exists($targetPath)) {
                @unlink($targetPath);
            }
            Response::error('Failed to register asset: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/assets/{id} — View asset details
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        $assetId = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, d.name as department_name, d.icon as department_icon, u.name as creator_name
            FROM lead_assets a
            LEFT JOIN departments d ON a.department_id = d.id
            LEFT JOIN users u ON a.created_by = u.id
            WHERE a.id = :id AND a.organization_id = :org_id
        ");
        $stmt->execute([':id' => $assetId, ':org_id' => $orgId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            Response::error('Asset not found.', 404);
            return;
        }

        Response::success($asset);
    }

    /**
     * PUT /v1/assets/{id} — Update asset metadata & status
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        $assetId = (int)($params['id'] ?? 0);
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;

        $db = Database::getConnection();
        $userContext = $this->getUserContext($db, $authUserId, $orgId);

        // Fetch existing asset
        $stmt = $db->prepare("SELECT * FROM lead_assets WHERE id = ? AND organization_id = ?");
        $stmt->execute([$assetId, $orgId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Asset not found.', 404);
            return;
        }

        // Permission check
        if (!$userContext['is_admin']) {
            if (empty($existing['department_id']) || !in_array((int)$existing['department_id'], $userContext['assigned_dept_ids'])) {
                Response::error('You are not authorized to update this asset.', 403);
                return;
            }
        }

        $data = $request->all();
        $title = !empty($data['title']) ? trim($data['title']) : $existing['title'];
        $category = !empty($data['category']) ? $data['category'] : $existing['category'];
        $description = isset($data['description']) ? trim($data['description']) : $existing['description'];
        $leadIntentTrigger = isset($data['lead_intent_trigger']) ? trim($data['lead_intent_trigger']) : $existing['lead_intent_trigger'];
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : (int)$existing['is_active'];

        $deptId = $existing['department_id'];
        if ($userContext['is_admin'] && array_key_exists('department_id', $data)) {
            $rawDept = $data['department_id'];
            $deptId = (!empty($rawDept) && $rawDept !== 'org' && $rawDept !== '0') ? (int)$rawDept : null;
        }

        $stmtUpdate = $db->prepare("
            UPDATE lead_assets
            SET title = :title,
                category = :category,
                description = :description,
                lead_intent_trigger = :trigger,
                department_id = :dept_id,
                is_active = :is_active,
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");

        $stmtUpdate->execute([
            ':title' => $title,
            ':category' => $category,
            ':description' => $description,
            ':trigger' => $leadIntentTrigger,
            ':dept_id' => $deptId,
            ':is_active' => $isActive,
            ':id' => $assetId,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('lead_asset_updated', 'lead_asset', $assetId, ['title' => $title]);

        Response::success([
            'id' => $assetId,
            'title' => $title,
            'category' => $category,
            'department_id' => $deptId,
            'is_active' => $isActive
        ], 'Asset updated successfully.');
    }

    /**
     * DELETE /v1/assets/{id} — Delete asset
     */
    public function delete(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        $assetId = (int)($params['id'] ?? 0);
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;

        $db = Database::getConnection();
        $userContext = $this->getUserContext($db, $authUserId, $orgId);

        $stmt = $db->prepare("SELECT * FROM lead_assets WHERE id = ? AND organization_id = ?");
        $stmt->execute([$assetId, $orgId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Asset not found.', 404);
            return;
        }

        if (!$userContext['is_admin']) {
            if (empty($existing['department_id']) || !in_array((int)$existing['department_id'], $userContext['assigned_dept_ids'])) {
                Response::error('You are not authorized to delete this asset.', 403);
                return;
            }
        }

        // Delete physical file
        $absFilePath = dirname(__DIR__, 2) . '/' . $existing['file_path'];
        if (file_exists($absFilePath)) {
            @unlink($absFilePath);
        }

        $stmtDel = $db->prepare("DELETE FROM lead_assets WHERE id = ? AND organization_id = ?");
        $stmtDel->execute([$assetId, $orgId]);

        AuditLogger::log('lead_asset_deleted', 'lead_asset', $assetId, ['title' => $existing['title']]);

        Response::success([], 'Asset deleted successfully.');
    }

    /**
     * GET /v1/assets/{id}/download — Secure download with counter
     */
    public function download(Request $request, array $params = []): void
    {
        $assetId = (int)($params['id'] ?? 0);
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT * FROM lead_assets WHERE id = ?");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            http_response_code(404);
            echo "Asset not found.";
            exit;
        }

        $absPath = dirname(__DIR__, 2) . '/' . $asset['file_path'];
        if (!file_exists($absPath)) {
            http_response_code(404);
            echo "File not found on server.";
            exit;
        }

        // Increment download count
        try {
            $db->prepare("UPDATE lead_assets SET downloads_count = downloads_count + 1 WHERE id = ?")->execute([$assetId]);
        } catch (Throwable $e) {}

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($asset['mime_type'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes($asset['file_name']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($absPath));
        readfile($absPath);
        exit;
    }

    /**
     * POST /v1/assets/{id}/deliver — Public endpoint for widget to email asset to student
     */
    public function deliver(Request $request, array $params = []): void
    {
        $assetId = (int)($params['id'] ?? 0);
        $email = strtolower(trim((string)$request->get('email')));
        $visitorName = trim((string)$request->get('name'));

        if (empty($email)) {
            Response::error('Email address is required to deliver asset.', 422);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT a.*, o.name as org_name
            FROM lead_assets a
            JOIN organizations o ON a.organization_id = o.id
            WHERE a.id = ? AND a.is_active = 1
        ");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch();

        if (!$asset) {
            Response::error('Asset not found or inactive.', 404);
            return;
        }

        // Increment download/delivery count
        try {
            $db->prepare("UPDATE lead_assets SET downloads_count = downloads_count + 1 WHERE id = ?")->execute([$assetId]);
        } catch (Throwable $e) {}

        $sent = \App\Services\EmailService::sendAssetDelivery($email, $asset, $asset['org_name'] ?? 'College Admissions', $visitorName);

        $parts = explode('@', $email);
        $maskedUser = substr($parts[0], 0, 2) . str_repeat('*', max(3, strlen($parts[0]) - 2));
        $maskedEmail = $maskedUser . '@' . ($parts[1] ?? 'domain.com');

        Response::success([
            'delivered' => $sent,
            'asset_title' => $asset['title'],
            'email_masked' => $maskedEmail
        ], 'Asset delivery initiated.');
    }
}
