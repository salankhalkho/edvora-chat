<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use PDO;
use Throwable;

class ProgramController
{
    /**
     * Helper to reliably obtain organization ID from middleware, session, or request.
     */
    private function getOrgId(Request $request): ?int
    {
        $orgId = $GLOBALS['organization_id'] ?? $GLOBALS['auth_user']['organization_id'] ?? $request->get('organization_id');
        return $orgId ? (int)$orgId : null;
    }

    /**
     * Helper to parse time window parameter and return SQL condition & params.
     */
    private function parseWindowDateCondition(Request $request): array
    {
        $window = strtolower(trim((string)($request->get('window') ?? $request->get('time_window') ?? '30d')));
        $startDate = trim((string)($request->get('start_date') ?? ''));
        $endDate = trim((string)($request->get('end_date') ?? ''));

        $sqlWhereDate = '';
        $dateParams = [];
        $windowLabel = 'Last 30 Days';

        if (!empty($startDate) && !empty($endDate)) {
            $window = 'custom';
            $sqlWhereDate = ' AND created_at >= :start_date AND created_at <= :end_date';
            $dateParams[':start_date'] = $startDate . ' 00:00:00';
            $dateParams[':end_date'] = $endDate . ' 23:59:59';
            $windowLabel = date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate));
        } else {
            switch ($window) {
                case '24h':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)';
                    $windowLabel = 'Last 24 Hours';
                    break;
                case '7d':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                    $windowLabel = 'Last 7 Days';
                    break;
                case '3m':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
                    $windowLabel = 'Last 3 Months';
                    break;
                case '6m':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)';
                    $windowLabel = 'Last 6 Months';
                    break;
                case 'all':
                    $sqlWhereDate = '';
                    $windowLabel = 'All Time';
                    break;
                case '30d':
                default:
                    $window = '30d';
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                    $windowLabel = 'Last 30 Days';
                    break;
            }
        }

        return [
            'window' => $window,
            'window_label' => $windowLabel,
            'sql' => $sqlWhereDate,
            'params' => $dateParams
        ];
    }

    /**
     * GET /v1/programs/{id}
     * Retrieve single academic program details and scoped 4 pipeline cards telemetry.
     */
    public function show(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)$request->param('id');
        if (!$id) {
            Response::error('Invalid program ID', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT 
                    p.*
                FROM programs p
                WHERE p.id = :id AND p.organization_id = :org_id
            ");
            $stmt->execute([':id' => $id, ':org_id' => $orgId]);
            $program = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$program) {
                Response::error('Academic program not found or unauthorized', 404);
                return;
            }

            // Mapped campuses
            $stmtCmp = $db->prepare("
                SELECT c.id, c.name, c.short_name, c.city, c.state, c.is_primary
                FROM campuses c
                JOIN campus_courses cc ON c.id = cc.campus_id
                WHERE cc.course_id = ? AND c.organization_id = ? AND c.status = 'active'
                ORDER BY c.is_primary DESC, c.name ASC
            ");
            $stmtCmp->execute([$id, $orgId]);
            $campuses = $stmtCmp->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $program['campuses'] = $campuses;
            $program['campus_ids'] = array_map('intval', array_column($campuses, 'id'));

            // Parse time window filter
            $timeWindow = $this->parseWindowDateCondition($request);
            $sqlWhereDate = $timeWindow['sql'];
            $dateParams = $timeWindow['params'];

            // Card 1: Callbacks Booked (counselor_callbacks where program_id = ?)
            $stmtCb = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE program_id = :program_id AND organization_id = :org_id {$sqlWhereDate}");
            $stmtCb->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
            $callbacksCount = (int)$stmtCb->fetchColumn();

            // Card 2: Campus Tours Scheduled (campus_tour_bookings where program_id = ?)
            $stmtCt = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE program_id = :program_id AND organization_id = :org_id {$sqlWhereDate}");
            $stmtCt->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
            $campusToursCount = (int)$stmtCt->fetchColumn();

            // Card 3: Scholarships Interests (leads where program_id = ? AND scholarship_tier IS NOT NULL)
            $stmtSch = $db->prepare("SELECT COUNT(*) FROM leads WHERE program_id = :program_id AND organization_id = :org_id AND scholarship_tier IS NOT NULL {$sqlWhereDate}");
            $stmtSch->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
            $scholarshipsCount = (int)$stmtSch->fetchColumn();

            // Card 4: Lead-Magnet Dispatched (lead_assets where program_id = ?, fallback: leads count)
            $stmtAsset = $db->prepare("SELECT COALESCE(SUM(downloads_count), 0) FROM lead_assets WHERE program_id = :program_id AND organization_id = :org_id {$sqlWhereDate}");
            $stmtAsset->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
            $leadAssetsDownloads = (int)$stmtAsset->fetchColumn();

            if ($leadAssetsDownloads === 0) {
                // Fallback to leads count for this program
                $stmtLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE program_id = :program_id AND organization_id = :org_id {$sqlWhereDate}");
                $stmtLeads->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
                $leadAssetsDownloads = (int)$stmtLeads->fetchColumn();
            }

            // Total overall leads for context
            $stmtAllLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE program_id = :program_id AND organization_id = :org_id {$sqlWhereDate}");
            $stmtAllLeads->execute(array_merge([':program_id' => $id, ':org_id' => $orgId], $dateParams));
            $totalLeads = (int)$stmtAllLeads->fetchColumn();

            Response::success([
                'program' => $program,
                'time_window' => [
                    'active' => $timeWindow['window'],
                    'label' => $timeWindow['window_label']
                ],
                'funnel_metrics' => [
                    'callbacks_count' => $callbacksCount,
                    'campus_tours_count' => $campusToursCount,
                    'scholarships_count' => $scholarshipsCount,
                    'lead_magnet_dispatched' => $leadAssetsDownloads,
                    'total_leads' => $totalLeads
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve program details: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/programs
     * List all programs for the authenticated organization from the 'programs' table.
     */
    public function index(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Fetch all programs for tenant from `programs` table
            $stmt = $db->prepare("
                SELECT 
                    p.id,
                    p.organization_id,
                    NULL AS department_id,
                    NULL AS department_name,
                    NULL AS department_slug,
                    NULL AS department_color,
                    p.course_name,
                    p.course_code,
                    COALESCE(p.program_type, 'undergraduate') AS program_type,
                    p.duration,
                    COALESCE(p.mode, 'full_time') AS mode,
                    COALESCE(p.is_admissions_open, 1) AS is_admissions_open,
                    p.tuition_fee,
                    p.registration_fee,
                    p.other_fees,
                    p.total_fee,
                    COALESCE(p.currency, 'INR') AS currency,
                    p.eligibility,
                    p.application_deadline,
                    p.application_fee,
                    p.application_url,
                    p.sort_order,
                    p.created_at,
                    p.updated_at
                FROM programs p
                WHERE p.organization_id = :org_id
                ORDER BY p.sort_order ASC, p.course_name ASC
            ");
            $stmt->execute([':org_id' => $orgId]);
            $courses = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Load mapped campuses for each program
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

            // Fetch available departments for UI dropdown selector compatibility
            $stmtDepts = $db->prepare("
                SELECT id, name, slug, is_active
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
     * POST /v1/programs
     * Create a new academic program in the 'programs' table.
     */
    public function store(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $body = $request->json();
        $courseName = trim($body['course_name'] ?? '');
        if (empty($courseName)) {
            Response::error('Program / Course name is required', 422);
            return;
        }

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
            $db->beginTransaction();

            $stmt = $db->prepare("
                INSERT INTO programs (
                    organization_id, course_name, course_code, 
                    program_type, duration, mode, is_admissions_open, 
                    tuition_fee, registration_fee, other_fees, total_fee, currency, 
                    eligibility, application_deadline, application_fee, application_url, sort_order
                ) VALUES (
                    ?, ?, ?, 
                    ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $orgId, $courseName, $courseCode,
                $programType, $duration, $mode, $isAdmissionsOpen,
                $tuitionFee, $regFee, $otherFees, $totalFee, $currency,
                $eligibility, $appDeadline, $appFee, $appUrl, $sortOrder
            ]);
            $programId = (int)$db->lastInsertId();

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
                        $stmtCamp->execute([$orgId, $programId, $cid, $orgId]);
                    }
                }
            }

            $db->commit();

            Response::success([
                'id' => $programId,
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
     * PUT /v1/programs/{id}
     * Update an existing program in the 'programs' table.
     */
    public function update(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)$request->param('id');
        if (!$id) {
            Response::error('Invalid program ID', 400);
            return;
        }

        $body = $request->json();
        $courseName = trim($body['course_name'] ?? '');
        if (empty($courseName)) {
            Response::error('Program / Course name is required', 422);
            return;
        }

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

            // Verify program belongs to tenant
            $stmtVerify = $db->prepare("SELECT id FROM programs WHERE id = ? AND organization_id = ?");
            $stmtVerify->execute([$id, $orgId]);
            if (!$stmtVerify->fetch()) {
                Response::error('Program not found or unauthorized', 404);
                return;
            }

            $db->beginTransaction();

            $stmt = $db->prepare("
                UPDATE programs
                SET course_name = ?,
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
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND organization_id = ?
            ");
            $stmt->execute([
                $courseName, $courseCode,
                $programType, $duration, $mode, $isAdmissionsOpen,
                $tuitionFee, $regFee, $otherFees, $totalFee, $currency,
                $eligibility, $appDeadline, $appFee, $appUrl, $sortOrder,
                $id, $orgId
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
     * DELETE /v1/programs/{id}
     * Delete an academic program from the 'programs' table.
     */
    public function delete(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)$request->param('id');
        if (!$id) {
            Response::error('Invalid program ID', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Verify program belongs to tenant
            $stmtVerify = $db->prepare("SELECT id FROM programs WHERE id = ? AND organization_id = ?");
            $stmtVerify->execute([$id, $orgId]);
            if (!$stmtVerify->fetch()) {
                Response::error('Program not found or unauthorized', 404);
                return;
            }

            $db->beginTransaction();

            // Delete campus junction entries
            $stmtCamp = $db->prepare("DELETE FROM campus_courses WHERE course_id = ? AND organization_id = ?");
            $stmtCamp->execute([$id, $orgId]);

            // Delete program
            $stmtDel = $db->prepare("DELETE FROM programs WHERE id = ? AND organization_id = ?");
            $stmtDel->execute([$id, $orgId]);

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
