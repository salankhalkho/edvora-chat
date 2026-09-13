<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;
use Throwable;

class CourseController
{
    /**
     * List all courses for the tenant (across all departments, including orphan courses).
     * Returns:
     * - courses: list of courses with department details and mapped campuses
     * - departments: list of tenant departments for assignment dropdowns
     * - campuses: list of active tenant campuses for mapping
     */
    public function index(Request $request): void
    {
        $orgId = $request->organizationId;
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Fetch all courses for tenant, joined with departments (LEFT JOIN for orphan courses)
            $stmt = $db->prepare("
                SELECT 
                    dc.id,
                    dc.organization_id,
                    dc.department_id,
                    d.name AS department_name,
                    d.slug AS department_slug,
                    d.color AS department_color,
                    dc.course_name,
                    dc.course_code,
                    COALESCE(dc.program_type, 'undergraduate') AS program_type,
                    dc.duration,
                    COALESCE(dc.mode, 'full_time') AS mode,
                    COALESCE(dc.is_admissions_open, 1) AS is_admissions_open,
                    dc.tuition_fee,
                    dc.registration_fee,
                    dc.other_fees,
                    dc.total_fee,
                    COALESCE(dc.currency, 'INR') AS currency,
                    dc.eligibility,
                    dc.application_deadline,
                    dc.application_fee,
                    dc.application_url,
                    dc.sort_order,
                    dc.created_at,
                    dc.updated_at
                FROM department_courses dc
                LEFT JOIN departments d ON dc.department_id = d.id AND d.organization_id = :org_id_dept
                WHERE dc.organization_id = :org_id OR (dc.organization_id IS NULL AND d.organization_id = :org_id_fallback)
                ORDER BY 
                    CASE WHEN dc.department_id IS NULL THEN 1 ELSE 0 END ASC,
                    d.name ASC, 
                    dc.sort_order ASC, 
                    dc.course_name ASC
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':org_id_dept' => $orgId,
                ':org_id_fallback' => $orgId
            ]);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Load mapped campuses for each course
            foreach ($courses as &$crs) {
                $crsId = (int)$crs['id'];
                $stmtCmp = $db->prepare("
                    SELECT c.id, c.name, c.short_name, c.is_primary
                    FROM campuses c
                    JOIN campus_courses cc ON c.id = cc.campus_id
                    WHERE cc.course_id = ? AND c.organization_id = ? AND c.status = 'active'
                    ORDER BY c.is_primary DESC, c.name ASC
                ");
                $stmtCmp->execute([$crsId, $orgId]);
                $campuses = $stmtCmp->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $crs['id'] = $crsId;
                $crs['campuses'] = $campuses;
                $crs['campus_ids'] = array_map('intval', array_column($campuses, 'id'));
            }
            unset($crs);

            // Fetch available departments for dropdown selector
            $stmtDepts = $db->prepare("
                SELECT id, name, slug, color, is_active
                FROM departments
                WHERE organization_id = ?
                ORDER BY name ASC
            ");
            $stmtDepts->execute([$orgId]);
            $departments = $stmtDepts->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Fetch available campuses for dropdown / checkbox selector
            $stmtCampuses = $db->prepare("
                SELECT id, name, short_name, is_primary
                FROM campuses
                WHERE organization_id = ? AND status = 'active'
                ORDER BY is_primary DESC, name ASC
            ");
            $stmtCampuses->execute([$orgId]);
            $campuses = $stmtCampuses->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success([
                'courses' => $courses,
                'departments' => $departments,
                'campuses' => $campuses,
                'total_courses' => count($courses)
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve academic programs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new course / program (can be assigned to a department or orphan if department_id is null).
     */
    public function store(Request $request): void
    {
        $orgId = $request->organizationId;
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $body = $request->json();
        $courseName = trim($body['course_name'] ?? '');
        if (empty($courseName)) {
            Response::error('Course / Program name is required', 422);
            return;
        }

        $deptId = !empty($body['department_id']) ? (int)$body['department_id'] : null;
        $courseCode = trim($body['course_code'] ?? '') ?: null;
        $programType = $body['program_type'] ?? 'undergraduate';
        $duration = trim($body['duration'] ?? '') ?: null;
        $mode = $body['mode'] ?? 'full_time';
        $isAdmissionsOpen = isset($body['is_admissions_open']) ? (int)$body['is_admissions_open'] : 1;
        $tuitionFee = isset($body['tuition_fee']) && $body['tuition_fee'] !== '' ? (float)$body['tuition_fee'] : null;
        $regFee = isset($body['registration_fee']) && $body['registration_fee'] !== '' ? (float)$body['registration_fee'] : null;
        $otherFees = isset($body['other_fees']) && $body['other_fees'] !== '' ? (float)$body['other_fees'] : null;
        $totalFee = isset($body['total_fee']) && $body['total_fee'] !== '' ? (float)$body['total_fee'] : null;
        $currency = trim($body['currency'] ?? 'INR') ?: 'INR';
        $eligibility = trim($body['eligibility'] ?? '') ?: null;
        $appDeadline = trim($body['application_deadline'] ?? '') ?: null;
        $appFee = trim($body['application_fee'] ?? '') ?: null;
        $appUrl = trim($body['application_url'] ?? '') ?: null;
        $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
        $campusIds = $body['campus_ids'] ?? [];

        try {
            $db = Database::getConnection();

            // If department_id is provided, verify it belongs to this tenant
            if ($deptId !== null) {
                $stmtCheck = $db->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
                $stmtCheck->execute([$deptId, $orgId]);
                if (!$stmtCheck->fetch()) {
                    Response::error('Selected department not found or does not belong to your organization', 404);
                    return;
                }
            }

            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO department_courses (
                    organization_id, department_id, course_name, course_code, 
                    program_type, duration, mode, is_admissions_open, 
                    tuition_fee, registration_fee, other_fees, total_fee, currency, 
                    eligibility, application_deadline, application_fee, application_url, sort_order
                ) VALUES (
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $orgId, $deptId, $courseName, $courseCode,
                $programType, $duration, $mode, $isAdmissionsOpen,
                $tuitionFee, $regFee, $otherFees, $totalFee, $currency,
                $eligibility, $appDeadline, $appFee, $appUrl, $sortOrder
            ]);
            $courseId = (int)$db->lastInsertId();

            // Sync campus mappings
            if (is_array($campusIds) && !empty($campusIds)) {
                $stmtCamp = $db->prepare("
                    INSERT IGNORE INTO campus_courses (organization_id, campus_id, course_id)
                    SELECT ?, c.id, ?
                    FROM campuses c
                    WHERE c.id = ? AND c.organization_id = ?
                ");
                foreach ($campusIds as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) {
                        $stmtCamp->execute([$orgId, $courseId, $cid, $orgId]);
                    }
                }
            }

            $db->commit();

            Response::success([
                'id' => $courseId,
                'message' => 'Academic program created successfully'
            ], 201);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to create academic program: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update an existing course / program (including moving to another department or orphan status).
     */
    public function update(Request $request): void
    {
        $orgId = $request->organizationId;
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)$request->param('id');
        if (!$id) {
            Response::error('Invalid course ID', 400);
            return;
        }

        $body = $request->json();
        $courseName = trim($body['course_name'] ?? '');
        if (empty($courseName)) {
            Response::error('Course / Program name is required', 422);
            return;
        }

        $deptId = array_key_exists('department_id', $body) && !empty($body['department_id']) ? (int)$body['department_id'] : null;
        $courseCode = trim($body['course_code'] ?? '') ?: null;
        $programType = $body['program_type'] ?? 'undergraduate';
        $duration = trim($body['duration'] ?? '') ?: null;
        $mode = $body['mode'] ?? 'full_time';
        $isAdmissionsOpen = isset($body['is_admissions_open']) ? (int)$body['is_admissions_open'] : 1;
        $tuitionFee = isset($body['tuition_fee']) && $body['tuition_fee'] !== '' ? (float)$body['tuition_fee'] : null;
        $regFee = isset($body['registration_fee']) && $body['registration_fee'] !== '' ? (float)$body['registration_fee'] : null;
        $otherFees = isset($body['other_fees']) && $body['other_fees'] !== '' ? (float)$body['other_fees'] : null;
        $totalFee = isset($body['total_fee']) && $body['total_fee'] !== '' ? (float)$body['total_fee'] : null;
        $currency = trim($body['currency'] ?? 'INR') ?: 'INR';
        $eligibility = trim($body['eligibility'] ?? '') ?: null;
        $appDeadline = trim($body['application_deadline'] ?? '') ?: null;
        $appFee = trim($body['application_fee'] ?? '') ?: null;
        $appUrl = trim($body['application_url'] ?? '') ?: null;
        $sortOrder = isset($body['sort_order']) ? (int)$body['sort_order'] : 0;
        $campusIds = $body['campus_ids'] ?? null;

        try {
            $db = Database::getConnection();

            // Verify course belongs to tenant
            $stmtVerify = $db->prepare("
                SELECT dc.id 
                FROM department_courses dc
                LEFT JOIN departments d ON dc.department_id = d.id
                WHERE dc.id = ? AND (dc.organization_id = ? OR d.organization_id = ?)
            ");
            $stmtVerify->execute([$id, $orgId, $orgId]);
            if (!$stmtVerify->fetch()) {
                Response::error('Course not found or unauthorized', 404);
                return;
            }

            // If department_id is provided, verify it belongs to this tenant
            if ($deptId !== null) {
                $stmtCheck = $db->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
                $stmtCheck->execute([$deptId, $orgId]);
                if (!$stmtCheck->fetch()) {
                    Response::error('Selected department not found or does not belong to your organization', 404);
                    return;
                }
            }

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE department_courses
                SET department_id = ?,
                    course_name = ?,
                    course_code = ?,
                    program_type = ?,
                    duration = ?,
                    mode = ?,
                    is_admissions_open = ?,
                    tuition_fee = ?,
                    registration_fee = ?,
                    other_fees = ?,
                    total_fee = ?,
                    currency = ?,
                    eligibility = ?,
                    application_deadline = ?,
                    application_fee = ?,
                    application_url = ?,
                    sort_order = ?,
                    organization_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $deptId, $courseName, $courseCode,
                $programType, $duration, $mode, $isAdmissionsOpen,
                $tuitionFee, $regFee, $otherFees, $totalFee, $currency,
                $eligibility, $appDeadline, $appFee, $appUrl, $sortOrder,
                $orgId, $id
            ]);

            // Sync campus mappings if provided
            if (is_array($campusIds)) {
                $stmtDel = $db->prepare("DELETE FROM campus_courses WHERE course_id = ? AND organization_id = ?");
                $stmtDel->execute([$id, $orgId]);

                if (!empty($campusIds)) {
                    $stmtCamp = $db->prepare("
                        INSERT IGNORE INTO campus_courses (organization_id, campus_id, course_id)
                        SELECT ?, c.id, ?
                        FROM campuses c
                        WHERE c.id = ? AND c.organization_id = ?
                    ");
                    foreach ($campusIds as $cid) {
                        $cid = (int)$cid;
                        if ($cid > 0) {
                            $stmtCamp->execute([$orgId, $id, $cid, $orgId]);
                        }
                    }
                }
            }

            $db->commit();

            Response::success([
                'id' => $id,
                'message' => 'Academic program updated successfully'
            ]);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to update academic program: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete an academic program course.
     */
    public function delete(Request $request): void
    {
        $orgId = $request->organizationId;
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)$request->param('id');
        if (!$id) {
            Response::error('Invalid course ID', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Verify course belongs to tenant
            $stmtVerify = $db->prepare("
                SELECT dc.id 
                FROM department_courses dc
                LEFT JOIN departments d ON dc.department_id = d.id
                WHERE dc.id = ? AND (dc.organization_id = ? OR d.organization_id = ?)
            ");
            $stmtVerify->execute([$id, $orgId, $orgId]);
            if (!$stmtVerify->fetch()) {
                Response::error('Course not found or unauthorized', 404);
                return;
            }

            $db->beginTransaction();

            // Delete campus junction entries
            $stmtCamp = $db->prepare("DELETE FROM campus_courses WHERE course_id = ?");
            $stmtCamp->execute([$id]);

            // Delete course
            $stmtDel = $db->prepare("DELETE FROM department_courses WHERE id = ?");
            $stmtDel->execute([$id]);

            $db->commit();

            Response::success([
                'message' => 'Academic program deleted successfully'
            ]);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to delete academic program: ' . $e->getMessage(), 500);
        }
    }
}
