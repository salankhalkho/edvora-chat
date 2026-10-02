<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\TenantLocalizationHelper;
use App\Services\EmailService;
use App\Services\LlmService;
use DateTime;
use PDO;
use Throwable;

class CampusTourSchedulingController
{
    /**
     * GET /v1/campus-tours/slots — Fetch active visit slots (Admin & Public Widget)
     */
    public function indexSlots(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $orgId = $GLOBALS['organization_id'] ?? null;
        $botToken = trim((string)($request->get('bot_token') ?: $request->getBotToken()));
        $campusId = $request->get('campus_id') ? (int)$request->get('campus_id') : null;
        $programId = $request->get('program_id') ? (int)$request->get('program_id') : null;
        $reqOrgId = (int)($request->get('organization_id') ?: $request->get('org_id'));

        // 1. If Bearer JWT token provided (admin dashboard), decode it
        $token = $request->getBearerToken();
        $payload = null;
        if (!$orgId && $token) {
            $payload = Jwt::decode($token);
            if (!$payload) {
                // If caller attempted Bearer auth, but token is invalid or expired, return 401
                // so the client-side Fetch Interceptor refreshes token and retries automatically!
                Response::error('Invalid or expired authentication token.', 401);
            }
            if (!empty($payload['organization_id'])) {
                $orgId = (int)$payload['organization_id'];
            } elseif (!empty($payload['user_id'])) {
                $stmtU = $db->prepare("SELECT organization_id, role FROM users WHERE id = :uid LIMIT 1");
                $stmtU->execute([':uid' => (int)$payload['user_id']]);
                $u = $stmtU->fetch();
                if ($u && !empty($u['organization_id'])) {
                    $orgId = (int)$u['organization_id'];
                }
            }
        }

        // 2. Resolve from explicit organization_id parameter
        if (!$orgId && $reqOrgId > 0) {
            $stmtOrg = $db->prepare("SELECT id FROM organizations WHERE id = :id LIMIT 1");
            $stmtOrg->execute([':id' => $reqOrgId]);
            if ($stmtOrg->fetch()) {
                $orgId = $reqOrgId;
            }
        }

        // 3. Resolve from campus_id if provided
        if (!$orgId && $campusId) {
            $stmtCamp = $db->prepare("SELECT organization_id FROM campuses WHERE id = :cid LIMIT 1");
            $stmtCamp->execute([':cid' => $campusId]);
            $camp = $stmtCamp->fetch();
            if ($camp && !empty($camp['organization_id'])) {
                $orgId = (int)$camp['organization_id'];
            }
        }

        // 4. If called from public chatbot widget, resolve orgId from bot_token
        if (!$orgId && !empty($botToken)) {
            $stmtBot = $db->prepare("SELECT organization_id FROM chatbots WHERE bot_token = :token AND is_active = 1 LIMIT 1");
            $stmtBot->execute([':token' => $botToken]);
            $bot = $stmtBot->fetch();
            if ($bot) {
                $orgId = (int)$bot['organization_id'];
            }
        }

        // 5. Superadmin fallback if authenticated as superadmin and no org specified
        if (!$orgId && isset($payload['role']) && ($payload['role'] === 'superadmin' || $payload['role'] === 'super_admin')) {
            $firstOrg = $db->query("SELECT id FROM organizations ORDER BY id ASC LIMIT 1")->fetch();
            if ($firstOrg) {
                $orgId = (int)$firstOrg['id'];
            }
        }

        if (!$orgId) {
            Response::error('Organization context missing.', 400);
        }

        // Program to Campus mapping fallback:
        // If program_id provided and campus_id not specified, check which campus offers this program
        if ($programId && !$campusId) {
            $stmtProgCampus = $db->prepare("SELECT campus_id FROM program_campuses WHERE program_id = :pid LIMIT 1");
            $stmtProgCampus->execute([':pid' => $programId]);
            $pc = $stmtProgCampus->fetch();
            if ($pc && !empty($pc['campus_id'])) {
                $campusId = (int)$pc['campus_id'];
            } else {
                // Default to main/primary campus if unassigned
                $stmtMain = $db->prepare("SELECT id FROM campuses WHERE organization_id = :org_id ORDER BY is_primary DESC, id ASC LIMIT 1");
                $stmtMain->execute([':org_id' => $orgId]);
                $main = $stmtMain->fetch();
                if ($main) {
                    $campusId = (int)$main['id'];
                }
            }
        }

        $query = "
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name, COALESCE(c.is_primary, 1) as is_primary,
                   c.country as campus_country, c.state as campus_state, c.city as campus_city,
                   u.name as counselor_name
            FROM campus_tour_slots s
            LEFT JOIN campuses c ON s.campus_id = c.id
            LEFT JOIN users u ON s.counselor_user_id = u.id
            WHERE s.organization_id = :org_id AND s.status = 'active'
        ";
        $paramsMap = [':org_id' => $orgId];

        if ($campusId) {
            $query .= " AND s.campus_id = :campus_id";
            $paramsMap[':campus_id'] = $campusId;
        }

        if ($programId) {
            // When filtered by program_id, return slots mapped to that program OR general slots
            $query .= " AND (s.is_general = 1 OR s.id IN (SELECT slot_id FROM campus_tour_slot_programs WHERE program_id = :filter_prog_id AND organization_id = :filter_sub_org_id))";
            $paramsMap[':filter_prog_id'] = $programId;
            $paramsMap[':filter_sub_org_id'] = $orgId;
        }

        $query .= " ORDER BY s.tour_date ASC, s.start_time ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($paramsMap);
        $slots = $stmt->fetchAll();

        // Attach mapped programs to each slot
        $slotIds = array_column($slots, 'id');
        $slotProgramsMap = [];
        if (!empty($slotIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($slotIds), '?'));
            $stmtProg = $db->prepare("
                SELECT stp.slot_id, p.id as program_id, p.course_name as program_name, p.course_code
                FROM campus_tour_slot_programs stp
                JOIN programs p ON stp.program_id = p.id
                WHERE stp.slot_id IN ({$inPlaceholders})
            ");
            $stmtProg->execute($slotIds);
            while ($row = $stmtProg->fetch()) {
                $slotProgramsMap[$row['slot_id']][] = [
                    'id' => (int)$row['program_id'],
                    'name' => $row['program_name'],
                    'code' => $row['course_code']
                ];
            }
        }

        // Calculate summary stats
        $stats = [
            'upcoming_slots' => count($slots),
            'total_capacity' => 0,
            'assigned_counselors' => 0
        ];
        $counselorIds = [];

        foreach ($slots as &$slot) {
            $slot['programs'] = $slotProgramsMap[$slot['id']] ?? [];
            $stats['total_capacity'] += (int)$slot['max_capacity'];
            if (!empty($slot['counselor_user_id'])) {
                $counselorIds[$slot['counselor_user_id']] = true;
            }
        }
        unset($slot);
        $stats['assigned_counselors'] = count($counselorIds);

        Response::success([
            'slots' => $slots,
            'stats' => $stats,
            'resolved_campus_id' => $campusId,
            'localization' => \App\Helpers\TenantLocalizationHelper::getTenantLocalization((int)$orgId)
        ]);
    }

    /**
     * POST /v1/campus-tours/slots — Create a new tour visit slot
     */
    public function storeSlot(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Unauthorized.', 401);
        }

        $campusId = (int)$request->get('campus_id');
        $title = trim((string)$request->get('title')) ?: 'Guided Campus & Lab Discovery Tour';
        $tourDate = trim((string)$request->get('tour_date'));
        $startTime = trim((string)$request->get('start_time'));
        $endTime = trim((string)$request->get('end_time'));
        $maxCapacity = max(1, (int)($request->get('max_capacity') ?: 15));
        $counselorUserId = $request->get('counselor_user_id') ? (int)$request->get('counselor_user_id') : null;
        $isGeneral = isset($_POST['is_general']) || isset($request->all()['is_general']) ? (int)(bool)$request->get('is_general') : 1;
        $programIds = $request->get('program_ids');
        if (!is_array($programIds)) {
            $programIds = [];
        }
        $programIds = array_filter(array_map('intval', $programIds));

        // If specific programs are selected, is_general is 0; otherwise 1
        if (!empty($programIds)) {
            $isGeneral = 0;
        } else {
            $isGeneral = 1;
        }

        if (!$campusId || empty($tourDate) || empty($startTime) || empty($endTime)) {
            Response::error('Campus, Date, Start Time, and End Time are required.', 422);
        }

        $db = Database::getConnection();

        // Determine counselor if not set
        if (!$counselorUserId) {
            // Find any on-duty counselor for the organization
            $stmtStaff = $db->prepare("
                SELECT u.id FROM users u
                WHERE u.organization_id = :org_id AND u.role IN ('counselor', 'admission_officer', 'agent', 'admin')
                ORDER BY u.id ASC LIMIT 1
            ");
            $stmtStaff->execute([':org_id' => $orgId]);
            $staffRow = $stmtStaff->fetch();
            if ($staffRow) {
                $counselorUserId = (int)$staffRow['id'];
            }
        }

        $stmt = $db->prepare("
            INSERT INTO campus_tour_slots (
                organization_id, campus_id, title, is_general, tour_date, start_time, end_time,
                max_capacity, counselor_user_id, status, created_at, updated_at
            ) VALUES (
                :org_id, :campus_id, :title, :is_general, :tour_date, :start_time, :end_time,
                :max_capacity, :counselor_uid, 'active', NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':campus_id' => $campusId,
            ':title' => $title,
            ':is_general' => $isGeneral,
            ':tour_date' => $tourDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':max_capacity' => $maxCapacity,
            ':counselor_uid' => $counselorUserId
        ]);
        $slotId = (int)$db->lastInsertId();

        // Insert program mappings if specific programs selected
        if (!empty($programIds)) {
            $stmtProgInsert = $db->prepare("
                INSERT IGNORE INTO campus_tour_slot_programs (organization_id, slot_id, program_id)
                VALUES (:org_id, :slot_id, :prog_id)
            ");
            foreach ($programIds as $pId) {
                $stmtProgInsert->execute([
                    ':org_id' => $orgId,
                    ':slot_id' => $slotId,
                    ':prog_id' => $pId
                ]);
            }
        }

        AuditLogger::log('campus_tour_slot_created', 'campus_tour_slot', $slotId, [
            'campus_id' => $campusId,
            'tour_date' => $tourDate,
            'is_general' => $isGeneral,
            'program_count' => count($programIds),
            'max_capacity' => $maxCapacity
        ]);

        Response::success(['id' => $slotId], 'Campus tour slot created successfully.', 201);
    }

    /**
     * GET /v1/campus-tours/slots/{id} — Fetch single tour slot details
     */
    public function showSlot(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        if (!$orgId || !$id) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name, COALESCE(c.is_primary, 1) as is_primary, u.name as counselor_name,
                   GREATEST(COALESCE(s.booked_count, 0), (SELECT COUNT(*) FROM campus_tour_bookings b WHERE b.slot_id = s.id AND b.status != 'cancelled')) as live_booked_count
            FROM campus_tour_slots s
            LEFT JOIN campuses c ON s.campus_id = c.id
            LEFT JOIN users u ON s.counselor_user_id = u.id
            WHERE s.id = :id AND s.organization_id = :org_id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $slot = $stmt->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        // Fetch mapped programs
        $stmtProg = $db->prepare("
            SELECT stp.program_id, p.course_name as program_name, p.course_code
            FROM campus_tour_slot_programs stp
            JOIN programs p ON stp.program_id = p.id
            WHERE stp.slot_id = :slot_id AND stp.organization_id = :org_id
        ");
        $stmtProg->execute([':slot_id' => $id, ':org_id' => $orgId]);
        $programs = $stmtProg->fetchAll();
        $slot['programs'] = $programs;

        Response::success($slot);
    }

    /**
     * PUT /v1/campus-tours/slots/{id} — Update an existing tour visit slot
     */
    public function updateSlot(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        if (!$orgId || !$id) {
            Response::error('Invalid request.', 400);
        }

        $campusId = (int)$request->get('campus_id');
        $title = trim((string)$request->get('title')) ?: 'Guided Campus & Lab Discovery Tour';
        $tourDate = trim((string)$request->get('tour_date'));
        $startTime = trim((string)$request->get('start_time'));
        $endTime = trim((string)$request->get('end_time'));
        $maxCapacity = max(1, (int)($request->get('max_capacity') ?: 15));
        $counselorUserId = $request->get('counselor_user_id') ? (int)$request->get('counselor_user_id') : null;
        $programIds = $request->get('program_ids');
        if (!is_array($programIds)) {
            $programIds = [];
        }
        $programIds = array_filter(array_map('intval', $programIds));

        $isGeneral = !empty($programIds) ? 0 : 1;

        if (!$campusId || empty($tourDate) || empty($startTime) || empty($endTime)) {
            Response::error('Campus, Date, Start Time, and End Time are required.', 422);
        }

        $db = Database::getConnection();

        // Verify slot exists and belongs to organization
        $stmtCheck = $db->prepare("SELECT id FROM campus_tour_slots WHERE id = :id AND organization_id = :org_id");
        $stmtCheck->execute([':id' => $id, ':org_id' => $orgId]);
        if (!$stmtCheck->fetch()) {
            Response::error('Tour slot not found.', 404);
        }

        // Determine counselor if not set
        if (!$counselorUserId) {
            $stmtStaff = $db->prepare("
                SELECT u.id FROM users u
                WHERE u.organization_id = :org_id AND u.role IN ('counselor', 'admission_officer', 'agent', 'admin')
                ORDER BY u.id ASC LIMIT 1
            ");
            $stmtStaff->execute([':org_id' => $orgId]);
            $staffRow = $stmtStaff->fetch();
            if ($staffRow) {
                $counselorUserId = (int)$staffRow['id'];
            }
        }

        $stmt = $db->prepare("
            UPDATE campus_tour_slots SET
                campus_id = :campus_id,
                title = :title,
                is_general = :is_general,
                tour_date = :tour_date,
                start_time = :start_time,
                end_time = :end_time,
                max_capacity = :max_capacity,
                counselor_user_id = :counselor_uid,
                status = 'active',
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([
            ':id' => $id,
            ':org_id' => $orgId,
            ':campus_id' => $campusId,
            ':title' => $title,
            ':is_general' => $isGeneral,
            ':tour_date' => $tourDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':max_capacity' => $maxCapacity,
            ':counselor_uid' => $counselorUserId
        ]);

        // Reset and re-insert program mappings
        $stmtDelProg = $db->prepare("DELETE FROM campus_tour_slot_programs WHERE slot_id = :slot_id AND organization_id = :org_id");
        $stmtDelProg->execute([':slot_id' => $id, ':org_id' => $orgId]);

        if (!empty($programIds)) {
            $stmtProgInsert = $db->prepare("
                INSERT IGNORE INTO campus_tour_slot_programs (organization_id, slot_id, program_id)
                VALUES (:org_id, :slot_id, :prog_id)
            ");
            foreach ($programIds as $pId) {
                $stmtProgInsert->execute([
                    ':org_id' => $orgId,
                    ':slot_id' => $id,
                    ':prog_id' => $pId
                ]);
            }
        }

        AuditLogger::log('campus_tour_slot_updated', 'campus_tour_slot', $id, [
            'campus_id' => $campusId,
            'tour_date' => $tourDate,
            'is_general' => $isGeneral,
            'program_count' => count($programIds),
            'max_capacity' => $maxCapacity
        ]);

        Response::success(['id' => $id], 'Campus tour slot updated successfully.');
    }

    /**
     * DELETE /v1/campus-tours/slots/{id} — Cancel/Delete a tour slot
     */
    public function deleteSlot(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        if (!$orgId || !$id) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE campus_tour_slots SET status = 'cancelled' WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);

        AuditLogger::log('campus_tour_slot_cancelled', 'campus_tour_slot', $id, []);

        Response::success(null, 'Campus tour slot cancelled.');
    }

    /**
     * GET /v1/campus-tours/settings — Fetch guided tour policies & routing rules
     */
    public function getSettings(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Unauthorized.', 401);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM campus_tour_settings WHERE organization_id = :org_id");
        $stmt->execute([':org_id' => $orgId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            $settings = [
                'organization_id' => $orgId,
                'routing_policy' => 'direct_assigned',
                'advance_hours' => 12,
                'auto_followup_enabled' => 1
            ];
        }

        Response::success($settings);
    }

    /**
     * POST /v1/campus-tours/settings — Save tour policies & routing rules
     */
    public function saveSettings(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Unauthorized.', 401);
        }

        $routingPolicy = in_array($request->get('routing_policy'), ['direct_assigned', 'round_robin']) ? $request->get('routing_policy') : 'direct_assigned';
        $advanceHours = max(1, (int)($request->get('advance_hours') ?: 12));
        $autoFollowupEnabled = $request->get('auto_followup_enabled') ? 1 : 0;

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO campus_tour_settings (organization_id, routing_policy, advance_hours, auto_followup_enabled, updated_at)
            VALUES (:org_id, :routing, :advance, :auto_follow, NOW())
            ON DUPLICATE KEY UPDATE
                routing_policy = VALUES(routing_policy),
                advance_hours = VALUES(advance_hours),
                auto_followup_enabled = VALUES(auto_followup_enabled),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':routing' => $routingPolicy,
            ':advance' => $advanceHours,
            ':auto_follow' => $autoFollowupEnabled
        ]);

        AuditLogger::log('campus_tour_settings_updated', 'campus_tour_settings', $orgId, [
            'routing_policy' => $routingPolicy,
            'advance_hours' => $advanceHours
        ]);

        Response::success(null, 'Campus tour settings updated successfully.');
    }

    /**
     * GET /v1/campus-tours/slots/{id}/attendees — Slot-specific attendee manifest & KPIs
     */
    public function indexSlotAttendees(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // 1. Fetch slot details
        $stmtSlot = $db->prepare("
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name, c.address_line, c.city, c.state,
                   u.name as counselor_name, u.email as counselor_email
            FROM campus_tour_slots s
            LEFT JOIN campuses c ON s.campus_id = c.id
            LEFT JOIN users u ON s.counselor_user_id = u.id
            WHERE s.id = :sid AND s.organization_id = :org_id
            LIMIT 1
        ");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $slot = $stmtSlot->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        // 2. Fetch all candidates booked for this slot
        $stmtBookings = $db->prepare("
            SELECT b.*, u.name as assigned_counselor_name, u.email as assigned_counselor_email,
                   (SELECT COUNT(*) FROM campus_tour_feedbacks fb WHERE fb.booking_id = b.id AND fb.submitted_at IS NOT NULL) as has_submitted_feedback
            FROM campus_tour_bookings b
            LEFT JOIN users u ON b.assigned_user_id = u.id
            WHERE b.slot_id = :sid AND b.organization_id = :org_id
            ORDER BY b.id DESC
        ");
        $stmtBookings->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $attendees = $stmtBookings->fetchAll();

        // 3. Compute live KPIs
        $stats = [
            'total_bookings' => count($attendees),
            'total_headcount' => 0,
            'attended_count' => 0,
            'attended_headcount' => 0,
            'no_show_count' => 0,
            'pending_count' => 0,
            'confirmed_count' => 0,
            'cancelled_count' => 0,
            'feedbacks_count' => 0
        ];

        foreach ($attendees as $a) {
            $gSize = max(1, (int)($a['group_size'] ?? 1));
            $st = $a['status'] ?? 'pending';
            $stats['total_headcount'] += $gSize;

            if ($st === 'attended') {
                $stats['attended_count']++;
                $stats['attended_headcount'] += $gSize;
            } elseif ($st === 'no_show') {
                $stats['no_show_count']++;
            } elseif ($st === 'confirmed') {
                $stats['confirmed_count']++;
            } elseif ($st === 'cancelled') {
                $stats['cancelled_count']++;
            } else {
                $stats['pending_count']++;
            }

            if (!empty($a['has_submitted_feedback'])) {
                $stats['feedbacks_count']++;
            }
        }

        // 4. Check active share token for gate/ambassador check-in
        $stmtToken = $db->prepare("
            SELECT access_token, expires_at FROM campus_tour_roster_tokens
            WHERE slot_id = :sid AND organization_id = :org_id AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmtToken->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $activeToken = $stmtToken->fetch();

        // 5. Mapped programs
        $stmtProg = $db->prepare("
            SELECT p.id, p.course_name as name, p.course_code as code
            FROM campus_tour_slot_programs stp
            JOIN programs p ON stp.program_id = p.id
            WHERE stp.slot_id = :sid AND stp.organization_id = :org_id
        ");
        $stmtProg->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $programs = $stmtProg->fetchAll();

        list($tz, $tzShort, $loc) = TenantLocalizationHelper::getTenantDateTimeZone((int)$orgId);

        Response::success([
            'slot' => $slot,
            'attendees' => $attendees,
            'stats' => $stats,
            'programs' => $programs,
            'share_token' => $activeToken ? $activeToken['access_token'] : null,
            'share_url' => $activeToken ? '/gate-checkin.html?token=' . $activeToken['access_token'] : null,
            'tz_short' => $tzShort
        ]);
    }

    /**
     * POST /v1/campus-tours/slots/{id}/attendees/{booking_id}/attendance — 1-Click attendance toggle
     */
    public function updateAttendeeAttendance(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);
        $bookingId = (int)($params['booking_id'] ?? 0);

        if (!$orgId || !$slotId || !$bookingId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // Verify booking belongs to slot and organization
        $stmtB = $db->prepare("
            SELECT * FROM campus_tour_bookings
            WHERE id = :bid AND slot_id = :sid AND organization_id = :org_id
            LIMIT 1
        ");
        $stmtB->execute([':bid' => $bookingId, ':sid' => $slotId, ':org_id' => $orgId]);
        $booking = $stmtB->fetch();

        if (!$booking) {
            Response::error('Booking record not found for this slot.', 404);
        }

        $validStatuses = ['pending', 'confirmed', 'attended', 'completed', 'no_show', 'cancelled'];
        $status = strtolower(trim((string)$request->get('status')));
        if (!in_array($status, $validStatuses)) {
            Response::error('Invalid attendance status.', 422);
        }

        $checkInNotes = $request->get('check_in_notes') !== null ? trim((string)$request->get('check_in_notes')) : $booking['check_in_notes'];
        $currentUserId = $GLOBALS['user_id'] ?? null;

        $attendedAt = $booking['attended_at'];
        $checkInByUserId = $booking['check_in_by_user_id'];

        if ($status === 'attended') {
            $attendedAt = date('Y-m-d H:i:s');
            $checkInByUserId = $currentUserId;

            // Automatically elevate lead intent score in leads table to 95 (Hot Lead)
            $whereClauses = [];
            $leadParams = [':org_id' => $orgId];
            if (!empty($booking['student_email'])) {
                $whereClauses[] = "email = :email";
                $leadParams[':email'] = $booking['student_email'];
            }
            if (!empty($booking['student_phone'])) {
                $whereClauses[] = "phone = :phone";
                $leadParams[':phone'] = $booking['student_phone'];
            }
            if (!empty($booking['conversation_id'])) {
                $whereClauses[] = "conversation_id = :conv_id";
                $leadParams[':conv_id'] = $booking['conversation_id'];
            }

            if (!empty($whereClauses)) {
                $whereSql = implode(' OR ', $whereClauses);
                $stmtLead = $db->prepare("
                    UPDATE leads
                    SET conversion_score = 95,
                        conversion_score_rationale = 'Attended Campus Tour',
                        pipeline_stage = 'campus_visit',
                        status = CASE WHEN status = 'new' THEN 'contacted' ELSE status END,
                        notes = CONCAT(COALESCE(notes, ''), ' | Attended Campus Tour on ', CURDATE()),
                        updated_at = NOW()
                    WHERE organization_id = :org_id AND ({$whereSql})
                ");
                $stmtLead->execute($leadParams);
            }
        } elseif ($status === 'no_show') {
            $attendedAt = null;
        }

        $stmtUp = $db->prepare("
            UPDATE campus_tour_bookings
            SET status = :status,
                attended_at = :attended_at,
                check_in_by_user_id = :check_in_uid,
                check_in_notes = :check_in_notes,
                updated_at = NOW()
            WHERE id = :bid AND slot_id = :sid AND organization_id = :org_id
        ");
        $stmtUp->execute([
            ':status' => $status,
            ':attended_at' => $attendedAt,
            ':check_in_uid' => $checkInByUserId,
            ':check_in_notes' => $checkInNotes,
            ':bid' => $bookingId,
            ':sid' => $slotId,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('campus_tour_attendance_updated', 'campus_tour_booking', $bookingId, [
            'slot_id' => $slotId,
            'previous_status' => $booking['status'],
            'new_status' => $status,
            'student_name' => $booking['student_name']
        ]);

        Response::success([
            'id' => $bookingId,
            'status' => $status,
            'attended_at' => $attendedAt,
            'check_in_notes' => $checkInNotes
        ], 'Attendance updated successfully.');
    }

    /**
     * GET /v1/campus-tours/slots/{id}/export — Download slot-specific attendee roster CSV
     */
    public function exportSlotRoster(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        $stmtSlot = $db->prepare("
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name
            FROM campus_tour_slots s
            LEFT JOIN campuses c ON s.campus_id = c.id
            WHERE s.id = :sid AND s.organization_id = :org_id
            LIMIT 1
        ");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $slot = $stmtSlot->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        $stmtBookings = $db->prepare("
            SELECT b.*, u.name as assigned_counselor_name
            FROM campus_tour_bookings b
            LEFT JOIN users u ON b.assigned_user_id = u.id
            WHERE b.slot_id = :sid AND b.organization_id = :org_id
            ORDER BY b.id DESC
        ");
        $stmtBookings->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $attendees = $stmtBookings->fetchAll();

        list($tz, $tzShort, $loc) = TenantLocalizationHelper::getTenantDateTimeZone((int)$orgId);

        $safeDate = preg_replace('/[^a-zA-Z0-9_-]/', '_', $slot['tour_date'] ?? date('Y-m-d'));
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Slot_' . $slotId . '_Roster_' . $safeDate . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Student Name',
            'Phone',
            'Email',
            'Attendance Status',
            'Check-In Time (' . $tzShort . ')',
            'Group Size',
            'Program Interest',
            'Campus',
            'Tour Date',
            'Tour Time Window',
            'Check-In Notes',
            'Registered On (' . $tzShort . ')'
        ]);

        foreach ($attendees as $a) {
            $formattedCheckin = '—';
            if (!empty($a['attended_at'])) {
                try {
                    $dt = new DateTime($a['attended_at']);
                    $dt->setTimezone($tz);
                    $formattedCheckin = $dt->format('d M Y, h:i A') . ' ' . $tzShort;
                } catch (Throwable $e) {
                    $formattedCheckin = $a['attended_at'];
                }
            }

            $formattedBooked = '—';
            if (!empty($a['created_at'])) {
                try {
                    $dt = new DateTime($a['created_at']);
                    $dt->setTimezone($tz);
                    $formattedBooked = $dt->format('d M Y, h:i A') . ' ' . $tzShort;
                } catch (Throwable $e) {
                    $formattedBooked = $a['created_at'];
                }
            }

            fputcsv($out, [
                $a['student_name'],
                $a['student_phone'],
                $a['student_email'],
                strtoupper($a['status']),
                $formattedCheckin,
                $a['group_size'] ?: 1,
                $a['program_interest'] ?: 'General Tour',
                $slot['campus_name'],
                $slot['tour_date'],
                substr($slot['start_time'], 0, 5) . ' – ' . substr($slot['end_time'], 0, 5) . ' ' . $tzShort,
                $a['check_in_notes'] ?: ($a['notes'] ?: ''),
                $formattedBooked
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * POST /v1/campus-tours/slots/{id}/share-token — Generate or retrieve active gatekeeper share token
     */
    public function generateShareToken(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // Verify slot
        $stmtSlot = $db->prepare("SELECT id FROM campus_tour_slots WHERE id = :sid AND organization_id = :org_id");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        if (!$stmtSlot->fetch()) {
            Response::error('Tour slot not found.', 404);
        }

        $forceNew = (bool)$request->get('force_new');

        if (!$forceNew) {
            $stmtExisting = $db->prepare("
                SELECT access_token, expires_at FROM campus_tour_roster_tokens
                WHERE slot_id = :sid AND organization_id = :org_id AND expires_at > NOW()
                ORDER BY id DESC LIMIT 1
            ");
            $stmtExisting->execute([':sid' => $slotId, ':org_id' => $orgId]);
            $existing = $stmtExisting->fetch();
            if ($existing) {
                Response::success([
                    'token' => $existing['access_token'],
                    'share_url' => '/gate-checkin.html?token=' . $existing['access_token'],
                    'expires_at' => $existing['expires_at']
                ], 'Existing active share link retrieved.');
                return;
            }
        }

        $token = bin2hex(random_bytes(24));
        $currentUserId = $GLOBALS['user_id'] ?? null;

        $stmtIns = $db->prepare("
            INSERT INTO campus_tour_roster_tokens (
                organization_id, slot_id, access_token, created_by_user_id, expires_at, created_at
            ) VALUES (
                :org_id, :sid, :token, :uid, DATE_ADD(NOW(), INTERVAL 48 HOUR), NOW()
            )
        ");
        $stmtIns->execute([
            ':org_id' => $orgId,
            ':sid' => $slotId,
            ':token' => $token,
            ':uid' => $currentUserId
        ]);

        Response::success([
            'token' => $token,
            'share_url' => '/gate-checkin.html?token=' . $token,
            'expires_at' => date('Y-m-d H:i:s', time() + (48 * 3600))
        ], 'Gate check-in pass link created successfully (valid for 48 hours).', 201);
    }

    /**
     * GET /v1/public/tour-roster/{token} — Public mobile check-in view for security gate & ambassadors
     */
    public function getPublicRoster(Request $request, array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        if (empty($token)) {
            Response::error('Missing pass token.', 400);
        }

        $db = Database::getConnection();

        $stmtT = $db->prepare("
            SELECT rt.*, s.title, s.tour_date, s.start_time, s.end_time, s.max_capacity, s.booked_count,
                   c.name as campus_name, c.address_line, c.city, c.state,
                   o.name as org_name, u.name as counselor_name
            FROM campus_tour_roster_tokens rt
            JOIN campus_tour_slots s ON rt.slot_id = s.id
            JOIN organizations o ON rt.organization_id = o.id
            LEFT JOIN campuses c ON s.campus_id = c.id
            LEFT JOIN users u ON s.counselor_user_id = u.id
            WHERE rt.access_token = :token AND rt.expires_at > NOW()
            LIMIT 1
        ");
        $stmtT->execute([':token' => $token]);
        $row = $stmtT->fetch();

        if (!$row) {
            Response::error('Invalid or expired gate check-in pass link. Please request a refreshed link from the admissions office.', 404);
        }

        $slotId = (int)$row['slot_id'];
        $orgId = (int)$row['organization_id'];

        $stmtAttendees = $db->prepare("
            SELECT id, student_name, student_phone, student_email, program_interest, group_size,
                   status, attended_at, check_in_notes
            FROM campus_tour_bookings
            WHERE slot_id = :sid AND organization_id = :org_id
            ORDER BY student_name ASC
        ");
        $stmtAttendees->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $attendees = $stmtAttendees->fetchAll();

        $stats = [
            'total' => count($attendees),
            'attended' => 0,
            'no_show' => 0,
            'pending' => 0
        ];
        foreach ($attendees as $a) {
            if ($a['status'] === 'attended') $stats['attended']++;
            elseif ($a['status'] === 'no_show') $stats['no_show']++;
            else $stats['pending']++;
        }

        list($tz, $tzShort, $loc) = TenantLocalizationHelper::getTenantDateTimeZone($orgId);

        Response::success([
            'institution_name' => $row['org_name'],
            'campus_name' => $row['campus_name'],
            'slot_title' => $row['title'],
            'tour_date' => $row['tour_date'],
            'start_time' => substr($row['start_time'], 0, 5),
            'end_time' => substr($row['end_time'], 0, 5),
            'tz_short' => $tzShort,
            'counselor_name' => $row['counselor_name'] ?: 'Admissions Guide',
            'stats' => $stats,
            'attendees' => $attendees
        ]);
    }

    /**
     * POST /v1/public/tour-roster/{token}/check-in — Public rapid check-in toggle
     */
    public function submitPublicGateCheckIn(Request $request, array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        $bookingId = (int)$request->get('booking_id');
        $status = strtolower(trim((string)$request->get('status'))) ?: 'attended';
        $notes = trim((string)$request->get('notes')) ?: null;

        if (empty($token) || !$bookingId) {
            Response::error('Invalid check-in submission.', 400);
        }

        if (!in_array($status, ['attended', 'no_show', 'pending'])) {
            Response::error('Invalid status.', 422);
        }

        $db = Database::getConnection();

        $stmtT = $db->prepare("
            SELECT organization_id, slot_id FROM campus_tour_roster_tokens
            WHERE access_token = :token AND expires_at > NOW()
            LIMIT 1
        ");
        $stmtT->execute([':token' => $token]);
        $pass = $stmtT->fetch();

        if (!$pass) {
            Response::error('Invalid or expired gate check-in pass.', 403);
        }

        $orgId = (int)$pass['organization_id'];
        $slotId = (int)$pass['slot_id'];

        $stmtB = $db->prepare("
            SELECT * FROM campus_tour_bookings
            WHERE id = :bid AND slot_id = :sid AND organization_id = :org_id
            LIMIT 1
        ");
        $stmtB->execute([':bid' => $bookingId, ':sid' => $slotId, ':org_id' => $orgId]);
        $booking = $stmtB->fetch();

        if (!$booking) {
            Response::error('Booking record not found.', 404);
        }

        $attendedAt = ($status === 'attended') ? date('Y-m-d H:i:s') : null;

        $stmtUp = $db->prepare("
            UPDATE campus_tour_bookings
            SET status = :status,
                attended_at = :attended_at,
                check_in_notes = COALESCE(:notes, check_in_notes),
                updated_at = NOW()
            WHERE id = :bid AND organization_id = :org_id
        ");
        $stmtUp->execute([
            ':status' => $status,
            ':attended_at' => $attendedAt,
            ':notes' => $notes,
            ':bid' => $bookingId,
            ':org_id' => $orgId
        ]);

        if ($status === 'attended') {
            // Auto warm lead in leads CRM
            $whereClauses = [];
            $leadParams = [':org_id' => $orgId];
            if (!empty($booking['student_email'])) {
                $whereClauses[] = "email = :email";
                $leadParams[':email'] = $booking['student_email'];
            }
            if (!empty($booking['student_phone'])) {
                $whereClauses[] = "phone = :phone";
                $leadParams[':phone'] = $booking['student_phone'];
            }
            if (!empty($booking['conversation_id'])) {
                $whereClauses[] = "conversation_id = :conv_id";
                $leadParams[':conv_id'] = $booking['conversation_id'];
            }

            if (!empty($whereClauses)) {
                $whereSql = implode(' OR ', $whereClauses);
                $stmtLead = $db->prepare("
                    UPDATE leads
                    SET conversion_score = 95,
                        conversion_score_rationale = 'Attended Campus Tour (Gate Check-In)',
                        pipeline_stage = 'campus_visit',
                        status = CASE WHEN status = 'new' THEN 'contacted' ELSE status END,
                        notes = CONCAT(COALESCE(notes, ''), ' | Gate check-in attended on ', CURDATE()),
                        updated_at = NOW()
                    WHERE organization_id = :org_id AND ({$whereSql})
                ");
                $stmtLead->execute($leadParams);
            }
        }

        Response::success([
            'id' => $bookingId,
            'status' => $status,
            'attended_at' => $attendedAt
        ], 'Gate check-in recorded successfully.');
    }

    /**
     * POST /v1/campus-tours/slots/{id}/send-feedback — Dispatch post-tour survey to attendees
     */
    public function sendSlotFeedbackSurveys(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // 1. Fetch slot & organization name
        $stmtSlot = $db->prepare("
            SELECT s.*, o.name as org_name
            FROM campus_tour_slots s
            JOIN organizations o ON s.organization_id = o.id
            WHERE s.id = :sid AND s.organization_id = :org_id
            LIMIT 1
        ");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $slot = $stmtSlot->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        $orgName = $slot['org_name'] ?? 'College Campus';

        // 2. Fetch target attendees (either specific booking_ids or all attended)
        $bookingIds = $request->get('booking_ids');
        if (is_array($bookingIds) && !empty($bookingIds)) {
            $inIds = implode(',', array_map('intval', $bookingIds));
            $stmtAtt = $db->prepare("
                SELECT * FROM campus_tour_bookings
                WHERE slot_id = :sid AND organization_id = :org_id AND id IN ({$inIds})
            ");
            $stmtAtt->execute([':sid' => $slotId, ':org_id' => $orgId]);
        } else {
            $stmtAtt = $db->prepare("
                SELECT * FROM campus_tour_bookings
                WHERE slot_id = :sid AND organization_id = :org_id AND status = 'attended'
            ");
            $stmtAtt->execute([':sid' => $slotId, ':org_id' => $orgId]);
        }
        $attendees = $stmtAtt->fetchAll();

        if (empty($attendees)) {
            Response::error('No checked-in attendees found to receive feedback surveys.', 422);
        }

        $dispatched = 0;
        foreach ($attendees as $b) {
            if (empty($b['student_email'])) continue;

            // Check if feedback token already generated
            $stmtFb = $db->prepare("SELECT feedback_token FROM campus_tour_feedbacks WHERE booking_id = :bid AND slot_id = :sid LIMIT 1");
            $stmtFb->execute([':bid' => $b['id'], ':sid' => $slotId]);
            $existingFb = $stmtFb->fetch();

            if ($existingFb) {
                $fbToken = $existingFb['feedback_token'];
            } else {
                $fbToken = bin2hex(random_bytes(24));
                $stmtInsFb = $db->prepare("
                    INSERT INTO campus_tour_feedbacks (
                        organization_id, booking_id, slot_id, feedback_token, created_at
                    ) VALUES (
                        :org_id, :bid, :sid, :token, NOW()
                    )
                ");
                $stmtInsFb->execute([
                    ':org_id' => $orgId,
                    ':bid' => $b['id'],
                    ':sid' => $slotId,
                    ':token' => $fbToken
                ]);
            }

            $feedbackUrl = 'https://edvora.chat/tour-feedback.html?token=' . $fbToken;
            $sent = EmailService::sendCampusTourFeedbackSurvey($b['student_email'], $b, $orgName, $feedbackUrl);
            if ($sent) {
                $db->exec("UPDATE campus_tour_bookings SET feedback_sent_at = NOW() WHERE id = {$b['id']}");
                $dispatched++;
            }
        }

        AuditLogger::log('campus_tour_feedbacks_dispatched', 'campus_tour_slot', $slotId, [
            'dispatched_count' => $dispatched,
            'total_attendees' => count($attendees)
        ]);

        Response::success([
            'dispatched_count' => $dispatched,
            'total_attendees' => count($attendees)
        ], "Feedback survey successfully dispatched to {$dispatched} attendee(s).");
    }

    /**
     * GET /v1/public/tour-feedback/{token} — Public student feedback form view
     */
    public function getPublicTourFeedback(Request $request, array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        if (empty($token)) {
            Response::error('Missing survey token.', 400);
        }

        $db = Database::getConnection();

        $stmtFb = $db->prepare("
            SELECT fb.*, b.student_name, b.student_email, b.program_interest,
                   s.title as slot_title, s.tour_date,
                   c.name as campus_name, o.name as org_name
            FROM campus_tour_feedbacks fb
            JOIN campus_tour_bookings b ON fb.booking_id = b.id
            JOIN campus_tour_slots s ON fb.slot_id = s.id
            JOIN organizations o ON fb.organization_id = o.id
            LEFT JOIN campuses c ON s.campus_id = c.id
            WHERE fb.feedback_token = :token
            LIMIT 1
        ");
        $stmtFb->execute([':token' => $token]);
        $row = $stmtFb->fetch();

        if (!$row) {
            Response::error('Invalid or expired feedback survey link.', 404);
        }

        Response::success([
            'student_name' => $row['student_name'],
            'institution_name' => $row['org_name'],
            'campus_name' => $row['campus_name'] ?: 'Main Campus',
            'tour_title' => $row['slot_title'],
            'tour_date' => $row['tour_date'],
            'program_interest' => $row['program_interest'],
            'is_already_submitted' => !empty($row['submitted_at']),
            'submitted_at' => $row['submitted_at'],
            'existing_feedback' => !empty($row['submitted_at']) ? [
                'rating_overall' => (int)$row['rating_overall'],
                'rating_facilities' => (int)$row['rating_facilities'],
                'rating_guide' => (int)$row['rating_guide'],
                'intent_to_apply' => $row['intent_to_apply'],
                'highlight_text' => $row['highlight_text'],
                'improvement_text' => $row['improvement_text']
            ] : null
        ]);
    }

    /**
     * POST /v1/public/tour-feedback/{token} — Public student survey submission
     */
    public function submitPublicTourFeedback(Request $request, array $params = []): void
    {
        $token = trim((string)($params['token'] ?? ''));
        if (empty($token)) {
            Response::error('Missing survey token.', 400);
        }

        $ratingOverall = max(1, min(5, (int)$request->get('rating_overall')));
        $ratingFacilities = $request->get('rating_facilities') ? max(1, min(5, (int)$request->get('rating_facilities'))) : null;
        $ratingGuide = $request->get('rating_guide') ? max(1, min(5, (int)$request->get('rating_guide'))) : null;
        $intentToApply = in_array($request->get('intent_to_apply'), ['definitely', 'likely', 'exploring', 'unlikely'])
            ? $request->get('intent_to_apply') : 'likely';
        $highlightText = trim((string)$request->get('highlight_text')) ?: null;
        $improvementText = trim((string)$request->get('improvement_text')) ?: null;
        $pendingQuestions = trim((string)$request->get('pending_questions')) ?: null;

        if (!$ratingOverall) {
            Response::error('Please select an overall rating (1 to 5 stars).', 422);
        }

        $db = Database::getConnection();

        $stmtFb = $db->prepare("
            SELECT * FROM campus_tour_feedbacks
            WHERE feedback_token = :token
            LIMIT 1
        ");
        $stmtFb->execute([':token' => $token]);
        $row = $stmtFb->fetch();

        if (!$row) {
            Response::error('Invalid survey link.', 404);
        }

        if (!empty($row['submitted_at'])) {
            Response::error('You have already submitted your feedback for this campus visit. Thank you!', 400);
        }

        $stmtUp = $db->prepare("
            UPDATE campus_tour_feedbacks
            SET rating_overall = :ro,
                rating_facilities = :rf,
                rating_guide = :rg,
                intent_to_apply = :ita,
                highlight_text = :ht,
                improvement_text = :it,
                pending_questions = :pq,
                submitted_at = NOW()
            WHERE id = :id
        ");
        $stmtUp->execute([
            ':ro' => $ratingOverall,
            ':rf' => $ratingFacilities,
            ':rg' => $ratingGuide,
            ':ita' => $intentToApply,
            ':ht' => $highlightText,
            ':it' => $improvementText,
            ':pq' => $pendingQuestions,
            ':id' => $row['id']
        ]);

        Response::success(null, 'Thank you! Your feedback has been received and shared with our admissions leadership.');
    }

    /**
     * GET /v1/campus-tours/slots/{id}/feedbacks — View all submitted feedback and scorecard
     */
    public function getSlotFeedbacks(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // 1. Fetch slot metadata & cached AI summary
        $stmtSlot = $db->prepare("
            SELECT id, title, tour_date, start_time, end_time, ai_feedback_summary, ai_feedback_generated_at
            FROM campus_tour_slots
            WHERE id = :sid AND organization_id = :org_id
            LIMIT 1
        ");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $slot = $stmtSlot->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        // 2. Fetch all feedbacks
        $stmtFb = $db->prepare("
            SELECT fb.*, b.student_name, b.student_email, b.student_phone, b.program_interest, b.group_size
            FROM campus_tour_feedbacks fb
            JOIN campus_tour_bookings b ON fb.booking_id = b.id
            WHERE fb.slot_id = :sid AND fb.organization_id = :org_id
            ORDER BY fb.submitted_at DESC, fb.id DESC
        ");
        $stmtFb->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $allFeedbacks = $stmtFb->fetchAll();

        // 3. Compute scorecard
        $submitted = array_filter($allFeedbacks, fn($f) => !empty($f['submitted_at']));
        $subCount = count($submitted);

        $scorecard = [
            'total_surveys_sent' => count($allFeedbacks),
            'total_submitted' => $subCount,
            'response_rate_percent' => count($allFeedbacks) > 0 ? round(($subCount / count($allFeedbacks)) * 100) : 0,
            'avg_overall_rating' => 0,
            'avg_facilities_rating' => 0,
            'avg_guide_rating' => 0,
            'intent_breakdown' => [
                'definitely' => 0,
                'likely' => 0,
                'exploring' => 0,
                'unlikely' => 0
            ]
        ];

        if ($subCount > 0) {
            $sumOverall = 0;
            $sumFac = 0; $countFac = 0;
            $sumGuide = 0; $countGuide = 0;

            foreach ($submitted as $s) {
                $sumOverall += (int)$s['rating_overall'];
                if (!empty($s['rating_facilities'])) {
                    $sumFac += (int)$s['rating_facilities'];
                    $countFac++;
                }
                if (!empty($s['rating_guide'])) {
                    $sumGuide += (int)$s['rating_guide'];
                    $countGuide++;
                }
                $ita = $s['intent_to_apply'] ?: 'likely';
                if (isset($scorecard['intent_breakdown'][$ita])) {
                    $scorecard['intent_breakdown'][$ita]++;
                }
            }

            $scorecard['avg_overall_rating'] = round($sumOverall / $subCount, 1);
            $scorecard['avg_facilities_rating'] = $countFac > 0 ? round($sumFac / $countFac, 1) : 0;
            $scorecard['avg_guide_rating'] = $countGuide > 0 ? round($sumGuide / $countGuide, 1) : 0;
        }

        Response::success([
            'slot' => $slot,
            'scorecard' => $scorecard,
            'feedbacks' => array_values($submitted)
        ]);
    }

    /**
     * POST /v1/campus-tours/slots/{id}/ai-summary — Generate LLM executive feedback synthesis
     */
    public function generateSlotAiFeedbackSummary(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $slotId = (int)($params['id'] ?? 0);

        if (!$orgId || !$slotId) {
            Response::error('Invalid request.', 400);
        }

        $db = Database::getConnection();

        // 1. Fetch slot & organization
        $stmtSlot = $db->prepare("
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name, o.name as org_name
            FROM campus_tour_slots s
            JOIN organizations o ON s.organization_id = o.id
            LEFT JOIN campuses c ON s.campus_id = c.id
            WHERE s.id = :sid AND s.organization_id = :org_id
            LIMIT 1
        ");
        $stmtSlot->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $slot = $stmtSlot->fetch();

        if (!$slot) {
            Response::error('Tour slot not found.', 404);
        }

        // 2. Fetch submitted feedbacks
        $stmtFb = $db->prepare("
            SELECT fb.*, b.student_name, b.program_interest
            FROM campus_tour_feedbacks fb
            JOIN campus_tour_bookings b ON fb.booking_id = b.id
            WHERE fb.slot_id = :sid AND fb.organization_id = :org_id AND fb.submitted_at IS NOT NULL
            ORDER BY fb.id ASC
        ");
        $stmtFb->execute([':sid' => $slotId, ':org_id' => $orgId]);
        $feedbacks = $stmtFb->fetchAll();

        if (empty($feedbacks)) {
            Response::error('No attendee feedback has been submitted yet for this slot. Please dispatch the survey and collect responses first.', 422);
        }

        // Build context for LLM
        $feedbackSnippets = [];
        foreach ($feedbacks as $idx => $f) {
            $num = $idx + 1;
            $snippet = "Candidate #{$num} ({$f['student_name']}, Program: " . ($f['program_interest'] ?: 'General') . "):\n";
            $snippet .= "- Overall Rating: {$f['rating_overall']}/5 | Facilities: " . ($f['rating_facilities'] ?: 'N/A') . "/5 | Guide: " . ($f['rating_guide'] ?: 'N/A') . "/5\n";
            $snippet .= "- Intent to Apply: " . strtoupper($f['intent_to_apply']) . "\n";
            if (!empty($f['highlight_text'])) $snippet .= "- Highlights & What they loved: \"{$f['highlight_text']}\"\n";
            if (!empty($f['improvement_text'])) $snippet .= "- Constructive Criticism: \"{$f['improvement_text']}\"\n";
            if (!empty($f['pending_questions'])) $snippet .= "- Urgent Questions for Admissions: \"{$f['pending_questions']}\"\n";
            $feedbackSnippets[] = $snippet;
        }

        $allFeedbacksText = implode("\n", $feedbackSnippets);

        $systemPrompt = "You are the Chief Admissions Intelligence Officer for {$slot['org_name']}. You specialize in evaluating candidate sentiment and admissions conversion yield from in-person campus tours. Your task is to analyze candidate feedback from a completed campus tour slot and deliver a sharp, actionable executive briefing for admissions leadership.";

        $userMessage = "Please analyze the following " . count($feedbacks) . " candidate feedback responses from the Campus Tour held on {$slot['tour_date']} at {$slot['campus_name']} ('{$slot['title']}'):\n\n" .
            $allFeedbacksText . "\n\n" .
            "Format your response with the following 4 markdown sections:\n" .
            "### 1. Executive Impression & Sentiment Overview\n(2-3 sentences summarizing overall visitor perception, sentiment score, and conversion momentum)\n\n" .
            "### 2. Key Highlights & Campus Praise\n(Bullet points detailing specific labs, staff, facilities, or experiences that prospective students loved)\n\n" .
            "### 3. Friction Points & Areas for Improvement\n(Bullet points identifying any pacing issues, unclear information, hostel/facility concerns, or hesitations)\n\n" .
            "### 4. Admissions Directives & Priority Counselor Follow-Ups\n(Action items for admissions counselors, specifically flagging any students with urgent pending questions or high enrollment intent).";

        try {
            $llmResponse = LlmService::complete($systemPrompt, $userMessage, [], [
                'max_tokens_override' => 900
            ]);

            $summary = trim((string)($llmResponse['text'] ?? $llmResponse['content'] ?? ''));

            if (empty($summary)) {
                Response::error('Failed to generate AI feedback summary. Please try again.', 500);
            }

            // Save summary into slot cache
            $stmtUp = $db->prepare("
                UPDATE campus_tour_slots
                SET ai_feedback_summary = :summary,
                    ai_feedback_generated_at = NOW()
                WHERE id = :sid AND organization_id = :org_id
            ");
            $stmtUp->execute([
                ':summary' => $summary,
                ':sid' => $slotId,
                ':org_id' => $orgId
            ]);

            AuditLogger::log('campus_tour_ai_summary_generated', 'campus_tour_slot', $slotId, [
                'feedbacks_analyzed' => count($feedbacks)
            ]);

            Response::success([
                'summary' => $summary,
                'generated_at' => date('Y-m-d H:i:s'),
                'feedbacks_analyzed' => count($feedbacks)
            ], 'AI Executive Feedback Brief generated successfully.');

        } catch (Throwable $e) {
            error_log("[CampusTourScheduling] AI summary generation error: " . $e->getMessage());
            Response::error('AI engine error while synthesizing tour feedback: ' . $e->getMessage(), 500);
        }
    }
}
