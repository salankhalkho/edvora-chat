<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use PDO;
use Throwable;

class CallbackController
{
    /**
     * POST /v1/callbacks — Register new counselor callback request (Widget or Public API)
     */
    public function store(Request $request, array $params = []): void
    {
        $botToken = trim((string)$request->get('bot_token'));
        $name = trim((string)($request->get('student_name') ?? $request->get('name') ?? ''));
        $phone = trim((string)($request->get('student_phone') ?? $request->get('phone') ?? ''));
        $email = strtolower(trim((string)($request->get('student_email') ?? $request->get('email') ?? '')));
        $timeSlot = trim((string)($request->get('preferred_time_slot') ?? 'Immediate (ASAP)'));
        $topic = trim((string)($request->get('topic_or_query') ?? $request->get('topic') ?? ''));
        $conversationId = (int)$request->get('conversation_id');
        $departmentId = $request->get('department_id') ? (int)$request->get('department_id') : null;

        if (empty($name) || empty($phone)) {
            Response::error('Student name and phone number are required to request a callback.', 422);
            return;
        }

        $db = Database::getConnection();

        // 1. Authenticate chatbot token
        $stmtBot = $db->prepare("SELECT id, organization_id FROM chatbots WHERE bot_token = :token AND is_active = 1");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Invalid or inactive chatbot token.', 403);
            return;
        }

        $orgId = (int)$bot['organization_id'];
        $botId = (int)$bot['id'];

        // Determine department_id from conversation if not explicitly passed
        if (!$departmentId && $conversationId > 0) {
            $stmtConvDept = $db->prepare("SELECT department_id FROM conversations WHERE id = :cid AND organization_id = :oid");
            $stmtConvDept->execute([':cid' => $conversationId, ':oid' => $orgId]);
            $convRow = $stmtConvDept->fetch();
            if ($convRow && !empty($convRow['department_id'])) {
                $departmentId = (int)$convRow['department_id'];
            }
        }

        // Auto-assign callback to department staff / counselor
        $assignedUserId = null;
        if ($departmentId) {
            $stmtDeptRules = $db->prepare("SELECT lead_assignment_rules FROM departments WHERE id = :did AND organization_id = :oid");
            $stmtDeptRules->execute([':did' => $departmentId, ':oid' => $orgId]);
            $deptRow = $stmtDeptRules->fetch();
            $assignmentRules = $deptRow ? json_decode($deptRow['lead_assignment_rules'] ?? '{}', true) : [];
            $method = $assignmentRules['method'] ?? 'round_robin';

            if ($method === 'direct') {
                $stmtLeadStaff = $db->prepare("
                    SELECT ds.user_id
                    FROM department_staff ds
                    WHERE ds.department_id = :did AND ds.is_on_duty = 1 AND ds.role = 'lead'
                    LIMIT 1
                ");
                $stmtLeadStaff->execute([':did' => $departmentId]);
                $leadStaff = $stmtLeadStaff->fetch();
                if ($leadStaff) {
                    $assignedUserId = (int)$leadStaff['user_id'];
                }
            }

            if (!$assignedUserId) {
                // Round-robin distribution across on-duty staff based on active callbacks count
                $stmtRr = $db->prepare("
                    SELECT ds.user_id
                    FROM department_staff ds
                    LEFT JOIN counselor_callbacks cb ON cb.assigned_user_id = ds.user_id AND cb.status IN ('pending', 'scheduled', 'in_progress')
                    WHERE ds.department_id = :did AND ds.is_on_duty = 1
                    GROUP BY ds.user_id
                    ORDER BY COUNT(cb.id) ASC, ds.id ASC
                    LIMIT 1
                ");
                $stmtRr->execute([':did' => $departmentId]);
                $rrStaff = $stmtRr->fetch();
                if ($rrStaff) {
                    $assignedUserId = (int)$rrStaff['user_id'];
                }
            }
        }

        // Fallback: assign to first active org admin/owner if no department staff found
        if (!$assignedUserId) {
            $stmtDefaultUser = $db->prepare("SELECT id FROM users WHERE organization_id = :oid AND role IN ('owner', 'admin') ORDER BY id ASC LIMIT 1");
            $stmtDefaultUser->execute([':oid' => $orgId]);
            $defUser = $stmtDefaultUser->fetch();
            if ($defUser) {
                $assignedUserId = (int)$defUser['id'];
            }
        }

        // Insert callback record
        $stmtInsert = $db->prepare("
            INSERT INTO counselor_callbacks (
                organization_id, chatbot_id, department_id, conversation_id, assigned_user_id,
                student_name, student_phone, student_email, preferred_time_slot, topic_or_query,
                status, created_at, updated_at
            ) VALUES (
                :org_id, :bot_id, :dept_id, :conv_id, :assigned_user_id,
                :name, :phone, :email, :time_slot, :topic,
                'pending', NOW(), NOW()
            )
        ");
        $stmtInsert->execute([
            ':org_id' => $orgId,
            ':bot_id' => $botId,
            ':dept_id' => $departmentId ?: null,
            ':conv_id' => $conversationId ?: null,
            ':assigned_user_id' => $assignedUserId ?: null,
            ':name' => $name,
            ':phone' => $phone,
            ':email' => $email ?: null,
            ':time_slot' => $timeSlot,
            ':topic' => $topic ?: null
        ]);
        $callbackId = (int)$db->lastInsertId();

        // Also record as a student lead in leads table for consolidated CRM tracking
        try {
            $stmtLead = $db->prepare("
                INSERT INTO leads (organization_id, chatbot_id, conversation_id, department_id, assigned_user_id, name, email, phone, program_interest, notes, status, created_at, updated_at)
                VALUES (:org_id, :bot_id, :conv_id, :dept_id, :assigned_user_id, :name, :email, :phone, :program, :notes, 'new', NOW(), NOW())
            ");
            $stmtLead->execute([
                ':org_id' => $orgId,
                ':bot_id' => $botId,
                ':conv_id' => $conversationId ?: null,
                ':dept_id' => $departmentId ?: null,
                ':assigned_user_id' => $assignedUserId ?: null,
                ':name' => $name,
                ':email' => $email ?: null,
                ':phone' => $phone,
                ':program' => $topic ?: 'Counselor Callback Request',
                ':notes' => "Callback requested for: {$timeSlot}. Topic: " . ($topic ?: 'General Admissions')
            ]);
        } catch (Throwable $e) {
            // Non-critical if lead already captured
        }

        // Update conversation visitor info if conversationId provided
        if ($conversationId > 0) {
            $db->prepare("
                UPDATE conversations
                SET visitor_name = :name, visitor_phone = :phone,
                    lead_name_collected = 1, lead_phone_collected = 1,
                    lead_capture_trigger = 'counselor_callback', lead_captured_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ")->execute([
                ':name' => $name,
                ':phone' => $phone,
                ':id' => $conversationId,
                ':org_id' => $orgId
            ]);
        }

        AuditLogger::log('callback_requested', 'counselor_callback', $callbackId, [
            'name' => $name,
            'phone' => $phone,
            'preferred_time_slot' => $timeSlot,
            'department_id' => $departmentId,
            'assigned_user_id' => $assignedUserId
        ]);

        // Trigger Notification Email to assigned counselor
        try {
            $recipientEmail = null;
            if ($assignedUserId) {
                $stmtAssigned = $db->prepare("SELECT email, name FROM users WHERE id = :uid AND organization_id = :oid");
                $stmtAssigned->execute([':uid' => $assignedUserId, ':oid' => $orgId]);
                $assignedRow = $stmtAssigned->fetch();
                if ($assignedRow && !empty($assignedRow['email'])) {
                    $recipientEmail = $assignedRow['email'];
                }
            }

            $stmtOrg = $db->prepare("SELECT name FROM organizations WHERE id = :oid");
            $stmtOrg->execute([':oid' => $orgId]);
            $orgRow = $stmtOrg->fetch();
            $orgName = $orgRow['name'] ?? 'College Admissions';

            if ($recipientEmail) {
                \App\Services\EmailService::sendNewLeadNotification($recipientEmail, [
                    'name' => $name,
                    'email' => $email ?: 'Not provided',
                    'phone' => $phone,
                    'program_interest' => "📞 Callback Request — Preferred Time: {$timeSlot} (" . ($topic ?: 'General') . ")"
                ], $orgName);
            }
        } catch (Throwable $e) {
            // Continue without failing response
        }

        Response::success([
            'id' => $callbackId,
            'student_name' => $name,
            'student_phone' => $phone,
            'preferred_time_slot' => $timeSlot,
            'department_id' => $departmentId,
            'assigned_user_id' => $assignedUserId,
            'status' => 'pending'
        ], 'Counselor callback registered successfully', 201);
    }

    /**
     * GET /v1/callbacks — List callbacks with stats and filters for college admin console
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $db = Database::getConnection();

        $statusFilter = trim((string)$request->get('status'));
        $deptFilter = $request->get('department_id') ? (int)$request->get('department_id') : null;
        $assignedFilter = $request->get('assigned_user_id') ? (int)$request->get('assigned_user_id') : null;
        $search = trim((string)$request->get('search'));

        $sql = "
            SELECT cb.*,
                   d.name as department_name,
                   d.icon as department_icon,
                   u.name as assigned_user_name,
                   u.email as assigned_user_email,
                   c.visitor_id,
                   c.page_url
            FROM counselor_callbacks cb
            LEFT JOIN departments d ON cb.department_id = d.id
            LEFT JOIN users u ON cb.assigned_user_id = u.id
            LEFT JOIN conversations c ON cb.conversation_id = c.id
            WHERE cb.organization_id = :org_id
        ";

        $bindParams = [':org_id' => $orgId];

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            $sql .= " AND cb.status = :status";
            $bindParams[':status'] = $statusFilter;
        }

        if ($deptFilter) {
            $sql .= " AND cb.department_id = :dept_id";
            $bindParams[':dept_id'] = $deptFilter;
        }

        if ($assignedFilter) {
            $sql .= " AND cb.assigned_user_id = :assigned_id";
            $bindParams[':assigned_id'] = $assignedFilter;
        }

        if (!empty($search)) {
            $sql .= " AND (cb.student_name LIKE :search_name OR cb.student_phone LIKE :search_phone OR cb.topic_or_query LIKE :search_topic)";
            $bindParams[':search_name'] = "%{$search}%";
            $bindParams[':search_phone'] = "%{$search}%";
            $bindParams[':search_topic'] = "%{$search}%";
        }

        $sql .= " ORDER BY cb.id DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($bindParams);
        $callbacks = $stmt->fetchAll();

        // Calculate summary statistics
        $stmtStats = $db->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status IN ('scheduled', 'in_progress') THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'no_response' THEN 1 ELSE 0 END) as missed_count
            FROM counselor_callbacks
            WHERE organization_id = :org_id
        ");
        $stmtStats->execute([':org_id' => $orgId]);
        $stats = $stmtStats->fetch();

        Response::success([
            'callbacks' => $callbacks,
            'metrics' => [
                'total' => (int)($stats['total_count'] ?? 0),
                'pending' => (int)($stats['pending_count'] ?? 0),
                'active' => (int)($stats['active_count'] ?? 0),
                'completed' => (int)($stats['completed_count'] ?? 0),
                'missed' => (int)($stats['missed_count'] ?? 0)
            ]
        ]);
    }

    /**
     * GET /v1/callbacks/{id} — Fetch single callback with full conversation transcript
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT cb.*,
                   d.name as department_name,
                   d.icon as department_icon,
                   u.name as assigned_user_name,
                   u.email as assigned_user_email,
                   c.visitor_id,
                   c.page_url
            FROM counselor_callbacks cb
            LEFT JOIN departments d ON cb.department_id = d.id
            LEFT JOIN users u ON cb.assigned_user_id = u.id
            LEFT JOIN conversations c ON cb.conversation_id = c.id
            WHERE cb.id = :id AND cb.organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $callback = $stmt->fetch();

        if (!$callback) {
            Response::error('Callback request not found.', 404);
            return;
        }

        // Fetch chat transcript if conversation_id is linked
        $messages = [];
        if (!empty($callback['conversation_id'])) {
            $stmtMsg = $db->prepare("
                SELECT id, role, content, created_at
                FROM messages
                WHERE conversation_id = :conv_id AND organization_id = :org_id
                ORDER BY id ASC
            ");
            $stmtMsg->execute([':conv_id' => $callback['conversation_id'], ':org_id' => $orgId]);
            $messages = $stmtMsg->fetchAll();
        }

        $callback['transcript'] = $messages;

        Response::success($callback);
    }

    /**
     * PUT /v1/callbacks/{id} — Update callback status, assigned counselor, notes, and call attempts
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $data = $request->all();

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM counselor_callbacks WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Callback request not found.', 404);
            return;
        }

        $validStatuses = ['pending', 'scheduled', 'in_progress', 'completed', 'no_response', 'cancelled'];
        $status = in_array($data['status'] ?? '', $validStatuses) ? $data['status'] : $existing['status'];
        $notes = array_key_exists('counselor_notes', $data) ? $data['counselor_notes'] : $existing['counselor_notes'];
        $timeSlot = !empty($data['preferred_time_slot']) ? trim((string)$data['preferred_time_slot']) : $existing['preferred_time_slot'];
        $callAttempts = isset($data['call_attempts']) ? (int)$data['call_attempts'] : (int)$existing['call_attempts'];
        
        $assignedUserId = array_key_exists('assigned_user_id', $data) 
            ? ($data['assigned_user_id'] ? (int)$data['assigned_user_id'] : null) 
            : $existing['assigned_user_id'];

        $departmentId = array_key_exists('department_id', $data)
            ? ($data['department_id'] ? (int)$data['department_id'] : null)
            : $existing['department_id'];

        $completedAt = $existing['completed_at'];
        if ($status === 'completed' && empty($completedAt)) {
            $completedAt = date('Y-m-d H:i:s');
        } elseif ($status !== 'completed') {
            $completedAt = null;
        }

        $stmtUpdate = $db->prepare("
            UPDATE counselor_callbacks
            SET status = :status,
                counselor_notes = :notes,
                preferred_time_slot = :time_slot,
                call_attempts = :call_attempts,
                assigned_user_id = :assigned_user_id,
                department_id = :department_id,
                completed_at = :completed_at,
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmtUpdate->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':time_slot' => $timeSlot,
            ':call_attempts' => $callAttempts,
            ':assigned_user_id' => $assignedUserId,
            ':department_id' => $departmentId,
            ':completed_at' => $completedAt,
            ':id' => $id,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('callback_updated', 'counselor_callback', $id, [
            'status' => $status,
            'assigned_user_id' => $assignedUserId,
            'call_attempts' => $callAttempts
        ]);

        Response::success([
            'id' => $id,
            'status' => $status,
            'counselor_notes' => $notes,
            'call_attempts' => $callAttempts,
            'assigned_user_id' => $assignedUserId,
            'department_id' => $departmentId,
            'completed_at' => $completedAt
        ], 'Counselor callback updated successfully');
    }

    /**
     * GET /v1/callbacks/export — Export callbacks to CSV format with IST timestamp
     */
    public function export(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT cb.student_name, cb.student_phone, cb.student_email,
                   d.name as department_name,
                   u.name as assigned_counselor_name,
                   cb.preferred_time_slot,
                   cb.topic_or_query,
                   cb.status,
                   cb.call_attempts,
                   cb.counselor_notes,
                   cb.created_at,
                   cb.completed_at
            FROM counselor_callbacks cb
            LEFT JOIN departments d ON cb.department_id = d.id
            LEFT JOIN users u ON cb.assigned_user_id = u.id
            WHERE cb.organization_id = :org_id
            ORDER BY cb.id DESC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=edvora_counselor_callbacks_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Student Name', 'Phone Number', 'Email Address', 'Department', 'Assigned Counselor',
            'Preferred Time Slot', 'Discussion Topic', 'Status', 'Call Attempts', 'Counselor Resolution Notes',
            'Requested Date & Time (IST)', 'Completed Date & Time (IST)'
        ]);

        $tz = new \DateTimeZone('Asia/Kolkata');
        foreach ($rows as $r) {
            $createdIst = 'N/A';
            if (!empty($r['created_at'])) {
                try {
                    $dt = new \DateTime($r['created_at']);
                    $dt->setTimezone($tz);
                    $createdIst = $dt->format('d M Y, h:i A') . ' IST';
                } catch (Throwable $e) {
                    $createdIst = $r['created_at'];
                }
            }

            $completedIst = 'N/A';
            if (!empty($r['completed_at'])) {
                try {
                    $dt2 = new \DateTime($r['completed_at']);
                    $dt2->setTimezone($tz);
                    $completedIst = $dt2->format('d M Y, h:i A') . ' IST';
                } catch (Throwable $e) {
                    $completedIst = $r['completed_at'];
                }
            }

            fputcsv($output, [
                $r['student_name'],
                $r['student_phone'],
                $r['student_email'] ?: 'N/A',
                $r['department_name'] ?: 'General Admissions',
                $r['assigned_counselor_name'] ?: 'Unassigned',
                $r['preferred_time_slot'] ?: 'Immediate',
                $r['topic_or_query'] ?: 'General Inquiry',
                strtoupper(str_replace('_', ' ', $r['status'])),
                $r['call_attempts'],
                $r['counselor_notes'] ?: '',
                $createdIst,
                $completedIst
            ]);
        }

        fclose($output);
        exit;
    }
}
