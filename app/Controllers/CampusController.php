<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;
use Throwable;

class CampusController
{
    /**
     * Check if current authenticated user has Full Admin access
     * (owner, superadmin, or admin with can_manage_structure = 1)
     */
    private function checkFullAdminAccess(PDO $db, int $userId): bool
    {
        $stmt = $db->prepare("SELECT role, COALESCE(can_manage_structure, 1) as can_manage_structure FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return false;
        }

        $role = $user['role'] ?? '';
        $canManage = (int)($user['can_manage_structure'] ?? 1);

        if ($role === 'owner' || $role === 'superadmin') {
            return true;
        }

        if (in_array($role, ['admin', 'org_admin']) && $canManage === 1) {
            return true;
        }

        return false;
    }

    /**
     * GET /v1/campuses
     * List all campuses for the current organization
     */
    public function index(Request $request): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;

        if (!$orgId || !$authUserId) {
            Response::error('Tenant context missing.', 403);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            $stmt = $db->prepare("
                SELECT id, organization_id, name, short_name, is_primary,
                       campus_area, virtual_tour_url, has_hostel,
                       contact_email, contact_phone, address_line, city, state,
                       country, pincode, status, created_at, updated_at
                FROM campuses
                WHERE organization_id = :org_id
                ORDER BY is_primary DESC, id ASC
            ");
            $stmt->execute([':org_id' => $orgId]);
            $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($campuses as &$c) {
                $cId = (int)$c['id'];
                $stmtCount = $db->prepare("SELECT COUNT(*) FROM campus_courses WHERE campus_id = ? AND organization_id = ?");
                $stmtCount->execute([$cId, $orgId]);
                $c['courses_count'] = (int)$stmtCount->fetchColumn();
                $c['derived_departments'] = [];
            }

            Response::success([
                'campuses' => $campuses,
                'can_manage' => $isFullAdmin,
                'total' => count($campuses)
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to load campuses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/campuses/{id}
     * Get single campus details
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $campusId = (int)($params['id'] ?? 0);

        if (!$orgId || !$authUserId || !$campusId) {
            Response::error('Invalid request.', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            $stmt = $db->prepare("
                SELECT id, organization_id, name, short_name, is_primary,
                       campus_area, virtual_tour_url, has_hostel,
                       contact_email, contact_phone, address_line, city, state,
                       country, pincode, status, created_at, updated_at
                FROM campuses
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([':id' => $campusId, ':org_id' => $orgId]);
            $campus = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campus) {
                Response::error('Campus not found.', 404);
                return;
            }

            $stmtCount = $db->prepare("SELECT COUNT(*) FROM campus_courses WHERE campus_id = ? AND organization_id = ?");
            $stmtCount->execute([$campusId, $orgId]);
            $campus['courses_count'] = (int)$stmtCount->fetchColumn();
            $campus['derived_departments'] = [];

            Response::success([
                'campus' => $campus,
                'can_manage' => $isFullAdmin
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve campus: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/campuses
     * Create a new campus (Admin full access required)
     */
    public function store(Request $request): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;

        if (!$orgId || !$authUserId) {
            Response::error('Tenant context missing.', 403);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            if (!$isFullAdmin) {
                Response::error('Access restricted: Only Admin (full access) can create campuses.', 403);
                return;
            }

            $data = $request->all();
            $name = trim($data['name'] ?? '');
            if ($name === '') {
                Response::error('Campus name is required.', 422);
                return;
            }

            $shortName = trim($data['short_name'] ?? '');
            $isPrimary = !empty($data['is_primary']) ? 1 : 0;
            $campusArea = trim($data['campus_area'] ?? '');
            $virtualTourUrl = trim($data['virtual_tour_url'] ?? '');
            $hasHostel = !empty($data['has_hostel']) ? 1 : 0;
            $contactEmail = trim($data['contact_email'] ?? '');
            $contactPhone = trim($data['contact_phone'] ?? '');
            $addressLine = trim($data['address_line'] ?? '');
            $city = trim($data['city'] ?? '');
            $state = trim($data['state'] ?? '');
            $country = trim($data['country'] ?? 'India');
            $pincode = trim($data['pincode'] ?? '');
            $status = (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) ? $data['status'] : 'active';

            // If marked as primary, demote any previous primary campus for this organization
            if ($isPrimary === 1) {
                $demoteStmt = $db->prepare("UPDATE campuses SET is_primary = 0 WHERE organization_id = :org_id");
                $demoteStmt->execute([':org_id' => $orgId]);
            } else {
                // If this is the first campus, automatically set it as primary
                $checkFirst = $db->prepare("SELECT COUNT(*) FROM campuses WHERE organization_id = :org_id");
                $checkFirst->execute([':org_id' => $orgId]);
                if ((int)$checkFirst->fetchColumn() === 0) {
                    $isPrimary = 1;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO campuses (
                    organization_id, name, short_name, is_primary,
                    campus_area, virtual_tour_url, has_hostel,
                    contact_email, contact_phone, address_line, city, state,
                    country, pincode, status
                ) VALUES (
                    :org_id, :name, :short_name, :is_primary,
                    :campus_area, :virtual_tour_url, :has_hostel,
                    :contact_email, :contact_phone, :address_line, :city, :state,
                    :country, :pincode, :status
                )
            ");

            $stmt->execute([
                ':org_id' => $orgId,
                ':name' => $name,
                ':short_name' => $shortName,
                ':is_primary' => $isPrimary,
                ':campus_area' => $campusArea,
                ':virtual_tour_url' => $virtualTourUrl,
                ':has_hostel' => $hasHostel,
                ':contact_email' => $contactEmail,
                ':contact_phone' => $contactPhone,
                ':address_line' => $addressLine,
                ':city' => $city,
                ':state' => $state,
                ':country' => $country,
                ':pincode' => $pincode,
                ':status' => $status
            ]);

            $newId = (int)$db->lastInsertId();

            AuditLogger::log('create', 'campus', $newId, ['name' => $name]);

            Response::success([
                'id' => $newId,
                'message' => 'Campus created successfully'
            ], 201);
        } catch (Throwable $e) {
            Response::error('Failed to create campus: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /v1/campuses/{id}
     * Update an existing campus (Admin full access required)
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $campusId = (int)($params['id'] ?? 0);

        if (!$orgId || !$authUserId || !$campusId) {
            Response::error('Invalid request.', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            if (!$isFullAdmin) {
                Response::error('Access restricted: Only Admin (full access) can edit campuses.', 403);
                return;
            }

            // Verify campus belongs to tenant
            $stmtCheck = $db->prepare("SELECT id, is_primary, name FROM campuses WHERE id = :id AND organization_id = :org_id");
            $stmtCheck->execute([':id' => $campusId, ':org_id' => $orgId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                Response::error('Campus not found.', 404);
                return;
            }

            $data = $request->all();
            $name = trim($data['name'] ?? '');
            if ($name === '') {
                Response::error('Campus name is required.', 422);
                return;
            }

            $shortName = trim($data['short_name'] ?? '');
            $isPrimary = isset($data['is_primary']) ? (!empty($data['is_primary']) ? 1 : 0) : (int)$existing['is_primary'];
            $campusArea = trim($data['campus_area'] ?? '');
            $virtualTourUrl = trim($data['virtual_tour_url'] ?? '');
            $hasHostel = !empty($data['has_hostel']) ? 1 : 0;
            $contactEmail = trim($data['contact_email'] ?? '');
            $contactPhone = trim($data['contact_phone'] ?? '');
            $addressLine = trim($data['address_line'] ?? '');
            $city = trim($data['city'] ?? '');
            $state = trim($data['state'] ?? '');
            $country = trim($data['country'] ?? 'India');
            $pincode = trim($data['pincode'] ?? '');
            $status = (isset($data['status']) && in_array($data['status'], ['active', 'inactive'])) ? $data['status'] : 'active';

            if ($isPrimary === 1) {
                $demoteStmt = $db->prepare("UPDATE campuses SET is_primary = 0 WHERE organization_id = :org_id AND id != :id");
                $demoteStmt->execute([':org_id' => $orgId, ':id' => $campusId]);
            }

            $stmt = $db->prepare("
                UPDATE campuses SET
                    name = :name,
                    short_name = :short_name,
                    is_primary = :is_primary,
                    campus_area = :campus_area,
                    virtual_tour_url = :virtual_tour_url,
                    has_hostel = :has_hostel,
                    contact_email = :contact_email,
                    contact_phone = :contact_phone,
                    address_line = :address_line,
                    city = :city,
                    state = :state,
                    country = :country,
                    pincode = :pincode,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND organization_id = :org_id
            ");

            $stmt->execute([
                ':id' => $campusId,
                ':org_id' => $orgId,
                ':name' => $name,
                ':short_name' => $shortName,
                ':is_primary' => $isPrimary,
                ':campus_area' => $campusArea,
                ':virtual_tour_url' => $virtualTourUrl,
                ':has_hostel' => $hasHostel,
                ':contact_email' => $contactEmail,
                ':contact_phone' => $contactPhone,
                ':address_line' => $addressLine,
                ':city' => $city,
                ':state' => $state,
                ':country' => $country,
                ':pincode' => $pincode,
                ':status' => $status
            ]);

            AuditLogger::log('update', 'campus', $campusId, ['name' => $name]);

            Response::success([
                'message' => 'Campus updated successfully'
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to update campus: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /v1/campuses/{id}
     * Delete a campus (Admin full access required)
     */
    public function delete(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $campusId = (int)($params['id'] ?? 0);

        if (!$orgId || !$authUserId || !$campusId) {
            Response::error('Invalid request.', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            if (!$isFullAdmin) {
                Response::error('Access restricted: Only Admin (full access) can delete campuses.', 403);
                return;
            }

            // Verify campus belongs to tenant
            $stmtCheck = $db->prepare("SELECT id, name, is_primary FROM campuses WHERE id = :id AND organization_id = :org_id");
            $stmtCheck->execute([':id' => $campusId, ':org_id' => $orgId]);
            $campus = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$campus) {
                Response::error('Campus not found.', 404);
                return;
            }

            // Check total count to prevent deleting only campus
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM campuses WHERE organization_id = :org_id");
            $stmtCount->execute([':org_id' => $orgId]);
            $totalCampuses = (int)$stmtCount->fetchColumn();

            if ($totalCampuses <= 1) {
                Response::error('Cannot delete the only remaining campus for this institution.', 422);
                return;
            }

            // If deleting primary, designate another one as primary
            if ((int)$campus['is_primary'] === 1) {
                $nextPrimary = $db->prepare("
                    UPDATE campuses 
                    SET is_primary = 1 
                    WHERE organization_id = :org_id AND id != :id 
                    ORDER BY id ASC LIMIT 1
                ");
                $nextPrimary->execute([':org_id' => $orgId, ':id' => $campusId]);
            }

            $stmtDel = $db->prepare("DELETE FROM campuses WHERE id = :id AND organization_id = :org_id");
            $stmtDel->execute([':id' => $campusId, ':org_id' => $orgId]);

            AuditLogger::log('delete', 'campus', $campusId, ['name' => $campus['name']]);

            Response::success([
                'message' => 'Campus deleted successfully'
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to delete campus: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/campuses/{id}/courses
     * Get all organization courses grouped by department, with is_offered flag for this campus,
     * and derived active departments list.
     */
    public function courses(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $campusId = (int)($params['id'] ?? 0);

        if (!$orgId || !$campusId) {
            Response::error('Invalid request.', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Verify campus belongs to tenant
            $stmtCampus = $db->prepare("SELECT id, name, short_name, is_primary, status FROM campuses WHERE id = ? AND organization_id = ?");
            $stmtCampus->execute([$campusId, $orgId]);
            $campus = $stmtCampus->fetch(PDO::FETCH_ASSOC);

            if (!$campus) {
                Response::error('Campus not found.', 404);
                return;
            }

            // Get currently mapped course IDs for this campus
            $stmtMapped = $db->prepare("SELECT course_id FROM campus_courses WHERE campus_id = ? AND organization_id = ?");
            $stmtMapped->execute([$campusId, $orgId]);
            $mappedCourseIds = $stmtMapped->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $mappedCourseIds = array_map('intval', $mappedCourseIds);
            $mappedCourseIdsMap = array_flip($mappedCourseIds);

            // Fetch all programs for this organization
            $stmtProg = $db->prepare("
                SELECT id, course_name, course_code, program_type, duration, mode, sort_order
                FROM programs 
                WHERE organization_id = ?
                ORDER BY sort_order ASC, course_name ASC
            ");
            $stmtProg->execute([$orgId]);
            $programs = $stmtProg->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $totalOffered = 0;
            foreach ($programs as &$p) {
                $pId = (int)$p['id'];
                $isOffered = isset($mappedCourseIdsMap[$pId]);
                if ($isOffered) {
                    $totalOffered++;
                }
                $p['id'] = $pId;
                $p['is_offered'] = $isOffered;
            }
            unset($p);

            Response::success([
                'campus' => $campus,
                'programs' => $programs,
                'departments' => [],
                'derived_departments' => [],
                'total_offered_courses' => $totalOffered,
                'mapped_course_ids' => $mappedCourseIds
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to load campus courses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/campuses/{id}/courses
     * Sync courses offered at this campus
     */
    public function syncCourses(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;
        $campusId = (int)($params['id'] ?? 0);

        if (!$orgId || !$authUserId || !$campusId) {
            Response::error('Invalid request.', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $isFullAdmin = $this->checkFullAdminAccess($db, (int)$authUserId);

            if (!$isFullAdmin) {
                Response::error('Access restricted: Only Admin (full access) can map campus courses.', 403);
                return;
            }

            // Verify campus belongs to tenant
            $stmtCampus = $db->prepare("SELECT id, name FROM campuses WHERE id = ? AND organization_id = ?");
            $stmtCampus->execute([$campusId, $orgId]);
            $campus = $stmtCampus->fetch(PDO::FETCH_ASSOC);

            if (!$campus) {
                Response::error('Campus not found.', 404);
                return;
            }

            $body = $request->all();
            $courseIds = $body['course_ids'] ?? [];
            if (!is_array($courseIds)) {
                $courseIds = [];
            }

            // Validate that all course IDs actually belong to this tenant's programs
            $validCourseIds = [];
            if (!empty($courseIds)) {
                $cleanIds = array_values(array_unique(array_filter(array_map('intval', $courseIds), fn($val) => $val > 0)));
                if (!empty($cleanIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $sqlVal = "
                        SELECT p.id
                        FROM programs p
                        WHERE p.id IN ($inPlaceholders) AND p.organization_id = ?
                    ";
                    $stmtVal = $db->prepare($sqlVal);
                    $stmtVal->execute(array_merge($cleanIds, [$orgId]));
                    $validCourseIds = array_map('intval', $stmtVal->fetchAll(PDO::FETCH_COLUMN) ?: []);
                }
            }

            // Perform atomic sync
            $db->beginTransaction();

            $stmtDel = $db->prepare("DELETE FROM campus_courses WHERE campus_id = ? AND organization_id = ?");
            $stmtDel->execute([$campusId, $orgId]);

            if (!empty($validCourseIds)) {
                $stmtIns = $db->prepare("INSERT INTO campus_courses (organization_id, campus_id, course_id) VALUES (?, ?, ?)");
                foreach ($validCourseIds as $cid) {
                    $stmtIns->execute([$orgId, $campusId, (int)$cid]);
                }
            }

            $db->commit();

            AuditLogger::log('update', 'campus_courses', $campusId, [
                'campus_name' => $campus['name'],
                'courses_count' => count($validCourseIds)
            ]);

            Response::success([
                'message' => 'Campus courses updated successfully',
                'campus_id' => $campusId,
                'total_offered' => count($validCourseIds),
                'derived_departments' => []
            ]);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to update campus courses: ' . $e->getMessage(), 500);
        }
    }
}
