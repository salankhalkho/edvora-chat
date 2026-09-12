<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Services\EmailService;
use DateTime;
use DateTimeZone;
use PDO;
use Throwable;

class CampusTourController
{
    /**
     * POST /v1/campus-tours — Book a new campus tour (Public Widget or Admin endpoint)
     */
    public function store(Request $request, array $params = []): void
    {
        $botToken = trim((string)$request->get('bot_token'));
        $name = trim((string)$request->get('name'));
        $email = strtolower(trim((string)$request->get('email')));
        $phone = trim((string)$request->get('phone'));
        $preferredDate = trim((string)$request->get('preferred_date')) ?: null;
        $preferredTime = trim((string)$request->get('preferred_time')) ?: 'Morning';
        $programInterest = trim((string)$request->get('program_interest')) ?: null;
        $groupSize = max(1, (int)($request->get('group_size') ?: 1));
        $notes = trim((string)$request->get('notes')) ?: null;
        $conversationId = (int)$request->get('conversation_id');
        $departmentId = $request->get('department_id') ? (int)$request->get('department_id') : null;

        if (empty($name) || empty($email) || empty($phone)) {
            Response::error('Full Name, Email Address, and Phone Number are required to book a campus tour.', 422);
        }

        $db = Database::getConnection();

        // Validate bot_token
        $stmtBot = $db->prepare("
            SELECT c.id, c.organization_id, o.name as org_name
            FROM chatbots c
            JOIN organizations o ON c.organization_id = o.id
            WHERE c.bot_token = :token AND c.is_active = 1
        ");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Invalid or inactive chatbot token.', 403);
        }

        $orgId = (int)$bot['organization_id'];
        $botId = (int)$bot['id'];
        $orgName = $bot['org_name'] ?? 'College Campus';

        // Auto-assign staff if department specified
        $assignedUserId = null;
        if ($departmentId) {
            $stmtStaff = $db->prepare("
                SELECT user_id FROM department_staff
                WHERE department_id = :did AND is_on_duty = 1
                ORDER BY id ASC LIMIT 1
            ");
            $stmtStaff->execute([':did' => $departmentId]);
            $staffRow = $stmtStaff->fetch();
            if ($staffRow) {
                $assignedUserId = (int)$staffRow['user_id'];
            }
        }

        // 1. Insert into campus_tour_bookings
        $stmtTour = $db->prepare("
            INSERT INTO campus_tour_bookings (
                organization_id, chatbot_id, department_id, conversation_id, assigned_user_id,
                student_name, student_email, student_phone, preferred_date, preferred_time,
                program_interest, group_size, notes, status, created_at, updated_at
            ) VALUES (
                :org_id, :bot_id, :dept_id, :conv_id, :assigned_uid,
                :name, :email, :phone, :pref_date, :pref_time,
                :program, :group_size, :notes, 'pending', NOW(), NOW()
            )
        ");
        $stmtTour->execute([
            ':org_id' => $orgId,
            ':bot_id' => $botId,
            ':dept_id' => $departmentId ?: null,
            ':conv_id' => $conversationId ?: null,
            ':assigned_uid' => $assignedUserId ?: null,
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':pref_date' => $preferredDate,
            ':pref_time' => $preferredTime,
            ':program' => $programInterest,
            ':group_size' => $groupSize,
            ':notes' => $notes
        ]);
        $tourId = (int)$db->lastInsertId();

        // 2. Also register in unified leads master table with lead_type = 'campus_tour'
        $stmtLead = $db->prepare("
            INSERT INTO leads (
                organization_id, chatbot_id, lead_type, conversation_id, department_id, assigned_user_id,
                name, email, phone, program_interest, notes, status, created_at, updated_at
            ) VALUES (
                :org_id, :bot_id, 'campus_tour', :conv_id, :dept_id, :assigned_uid,
                :name, :email, :phone, :program, :notes, 'new', NOW(), NOW()
            )
        ");
        $tourNotes = "Booked Campus Tour on {$preferredDate} ({$preferredTime}), Group Size: {$groupSize}";
        if ($notes) $tourNotes .= " | Notes: " . $notes;
        $stmtLead->execute([
            ':org_id' => $orgId,
            ':bot_id' => $botId,
            ':conv_id' => $conversationId ?: null,
            ':dept_id' => $departmentId ?: null,
            ':assigned_uid' => $assignedUserId ?: null,
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':program' => $programInterest ?: 'Campus Tour',
            ':notes' => $tourNotes
        ]);

        // 3. Update conversation state if conversation_id exists
        if ($conversationId > 0) {
            $stmtUpdateConv = $db->prepare("
                UPDATE conversations
                SET visitor_name = :name, visitor_email = :email, visitor_phone = :phone,
                    lead_name_collected = 1, lead_email_collected = 1, lead_phone_collected = 1,
                    lead_program_interest = :program, lead_capture_trigger = 'campus_tour', lead_captured_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmtUpdateConv->execute([
                ':name' => $name,
                ':email' => $email,
                ':phone' => $phone,
                ':program' => $programInterest ?: 'Campus Tour',
                ':id' => $conversationId,
                ':org_id' => $orgId
            ]);
        }

        // 4. Update monthly usage log
        $period = date('Y-m');
        $db->exec("
            INSERT INTO usage_logs (organization_id, period, leads_captured)
            VALUES ({$orgId}, '{$period}', 1)
            ON DUPLICATE KEY UPDATE leads_captured = leads_captured + 1
        ");

        AuditLogger::log('campus_tour_booked', 'campus_tour', $tourId, [
            'name' => $name,
            'email' => $email,
            'preferred_date' => $preferredDate
        ]);

        // 5. Send Alert Email to Admin / Coordinator
        $stmtOwner = $db->prepare("
            SELECT u.email FROM users u
            WHERE u.organization_id = :org_id AND u.role IN ('owner', 'admin')
            LIMIT 1
        ");
        $stmtOwner->execute([':org_id' => $orgId]);
        $owner = $stmtOwner->fetch();
        if ($owner && !empty($owner['email'])) {
            EmailService::sendCampusTourNotification($owner['email'], [
                'student_name' => $name,
                'student_email' => $email,
                'student_phone' => $phone,
                'preferred_date' => $preferredDate,
                'preferred_time' => $preferredTime,
                'program_interest' => $programInterest,
                'group_size' => $groupSize
            ], $orgName);
        }

        Response::success([
            'id' => $tourId,
            'status' => 'pending',
            'message' => 'Campus tour booked successfully.'
        ], 'Campus tour scheduled successfully.', 201);
    }

    /**
     * GET /v1/campus-tours — List all campus tour bookings for college admin
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT t.*,
                   d.name as department_name,
                   d.icon as department_icon,
                   u.name as assigned_user_name,
                   u.email as assigned_user_email
            FROM campus_tour_bookings t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN users u ON t.assigned_user_id = u.id
            WHERE t.organization_id = :org_id
            ORDER BY t.id DESC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $tours = $stmt->fetchAll();

        // Calculate summary counts
        $stats = [
            'total' => count($tours),
            'pending' => 0,
            'confirmed' => 0,
            'completed' => 0,
            'cancelled' => 0
        ];
        foreach ($tours as $t) {
            $st = $t['status'] ?? 'pending';
            if (isset($stats[$st])) {
                $stats[$st]++;
            }
        }

        Response::success([
            'tours' => $tours,
            'stats' => $stats
        ]);
    }

    /**
     * GET /v1/campus-tours/{id} — Single tour details
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT t.*,
                   d.name as department_name,
                   u.name as assigned_user_name
            FROM campus_tour_bookings t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN users u ON t.assigned_user_id = u.id
            WHERE t.id = :id AND t.organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $tour = $stmt->fetch();

        if (!$tour) {
            Response::error('Campus tour booking not found.', 404);
        }

        Response::success($tour);
    }

    /**
     * PUT /v1/campus-tours/{id} — Update tour booking status & counselor notes
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $data = $request->all();

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM campus_tour_bookings WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Campus tour booking not found.', 404);
        }

        $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'];
        $status = in_array($data['status'] ?? '', $validStatuses) ? $data['status'] : $existing['status'];
        $counselorNotes = $data['counselor_notes'] ?? $existing['counselor_notes'];
        $confirmedDate = !empty($data['confirmed_date']) ? $data['confirmed_date'] : $existing['confirmed_date'];
        $assignedUserId = isset($data['assigned_user_id']) ? ($data['assigned_user_id'] ? (int)$data['assigned_user_id'] : null) : $existing['assigned_user_id'];

        $stmtUpdate = $db->prepare("
            UPDATE campus_tour_bookings
            SET status = :status,
                counselor_notes = :notes,
                confirmed_date = :confirmed_date,
                assigned_user_id = :assigned_user_id,
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmtUpdate->execute([
            ':status' => $status,
            ':notes' => $counselorNotes,
            ':confirmed_date' => $confirmedDate,
            ':assigned_user_id' => $assignedUserId,
            ':id' => $id,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('campus_tour_updated', 'campus_tour', $id, [
            'previous_status' => $existing['status'],
            'new_status' => $status,
            'assigned_user_id' => $assignedUserId
        ]);

        // Auto-trigger post-tour feedback survey if status changed to 'completed'
        if ($status === 'completed' && $existing['status'] !== 'completed') {
            $stmtOrg = $db->prepare("SELECT name FROM organizations WHERE id = :id");
            $stmtOrg->execute([':id' => $orgId]);
            $orgRow = $stmtOrg->fetch();
            $orgName = $orgRow['name'] ?? 'our college';

            if (!empty($existing['student_email'])) {
                EmailService::sendCampusTourFeedbackSurvey($existing['student_email'], $existing, $orgName);
            }
        }

        Response::success([
            'id' => $id,
            'status' => $status,
            'counselor_notes' => $counselorNotes,
            'confirmed_date' => $confirmedDate,
            'assigned_user_id' => $assignedUserId
        ], 'Campus tour updated successfully.');
    }

    /**
     * POST /v1/campus-tours/{id}/feedback — Manually trigger post-tour feedback survey email
     */
    public function triggerFeedback(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM campus_tour_bookings WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $tour = $stmt->fetch();

        if (!$tour) {
            Response::error('Campus tour booking not found.', 404);
        }

        if (empty($tour['student_email'])) {
            Response::error('Student email address is missing for this booking.', 422);
        }

        $stmtOrg = $db->prepare("SELECT name FROM organizations WHERE id = :id");
        $stmtOrg->execute([':id' => $orgId]);
        $orgRow = $stmtOrg->fetch();
        $orgName = $orgRow['name'] ?? 'our college';

        $sent = EmailService::sendCampusTourFeedbackSurvey($tour['student_email'], $tour, $orgName);

        if ($sent) {
            Response::success(null, 'Feedback survey email sent successfully to student.');
        } else {
            Response::error('Failed to dispatch feedback email. Please verify mail service settings.', 500);
        }
    }

    /**
     * GET /v1/campus-tours/export — Export campus tours to CSV format with IST timestamps
     */
    public function export(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT t.student_name, t.student_email, t.student_phone,
                   d.name as department_name,
                   u.name as assigned_user_name,
                   t.preferred_date, t.preferred_time, t.group_size,
                   t.program_interest, t.status, t.counselor_notes, t.created_at
            FROM campus_tour_bookings t
            LEFT JOIN departments d ON t.department_id = d.id
            LEFT JOIN users u ON t.assigned_user_id = u.id
            WHERE t.organization_id = :org_id
            ORDER BY t.id DESC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $tours = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=edvora_campus_tours_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Student Name', 'Email', 'Phone', 'Department', 'Assigned Coordinator', 'Preferred Date', 'Preferred Time', 'Group Size', 'Program Interest', 'Status', 'Counselor Notes', 'Booked On (IST)']);

        $tz = new DateTimeZone('Asia/Kolkata');
        foreach ($tours as $row) {
            $formattedDate = 'N/A';
            if (!empty($row['created_at'])) {
                try {
                    $dt = new DateTime($row['created_at']);
                    $dt->setTimezone($tz);
                    $formattedDate = $dt->format('d M Y, h:i A') . ' IST';
                } catch (Throwable $e) {
                    $formattedDate = $row['created_at'];
                }
            }

            fputcsv($output, [
                $row['student_name'],
                $row['student_email'] ?: 'N/A',
                $row['student_phone'] ?: 'N/A',
                $row['department_name'] ?: 'General Campus',
                $row['assigned_user_name'] ?: 'Unassigned',
                $row['preferred_date'] ?: 'Flexible',
                $row['preferred_time'] ?: 'Morning',
                $row['group_size'] ?: 1,
                $row['program_interest'] ?: 'General',
                strtoupper($row['status']),
                $row['counselor_notes'] ?: '',
                $formattedDate
            ]);
        }

        fclose($output);
        exit;
    }
}
