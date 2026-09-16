<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;

class OrganizationController
{
    /**
     * GET /v1/organization/profile
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT o.*, p.name as plan_name
            FROM organizations o
            LEFT JOIN plans p ON o.plan_id = p.id
            WHERE o.id = :id
        ");
        $stmt->execute([':id' => $orgId]);
        $org = $stmt->fetch();

        if (!$org) {
            Response::error('Organization not found.', 404);
        }

        Response::success($org);
    }

    /**
     * PUT /v1/organization/profile
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $name = trim($data['name']);
        $shortName = isset($data['short_name']) ? trim($data['short_name']) : null;
        $websiteUrl = isset($data['website_url']) ? trim($data['website_url']) : null;
        $institutionType = isset($data['institution_type']) ? trim($data['institution_type']) : null;
        $institutionCategory = isset($data['institution_category']) ? trim($data['institution_category']) : null;
        $foundedYear = isset($data['founded_year']) ? trim($data['founded_year']) : null;
        $academicYear = isset($data['academic_year']) ? trim($data['academic_year']) : null;
        $addressLine = isset($data['address_line']) ? trim($data['address_line']) : null;
        $city = isset($data['city']) ? trim($data['city']) : null;
        $state = isset($data['state']) ? trim($data['state']) : null;
        $country = isset($data['country']) ? trim($data['country']) : 'India';
        $pincode = isset($data['pincode']) ? trim($data['pincode']) : null;
        $description = isset($data['institution_description']) ? trim($data['institution_description']) : null;
        $logoUrl = $data['logo_url'] ?? null;
        $primaryColor = $data['primary_color'] ?? '#2563EB';

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE organizations
            SET name = :name,
                short_name = :short_name,
                website_url = :website_url,
                institution_type = :institution_type,
                institution_category = :institution_category,
                founded_year = :founded_year,
                academic_year = :academic_year,
                address_line = :address_line,
                city = :city,
                state = :state,
                country = :country,
                pincode = :pincode,
                institution_description = :description,
                logo_url = :logo,
                primary_color = :color,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $name,
            ':short_name' => $shortName,
            ':website_url' => $websiteUrl,
            ':institution_type' => $institutionType,
            ':institution_category' => $institutionCategory,
            ':founded_year' => $foundedYear,
            ':academic_year' => $academicYear,
            ':address_line' => $addressLine,
            ':city' => $city,
            ':state' => $state,
            ':country' => $country,
            ':pincode' => $pincode,
            ':description' => $description,
            ':logo' => $logoUrl,
            ':color' => $primaryColor,
            ':id' => $orgId
        ]);

        // Also sync primary_color to chatbots table for consistency
        $stmtBot = $db->prepare("
            UPDATE chatbots
            SET primary_color = :color
            WHERE organization_id = :org_id
        ");
        $stmtBot->execute([':color' => $primaryColor, ':org_id' => $orgId]);

        AuditLogger::log('organization_profile_updated', 'organization', $orgId, [
            'name' => $name,
            'website_url' => $websiteUrl,
            'primary_color' => $primaryColor
        ]);

        Response::success([
            'id' => $orgId,
            'name' => $name,
            'short_name' => $shortName,
            'website_url' => $websiteUrl,
            'institution_type' => $institutionType,
            'institution_category' => $institutionCategory,
            'founded_year' => $foundedYear,
            'academic_year' => $academicYear,
            'address_line' => $addressLine,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'pincode' => $pincode,
            'institution_description' => $description,
            'logo_url' => $logoUrl,
            'primary_color' => $primaryColor
        ], 'Institution settings saved successfully');
    }

    /**
     * GET /v1/organization/staff — List all team members & admins for organization
     */
    public function indexStaff(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, COALESCE(u.can_manage_structure, 1) as can_manage_structure, u.created_at
            FROM users u
            WHERE u.organization_id = :org_id
            ORDER BY u.id ASC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $users = $stmt->fetchAll();

        // Attach assigned departments (deprecated, return empty array)
        foreach ($users as &$user) {
            $user['departments'] = [];
            $user['can_manage_structure'] = (int)$user['can_manage_structure'];
        }

        Response::success($users);
    }

    private function checkFullAdminPermission(): void
    {
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        if (!$authUserId) {
            Response::error('Unauthorized', 401);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT role, COALESCE(can_manage_structure, 1) as can_manage_structure FROM users WHERE id = ?");
        $stmt->execute([$authUserId]);
        $user = $stmt->fetch();

        $isFullAdmin = $user && (
            $user['role'] === 'owner' ||
            $user['role'] === 'superadmin' ||
            ($user['role'] === 'admin' && (int)$user['can_manage_structure'] === 1)
        );

        if (!$isFullAdmin) {
            Response::error('Forbidden: Only Full Administrators can onboard, edit, or delete team members.', 403);
        }
    }

    /**
     * POST /v1/organization/staff — Register new staff / team member for organization
     */
    public function createStaff(Request $request, array $params = []): void
    {
        $this->checkFullAdminPermission();

        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|min:6'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $name = trim($data['name']);
        $email = strtolower(trim($data['email']));
        $password = $data['password'];
        $inputRole = $data['role'] ?? 'team';

        $role = ($inputRole === 'admin' || $inputRole === 'owner') ? 'admin' : 'staff';
        $canManageStructure = isset($data['can_manage_structure']) ? (int)$data['can_manage_structure'] : ($role === 'admin' ? 1 : 0);

        $db = Database::getConnection();

        // Check duplicate email
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            Response::error('A user account with this email address already exists.', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmtIns = $db->prepare("
            INSERT INTO users (organization_id, name, email, password_hash, role, can_manage_structure, email_verified_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
        ");
        $stmtIns->execute([$orgId, $name, $email, $hash, $role, $canManageStructure]);
        $newUserId = (int)$db->lastInsertId();

        AuditLogger::log('staff_user_created', 'user', $newUserId, [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'can_manage_structure' => $canManageStructure
        ]);

        Response::success([
            'id' => $newUserId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'can_manage_structure' => $canManageStructure
        ], 'Team member account created successfully', 201);
    }

    /**
     * DELETE /v1/organization/staff/{id} — Delete team member
     */
    public function deleteStaff(Request $request, array $params = []): void
    {
        $this->checkFullAdminPermission();

        $orgId = $GLOBALS['organization_id'] ?? null;
        $userId = (int)($params['id'] ?? 0);

        if (!$orgId || !$userId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // Prevent self deletion
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        if ($authUserId && (int)$authUserId === $userId) {
            Response::error('You cannot delete your own logged-in administrator account.', 400);
        }

        $stmtDel = $db->prepare("DELETE FROM users WHERE id = :id AND organization_id = :org_id");
        $stmtDel->execute([':id' => $userId, ':org_id' => $orgId]);

        AuditLogger::log('staff_user_deleted', 'user', $userId, []);

        Response::success(['message' => 'Team member deleted successfully']);
    }

    /**
     * PUT /v1/organization/staff/{id} — Update team member details, role, privileges & departments
     */
    public function updateStaff(Request $request, array $params = []): void
    {
        $this->checkFullAdminPermission();

        $orgId = $GLOBALS['organization_id'] ?? null;
        $userId = (int)($params['id'] ?? 0);

        if (!$orgId || !$userId) {
            Response::error('Invalid request.', 400);
            return;
        }

        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
            return;
        }

        $name = trim($data['name']);
        $email = strtolower(trim($data['email']));
        $inputRole = $data['role'] ?? 'team';

        $role = ($inputRole === 'admin' || $inputRole === 'owner') ? 'admin' : 'staff';
        $canManageStructure = isset($data['can_manage_structure']) ? (int)$data['can_manage_structure'] : ($role === 'admin' ? 1 : 0);

        $db = Database::getConnection();

        // Check duplicate email for another user
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmtCheck->execute([$email, $userId]);
        if ($stmtCheck->fetch()) {
            Response::error('Another user account with this email address already exists.', 409);
            return;
        }

        // Verify user exists and belongs to this organization
        $stmtUser = $db->prepare("SELECT id, role FROM users WHERE id = ? AND organization_id = ?");
        $stmtUser->execute([$userId, $orgId]);
        $existingUser = $stmtUser->fetch();
        if (!$existingUser) {
            Response::error('User not found in organization.', 404);
            return;
        }

        // Update password if provided
        $password = trim($data['password'] ?? '');
        if (!empty($password)) {
            if (strlen($password) < 6) {
                Response::error('Password must be at least 6 characters.', 422);
                return;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmtUp = $db->prepare("
                UPDATE users 
                SET name = ?, email = ?, password_hash = ?, role = ?, can_manage_structure = ?, updated_at = NOW()
                WHERE id = ? AND organization_id = ?
            ");
            $stmtUp->execute([$name, $email, $hash, $role, $canManageStructure, $userId, $orgId]);
        } else {
            $stmtUp = $db->prepare("
                UPDATE users 
                SET name = ?, email = ?, role = ?, can_manage_structure = ?, updated_at = NOW()
                WHERE id = ? AND organization_id = ?
            ");
            $stmtUp->execute([$name, $email, $role, $canManageStructure, $userId, $orgId]);
        }

        AuditLogger::log('staff_user_updated', 'user', $userId, [
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'can_manage_structure' => $canManageStructure
        ]);

        Response::success([
            'id' => $userId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'can_manage_structure' => $canManageStructure
        ], 'Team member updated successfully');
    }

    /**
     * PUT /v1/organization/staff/{id}/password — Reset/Update password for a team member
     */
    public function updateStaffPassword(Request $request, array $params = []): void
    {
        $this->checkFullAdminPermission();

        $orgId = $GLOBALS['organization_id'] ?? null;
        $userId = (int)($params['id'] ?? 0);

        if (!$orgId || !$userId) {
            Response::error('Invalid request.', 400);
        }

        $data = $request->all();
        $password = trim($data['password'] ?? '');

        if (empty($password) || strlen($password) < 6) {
            Response::error('Password must be at least 6 characters.', 422);
        }

        $db = Database::getConnection();

        // Verify target user belongs to this organization
        $stmtCheck = $db->prepare("SELECT id, name, email FROM users WHERE id = :id AND organization_id = :org_id");
        $stmtCheck->execute([':id' => $userId, ':org_id' => $orgId]);
        $targetUser = $stmtCheck->fetch();

        if (!$targetUser) {
            Response::error('User not found in this organization.', 404);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmtUpd = $db->prepare("UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id AND organization_id = :org_id");
        $stmtUpd->execute([':hash' => $hash, ':id' => $userId, ':org_id' => $orgId]);

        AuditLogger::log('staff_password_reset', 'user', $userId, ['email' => $targetUser['email']]);

        Response::success(['message' => "Password for {$targetUser['name']} has been updated successfully."]);
    }

    /**
     * GET /v1/organization/languages — Get supported languages for organization
     */
    public function getLanguages(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT supported_languages FROM organizations WHERE id = :id");
        $stmt->execute([':id' => $orgId]);
        $row = $stmt->fetch();

        $defaultLanguages = [
            'en', 'es', 'zh', 'ar', 'fr', 'pt', 'hi', 'vi', 'ko', 'ja',
            'tr', 'id', 'de', 'it', 'ru', 'bn', 'ur', 'ne', 'th', 'ms',
            'fil', 'fa', 'uk', 'pl', 'nl'
        ];

        $languages = null;
        if ($row && !empty($row['supported_languages'])) {
            $decoded = json_decode($row['supported_languages'], true);
            if (is_array($decoded)) {
                $languages = $decoded;
            }
        }

        if ($languages === null) {
            $languages = $defaultLanguages;
        }

        Response::success([
            'languages' => $languages,
            'total_selected' => count($languages)
        ]);
    }

    /**
     * PUT /v1/organization/languages — Update supported languages for organization
     */
    public function updateLanguages(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();
        $languages = $data['languages'] ?? [];

        if (!is_array($languages)) {
            Response::error('Languages must be an array of language codes.', 422);
        }

        // Sanitize language codes
        $cleaned = [];
        foreach ($languages as $lang) {
            if (is_string($lang)) {
                $cleaned[] = strtolower(trim($lang));
            }
        }
        $cleaned = array_values(array_unique($cleaned));

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE organizations SET supported_languages = :languages, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':languages' => json_encode($cleaned),
            ':id' => $orgId
        ]);

        AuditLogger::log('organization_languages_updated', 'organization', $orgId, [
            'total_languages' => count($cleaned),
            'languages' => $cleaned
        ]);

        Response::success([
            'languages' => $cleaned,
            'total_selected' => count($cleaned)
        ], 'Multilingual preferences saved successfully.');
    }

    /**
     * GET /v1/organization/operating-hours — Retrieve institution-level desk hours & away automation
     */
    public function getOperatingHours(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_operating_hours WHERE organization_id = :org_id LIMIT 1");
        $stmt->execute([':org_id' => $orgId]);
        $record = $stmt->fetch();

        $defaultHours = [
            'mon' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'tue' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'wed' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'thu' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'fri' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'sat' => ['active' => true, 'open' => '09:00', 'close' => '18:00'],
            'sun' => ['active' => false, 'open' => '09:00', 'close' => '18:00'],
        ];

        if ($record) {
            $workingHours = $defaultHours;
            if (!empty($record['working_hours'])) {
                $decoded = is_string($record['working_hours']) ? json_decode($record['working_hours'], true) : $record['working_hours'];
                if (is_array($decoded)) {
                    $workingHours = array_merge($defaultHours, $decoded);
                }
            }
            Response::success([
                'id' => (int)$record['id'],
                'organization_id' => (int)$record['organization_id'],
                'timezone' => $record['timezone'] ?: 'America/New_York',
                'working_hours' => $workingHours,
                'auto_away_message' => $record['auto_away_message'] ?: 'Our Admissions & Support Desk is currently closed. Your inquiry has been received and prioritized. A counselor will respond promptly during our next operating shift.'
            ]);
            return;
        }

        Response::success([
            'id' => null,
            'organization_id' => (int)$orgId,
            'timezone' => 'America/New_York',
            'working_hours' => $defaultHours,
            'auto_away_message' => 'Our Admissions & Support Desk is currently closed. Your inquiry has been received and prioritized. A counselor will respond promptly during our next operating shift.'
        ]);
    }

    /**
     * PUT /v1/organization/operating-hours — Upsert institution-level desk hours & away automation
     */
    public function updateOperatingHours(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();
        $timezone = !empty($data['timezone']) ? trim($data['timezone']) : 'America/New_York';
        $workingHours = $data['working_hours'] ?? null;
        $autoAwayMessage = isset($data['auto_away_message']) ? trim($data['auto_away_message']) : null;

        $workingHoursJson = is_array($workingHours) ? json_encode($workingHours) : (is_string($workingHours) ? $workingHours : null);

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM organization_operating_hours WHERE organization_id = :org_id LIMIT 1");
        $stmtCheck->execute([':org_id' => $orgId]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $stmt = $db->prepare("
                UPDATE organization_operating_hours
                SET timezone = :timezone,
                    working_hours = :working_hours,
                    auto_away_message = :auto_away_message,
                    updated_at = NOW()
                WHERE organization_id = :org_id
            ");
            $stmt->execute([
                ':timezone' => $timezone,
                ':working_hours' => $workingHoursJson,
                ':auto_away_message' => $autoAwayMessage,
                ':org_id' => $orgId
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO organization_operating_hours
                (organization_id, timezone, working_hours, auto_away_message, created_at, updated_at)
                VALUES
                (:org_id, :timezone, :working_hours, :auto_away_message, NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':timezone' => $timezone,
                ':working_hours' => $workingHoursJson,
                ':auto_away_message' => $autoAwayMessage
            ]);
        }

        AuditLogger::log('organization_operating_hours_updated', 'organization', $orgId, [
            'timezone' => $timezone
        ]);

        $this->getOperatingHours($request, $params);
    }

    /**
     * GET /v1/organization/escalation-rules — Retrieve institution-level escalation rules & SLA
     */
    public function getEscalationRules(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_escalation_rules WHERE organization_id = :org_id LIMIT 1");
        $stmt->execute([':org_id' => $orgId]);
        $record = $stmt->fetch();

        if ($record) {
            Response::success([
                'id' => (int)$record['id'],
                'organization_id' => (int)$record['organization_id'],
                'lead_assignment_logic' => $record['lead_assignment_logic'] ?: 'round_robin',
                'sla_target_minutes' => (int)($record['sla_target_minutes'] ?: 8),
                'escalate_email' => (bool)$record['escalate_email'],
                'escalate_whatsapp' => (bool)$record['escalate_whatsapp'],
                'priority_channel' => $record['priority_channel'] ?: 'whatsapp_email'
            ]);
            return;
        }

        Response::success([
            'id' => null,
            'organization_id' => (int)$orgId,
            'lead_assignment_logic' => 'round_robin',
            'sla_target_minutes' => 8,
            'escalate_email' => true,
            'escalate_whatsapp' => true,
            'priority_channel' => 'whatsapp_email'
        ]);
    }

    /**
     * PUT /v1/organization/escalation-rules — Upsert institution-level escalation rules & SLA
     */
    public function updateEscalationRules(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();
        $leadAssignmentLogic = !empty($data['lead_assignment_logic']) ? trim($data['lead_assignment_logic']) : 'round_robin';
        $slaTargetMinutes = isset($data['sla_target_minutes']) ? max(1, (int)$data['sla_target_minutes']) : 8;
        $escalateEmail = isset($data['escalate_email']) ? ($data['escalate_email'] ? 1 : 0) : 1;
        $escalateWhatsapp = isset($data['escalate_whatsapp']) ? ($data['escalate_whatsapp'] ? 1 : 0) : 1;
        $priorityChannel = !empty($data['priority_channel']) ? trim($data['priority_channel']) : 'whatsapp_email';

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM organization_escalation_rules WHERE organization_id = :org_id LIMIT 1");
        $stmtCheck->execute([':org_id' => $orgId]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $stmt = $db->prepare("
                UPDATE organization_escalation_rules
                SET lead_assignment_logic = :lead_assignment_logic,
                    sla_target_minutes = :sla_target_minutes,
                    escalate_email = :escalate_email,
                    escalate_whatsapp = :escalate_whatsapp,
                    priority_channel = :priority_channel,
                    updated_at = NOW()
                WHERE organization_id = :org_id
            ");
            $stmt->execute([
                ':lead_assignment_logic' => $leadAssignmentLogic,
                ':sla_target_minutes' => $slaTargetMinutes,
                ':escalate_email' => $escalateEmail,
                ':escalate_whatsapp' => $escalateWhatsapp,
                ':priority_channel' => $priorityChannel,
                ':org_id' => $orgId
            ]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO organization_escalation_rules
                (organization_id, lead_assignment_logic, sla_target_minutes, escalate_email, escalate_whatsapp, priority_channel, created_at, updated_at)
                VALUES
                (:org_id, :lead_assignment_logic, :sla_target_minutes, :escalate_email, :escalate_whatsapp, :priority_channel, NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':lead_assignment_logic' => $leadAssignmentLogic,
                ':sla_target_minutes' => $slaTargetMinutes,
                ':escalate_email' => $escalateEmail,
                ':escalate_whatsapp' => $escalateWhatsapp,
                ':priority_channel' => $priorityChannel
            ]);
        }

        AuditLogger::log('organization_escalation_rules_updated', 'organization', $orgId, [
            'lead_assignment_logic' => $leadAssignmentLogic,
            'sla_target_minutes' => $slaTargetMinutes
        ]);

        $this->getEscalationRules($request, $params);
    }
}
