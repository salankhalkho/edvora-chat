<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
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
        $botToken = trim((string)$request->get('bot_token'));
        $campusId = $request->get('campus_id') ? (int)$request->get('campus_id') : null;
        $programId = $request->get('program_id') ? (int)$request->get('program_id') : null;

        // If not set by middleware, extract organization_id from Bearer JWT token (admin dashboard)
        if (!$orgId) {
            $token = $request->getBearerToken();
            if ($token) {
                $payload = Jwt::decode($token);
                if ($payload && !empty($payload['organization_id'])) {
                    $orgId = (int)$payload['organization_id'];
                }
            }
        }

        // If called from public chatbot widget, resolve orgId from bot_token
        if (!$orgId && !empty($botToken)) {
            $stmtBot = $db->prepare("SELECT organization_id FROM chatbots WHERE bot_token = :token AND is_active = 1");
            $stmtBot->execute([':token' => $botToken]);
            $bot = $stmtBot->fetch();
            if ($bot) {
                $orgId = (int)$bot['organization_id'];
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
            SELECT s.*, COALESCE(c.name, 'Main Campus') as campus_name, COALESCE(c.is_primary, 1) as is_primary, u.name as counselor_name
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
            $query .= " AND (s.is_general = 1 OR s.id IN (SELECT slot_id FROM campus_tour_slot_programs WHERE program_id = :filter_prog_id AND organization_id = :org_id))";
            $paramsMap[':filter_prog_id'] = $programId;
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
            'resolved_campus_id' => $campusId
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
}
