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

        $query .= " ORDER BY s.tour_date ASC, s.start_time ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($paramsMap);
        $slots = $stmt->fetchAll();

        // Calculate summary stats
        $stats = [
            'upcoming_slots' => count($slots),
            'total_capacity' => 0,
            'assigned_counselors' => 0
        ];
        $counselorIds = [];

        foreach ($slots as &$slot) {
            $stats['total_capacity'] += (int)$slot['max_capacity'];
            if (!empty($slot['counselor_user_id'])) {
                $counselorIds[$slot['counselor_user_id']] = true;
            }
        }
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

        if (!$campusId || empty($tourDate) || empty($startTime) || empty($endTime)) {
            Response::error('Campus, Date, Start Time, and End Time are required.', 422);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO campus_tour_slots (
                organization_id, campus_id, title, tour_date, start_time, end_time,
                max_capacity, counselor_user_id, status, created_at, updated_at
            ) VALUES (
                :org_id, :campus_id, :title, :tour_date, :start_time, :end_time,
                :max_capacity, :counselor_uid, 'active', NOW(), NOW()
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':campus_id' => $campusId,
            ':title' => $title,
            ':tour_date' => $tourDate,
            ':start_time' => $startTime,
            ':end_time' => $endTime,
            ':max_capacity' => $maxCapacity,
            ':counselor_uid' => $counselorUserId
        ]);
        $slotId = (int)$db->lastInsertId();

        AuditLogger::log('campus_tour_slot_created', 'campus_tour_slot', $slotId, [
            'campus_id' => $campusId,
            'tour_date' => $tourDate,
            'max_capacity' => $maxCapacity
        ]);

        Response::success(['id' => $slotId], 'Campus tour slot created successfully.', 201);
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
