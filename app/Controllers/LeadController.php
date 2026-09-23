<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;

class LeadController
{
    /**
     * POST /v1/leads — Capture new student lead (Public or Widget endpoint)
     */
    public function store(Request $request, array $params = []): void
    {
        $botToken = trim((string)$request->get('bot_token'));
        $name = trim((string)$request->get('name'));
        $email = strtolower(trim((string)$request->get('email')));
        $phone = trim((string)$request->get('phone'));
        $programInterest = trim((string)$request->get('program_interest'));
        $conversationId = (int)$request->get('conversation_id');
        $leadType = trim((string)($request->get('lead_type') ?: 'general'));
        $academicScore = trim((string)$request->get('academic_score'));
        $scholarshipTier = trim((string)$request->get('scholarship_tier'));
        $estimatedWaiver = $request->get('estimated_waiver_amount') !== null ? (float)$request->get('estimated_waiver_amount') : null;
        $evaluationPayload = $request->get('evaluation_payload') ? (is_string($request->get('evaluation_payload')) ? $request->get('evaluation_payload') : json_encode($request->get('evaluation_payload'))) : null;

        if (empty($name) || (empty($email) && empty($phone))) {
            Response::error('Name and at least email or phone number are required.', 422);
        }

        $db = Database::getConnection();

        // Validate bot_token
        $stmtBot = $db->prepare("SELECT id, organization_id FROM chatbots WHERE bot_token = :token AND is_active = 1");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Invalid chatbot token.', 403);
        }

        $orgId = (int)$bot['organization_id'];
        $botId = (int)$bot['id'];

        // Determine conversation_id from visitor_id if not passed directly
        if (!$conversationId && !empty($request->get('visitor_id'))) {
            $stmtConvVid = $db->prepare("SELECT id FROM conversations WHERE organization_id = :oid AND visitor_id = :vid ORDER BY id DESC LIMIT 1");
            $stmtConvVid->execute([':oid' => $orgId, ':vid' => trim((string)$request->get('visitor_id'))]);
            $convRowVid = $stmtConvVid->fetch();
            if ($convRowVid) {
                $conversationId = (int)$convRowVid['id'];
            }
        }

        // Auto-assign lead to active counselor in organization
        $assignedUserId = null;
        $stmtRr = $db->prepare("
            SELECT u.id
            FROM users u
            LEFT JOIN leads l ON l.assigned_user_id = u.id
            WHERE u.organization_id = :oid AND u.role IN ('counselor', 'agent', 'admin', 'owner')
            GROUP BY u.id
            ORDER BY COUNT(l.id) ASC, u.id ASC
            LIMIT 1
        ");
        $stmtRr->execute([':oid' => $orgId]);
        $rrStaff = $stmtRr->fetch();
        if ($rrStaff) {
            $assignedUserId = (int)$rrStaff['id'];
        }

        $stmtLead = $db->prepare("
            INSERT INTO leads (organization_id, chatbot_id, lead_type, conversation_id, assigned_user_id, name, email, phone, program_interest, academic_score, scholarship_tier, estimated_waiver_amount, evaluation_payload, status, created_at, updated_at)
            VALUES (:org_id, :bot_id, :lead_type, :conv_id, :assigned_user_id, :name, :email, :phone, :program, :score, :tier, :waiver, :eval, 'new', NOW(), NOW())
        ");
        $stmtLead->execute([
            ':org_id' => $orgId,
            ':bot_id' => $botId,
            ':lead_type' => $leadType,
            ':conv_id' => $conversationId ?: null,
            ':assigned_user_id' => $assignedUserId ?: null,
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone,
            ':program' => $programInterest,
            ':score' => $academicScore ?: null,
            ':tier' => $scholarshipTier ?: null,
            ':waiver' => $estimatedWaiver,
            ':eval' => $evaluationPayload
        ]);
        $leadId = (int)$db->lastInsertId();

        // Update conversation visitor details if conversation_id provided
        if ($conversationId > 0) {
            $stmtUpdateConv = $db->prepare("
                UPDATE conversations
                SET visitor_name = :name, visitor_email = :email, visitor_phone = :phone,
                    lead_name_collected = 1, lead_email_collected = 1, lead_phone_collected = 1,
                    lead_program_interest = :program, lead_capture_trigger = :trigger, lead_captured_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmtUpdateConv->execute([
                ':name' => $name,
                ':email' => $email,
                ':phone' => $phone,
                ':program' => $programInterest,
                ':trigger' => $leadType,
                ':id' => $conversationId,
                ':org_id' => $orgId
            ]);
        }

        // Update monthly usage log
        $period = date('Y-m');
        $db->exec("
            INSERT INTO usage_logs (organization_id, period, leads_captured)
            VALUES ({$orgId}, '{$period}', 1)
            ON DUPLICATE KEY UPDATE leads_captured = leads_captured + 1
        ");

        AuditLogger::log('lead_captured', 'lead', $leadId, ['name' => $name, 'email' => $email, 'assigned_user_id' => $assignedUserId]);

        // Send instant lead notification email
        $recipientEmail = null;
        if ($assignedUserId) {
            $stmtAssigned = $db->prepare("SELECT email FROM users WHERE id = :uid AND organization_id = :oid");
            $stmtAssigned->execute([':uid' => $assignedUserId, ':oid' => $orgId]);
            $assignedUserRow = $stmtAssigned->fetch();
            if ($assignedUserRow && !empty($assignedUserRow['email'])) {
                $recipientEmail = $assignedUserRow['email'];
            }
        }

        $stmtOwner = $db->prepare("
            SELECT u.email, o.name as org_name
            FROM users u
            JOIN organizations o ON u.organization_id = o.id
            WHERE u.organization_id = :org_id AND u.role IN ('owner', 'admin')
            LIMIT 1
        ");
        $stmtOwner->execute([':org_id' => $orgId]);
        $owner = $stmtOwner->fetch();

        $notifyEmail = $recipientEmail ?: ($owner['email'] ?? null);
        $orgName = $owner['org_name'] ?? 'College Admissions';

        if ($notifyEmail) {
            \App\Services\EmailService::sendNewLeadNotification($notifyEmail, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'program_interest' => $programInterest
            ], $orgName);
        }

        Response::success([
            'id' => $leadId,
            'assigned_user_id' => $assignedUserId,
            'status' => 'new'
        ], 'Lead captured successfully', 201);
    }

    /**
     * Helper method to parse time window and custom date range filters
     */
    private function buildDateFilter(Request $request, string $dateColumn = 'l.created_at'): array
    {
        $window = strtolower(trim((string)($request->get('window') ?? $request->get('time_window') ?? '30d')));
        $startDate = trim((string)$request->get('start_date'));
        $endDate = trim((string)$request->get('end_date'));

        $sql = '';
        $params = [];
        $label = 'Last 30 Days';

        if (!empty($startDate) && !empty($endDate)) {
            $window = 'custom';
            $sql = " AND {$dateColumn} >= :start_date AND {$dateColumn} <= :end_date";
            $params[':start_date'] = $startDate . ' 00:00:00';
            $params[':end_date'] = $endDate . ' 23:59:59';
            $label = date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate));
        } else {
            switch ($window) {
                case '24h':
                    $sql = " AND {$dateColumn} >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                    $label = 'Last 24 Hours';
                    break;
                case '7d':
                    $sql = " AND {$dateColumn} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    $label = 'Last 7 Days';
                    break;
                case '3m':
                    $sql = " AND {$dateColumn} >= DATE_SUB(NOW(), INTERVAL 90 DAY)";
                    $label = 'Last 3 Months';
                    break;
                case '6m':
                    $sql = " AND {$dateColumn} >= DATE_SUB(NOW(), INTERVAL 180 DAY)";
                    $label = 'Last 6 Months';
                    break;
                case 'all':
                    $sql = "";
                    $label = 'All Time';
                    break;
                case '30d':
                default:
                    $window = '30d';
                    $sql = " AND {$dateColumn} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                    $label = 'Last 30 Days';
                    break;
            }
        }

        return [
            'window' => $window,
            'sql' => $sql,
            'params' => $params,
            'label' => $label,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * GET /v1/leads — List all captured leads for college admin with time window & date filters
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $filter = $this->buildDateFilter($request, 'l.created_at');

        $query = "
            SELECT l.*, 
                   c.visitor_id, 
                   c.started_at as chat_started_at,
                   u.name as assigned_user_name,
                   u.email as assigned_user_email
            FROM leads l
            LEFT JOIN conversations c ON l.conversation_id = c.id
            LEFT JOIN users u ON l.assigned_user_id = u.id
            WHERE l.organization_id = :org_id {$filter['sql']}
            ORDER BY l.id DESC
        ";

        $binds = array_merge([':org_id' => $orgId], $filter['params']);
        $stmt = $db->prepare($query);
        $stmt->execute($binds);
        $leads = $stmt->fetchAll() ?: [];

        // Window-synchronized stats for top channel cards
        $cbFilter = $this->buildDateFilter($request, 'created_at');
        $stmtCb = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE organization_id = :org_id {$cbFilter['sql']}");
        $stmtCb->execute(array_merge([':org_id' => $orgId], $cbFilter['params']));
        $cbCount = (int)$stmtCb->fetchColumn();

        $stmtCbPending = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE organization_id = :org_id AND status = 'pending' {$cbFilter['sql']}");
        $stmtCbPending->execute(array_merge([':org_id' => $orgId], $cbFilter['params']));
        $cbPending = (int)$stmtCbPending->fetchColumn();

        $stmtCbDone = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE organization_id = :org_id AND status = 'completed' {$cbFilter['sql']}");
        $stmtCbDone->execute(array_merge([':org_id' => $orgId], $cbFilter['params']));
        $cbDone = (int)$stmtCbDone->fetchColumn();

        $tourFilter = $this->buildDateFilter($request, 'created_at');
        $stmtTour = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE organization_id = :org_id {$tourFilter['sql']}");
        $stmtTour->execute(array_merge([':org_id' => $orgId], $tourFilter['params']));
        $tourCount = (int)$stmtTour->fetchColumn();

        $stmtTourUpcoming = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE organization_id = :org_id AND status IN ('pending', 'confirmed') {$tourFilter['sql']}");
        $stmtTourUpcoming->execute(array_merge([':org_id' => $orgId], $tourFilter['params']));
        $tourUpcoming = (int)$stmtTourUpcoming->fetchColumn();

        $stmtTourDone = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE organization_id = :org_id AND status = 'completed' {$tourFilter['sql']}");
        $stmtTourDone->execute(array_merge([':org_id' => $orgId], $tourFilter['params']));
        $tourDone = (int)$stmtTourDone->fetchColumn();

        $schFilter = $this->buildDateFilter($request, 'created_at');
        $stmtSch = $db->prepare("SELECT COUNT(*) FROM leads WHERE organization_id = :org_id AND (scholarship_tier IS NOT NULL OR lead_type = 'scholarship_eval') {$schFilter['sql']}");
        $stmtSch->execute(array_merge([':org_id' => $orgId], $schFilter['params']));
        $schCount = (int)$stmtSch->fetchColumn();

        $evtFilter = $this->buildDateFilter($request, 'created_at');
        $stmtEvt = $db->prepare("SELECT COUNT(*) FROM lead_journey_events WHERE organization_id = :org_id AND event_type IN ('lead_magnet_sent', 'asset_download') {$evtFilter['sql']}");
        $stmtEvt->execute(array_merge([':org_id' => $orgId], $evtFilter['params']));
        $evtCount = (int)$stmtEvt->fetchColumn();
        if ($evtCount === 0) {
            $stmtAssetDownloads = $db->prepare("SELECT COALESCE(SUM(downloads_count), 0) FROM lead_assets WHERE organization_id = :org_id");
            $stmtAssetDownloads->execute([':org_id' => $orgId]);
            $evtCount = (int)$stmtAssetDownloads->fetchColumn();
        }

        Response::success([
            'leads' => $leads,
            'filter' => [
                'window' => $filter['window'],
                'label' => $filter['label'],
                'start_date' => $filter['start_date'],
                'end_date' => $filter['end_date']
            ],
            'stats' => [
                'total_leads' => count($leads),
                'callbacks_count' => $cbCount,
                'callbacks_pending' => $cbPending,
                'callbacks_done' => $cbDone,
                'tours_count' => $tourCount,
                'tours_upcoming' => $tourUpcoming,
                'tours_done' => $tourDone,
                'scholarships_count' => $schCount,
                'lead_magnets_count' => $evtCount
            ]
        ]);
    }

    /**
     * GET /v1/leads/{id} — Fetch single lead details + conversation chat transcript
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT l.*,
                   u.name as assigned_user_name,
                   u.email as assigned_user_email
            FROM leads l
            LEFT JOIN users u ON l.assigned_user_id = u.id
            WHERE l.id = :id AND l.organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $lead = $stmt->fetch();

        if (!$lead) {
            Response::error('Lead not found.', 404);
        }

        // Fetch conversation messages transcript if conversation_id exists, or fallback to visitor match
        $messages = [];
        $convId = !empty($lead['conversation_id']) ? (int)$lead['conversation_id'] : null;

        if (!$convId && (!empty($lead['email']) || !empty($lead['phone']))) {
            $stmtFindConv = $db->prepare("
                SELECT id FROM conversations 
                WHERE organization_id = :org_id 
                  AND ((:email != '' AND visitor_email = :email) OR (:phone != '' AND visitor_phone = :phone))
                ORDER BY id DESC LIMIT 1
            ");
            $stmtFindConv->execute([
                ':org_id' => $orgId,
                ':email' => !empty($lead['email']) ? $lead['email'] : '',
                ':phone' => !empty($lead['phone']) ? $lead['phone'] : ''
            ]);
            $convId = (int)$stmtFindConv->fetchColumn() ?: null;
        }

        if ($convId) {
            $stmtMsg = $db->prepare("
                SELECT id, role, content, is_fallback, source, created_at
                FROM messages
                WHERE conversation_id = :conv_id AND organization_id = :org_id
                ORDER BY id ASC
            ");
            $stmtMsg->execute([':conv_id' => $convId, ':org_id' => $orgId]);
            $messages = $stmtMsg->fetchAll();
        }

        $lead['transcript'] = $messages;

        Response::success($lead);
    }

    /**
     * PUT /v1/leads/{id} — Update lead status, counselor notes, and assignment
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $data = $request->all();

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM leads WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        if (!$stmt->fetch()) {
            Response::error('Lead not found.', 404);
        }

        $status = in_array($data['status'] ?? '', ['new', 'contacted', 'converted']) ? $data['status'] : 'new';
        $notes = $data['notes'] ?? null;
        $assignedUserId = isset($data['assigned_user_id']) ? ($data['assigned_user_id'] ? (int)$data['assigned_user_id'] : null) : null;

        $stmtUpdate = $db->prepare("
            UPDATE leads
            SET status = :status, 
                notes = :notes, 
                assigned_user_id = :assigned_user_id,
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmtUpdate->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':assigned_user_id' => $assignedUserId,
            ':id' => $id,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('lead_updated', 'lead', $id, ['status' => $status, 'assigned_user_id' => $assignedUserId]);

        Response::success([
            'id' => $id,
            'status' => $status,
            'notes' => $notes,
            'assigned_user_id' => $assignedUserId
        ], 'Lead updated successfully');
    }

    /**
     * DELETE /v1/leads/{id} — Purge student lead record (GDPR / CCPA Right to Erasure)
     */
    public function destroy(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        if (!$id || !$orgId) {
            Response::error('Invalid lead ID', 400);
            return;
        }

        $db = Database::getConnection();
        $stmtFind = $db->prepare("SELECT id, name, email FROM leads WHERE id = :id AND organization_id = :org_id");
        $stmtFind->execute([':id' => $id, ':org_id' => $orgId]);
        $lead = $stmtFind->fetch();

        if (!$lead) {
            Response::error('Lead not found or unauthorized', 404);
            return;
        }

        $stmtDel = $db->prepare("DELETE FROM leads WHERE id = :id AND organization_id = :org_id");
        $stmtDel->execute([':id' => $id, ':org_id' => $orgId]);

        AuditLogger::log('lead_deleted_erasure', 'lead', $id, [
            'name' => $lead['name'],
            'email' => $lead['email'],
            'compliance_reason' => 'Data Subject Erasure Request / Admin purge'
        ]);

        Response::success(['id' => $id], 'Student lead record permanently erased');
    }

    /**
     * GET /v1/leads/export — Export leads to CSV or JSON format with IST timestamp
     */
    public function export(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $filter = $this->buildDateFilter($request, 'l.created_at');

        $query = "
            SELECT l.id, l.name, l.email, l.phone, l.lead_type,
                   u.name as assigned_user_name,
                   l.program_interest, l.status, l.notes, l.created_at
            FROM leads l
            LEFT JOIN users u ON l.assigned_user_id = u.id
            WHERE l.organization_id = :org_id {$filter['sql']}
            ORDER BY l.id DESC
        ";

        $binds = array_merge([':org_id' => $orgId], $filter['params']);
        $stmt = $db->prepare($query);
        $stmt->execute($binds);
        $leads = $stmt->fetchAll() ?: [];

        $format = strtolower((string)($request->get('format') ?? 'csv'));

        $tz = new \DateTimeZone('Asia/Kolkata');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename=edvora_leads_export_' . date('Y-m-d') . '.json');

            $exportData = array_map(function($row) use ($tz) {
                $formattedDate = $row['created_at'];
                if (!empty($row['created_at'])) {
                    try {
                        $dt = new \DateTime($row['created_at']);
                        $dt->setTimezone($tz);
                        $formattedDate = $dt->format('d M Y, h:i A') . ' IST';
                    } catch (\Throwable $e) {}
                }
                return [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'lead_type' => $row['lead_type'] ?: 'general',
                    'assigned_to' => $row['assigned_user_name'] ?: 'Unassigned',
                    'program_interest' => $row['program_interest'],
                    'status' => $row['status'],
                    'notes' => $row['notes'],
                    'created_at_ist' => $formattedDate,
                    'created_at_utc' => $row['created_at']
                ];
            }, $leads);

            echo json_encode([
                'institution_id' => $orgId,
                'exported_at' => date('c'),
                'total_records' => count($exportData),
                'compliance_format' => 'GDPR / CCPA / PIPEDA Data Portability',
                'records' => $exportData
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=edvora_leads_' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Name', 'Email', 'Phone', 'Lead Type', 'Assigned To', 'Program Interest', 'Status', 'Counselor Notes', 'Date & Time Captured (IST)']);

        foreach ($leads as $row) {
            $formattedDate = 'N/A';
            if (!empty($row['created_at'])) {
                try {
                    $dt = new \DateTime($row['created_at']);
                    $dt->setTimezone($tz);
                    $formattedDate = $dt->format('d M Y, h:i A') . ' IST';
                } catch (\Throwable $e) {
                    $formattedDate = $row['created_at'];
                }
            }

            $typeLabel = ucfirst(str_replace('_', ' ', $row['lead_type'] ?: 'general'));

            fputcsv($output, [
                $row['name'],
                $row['email'] ?: 'N/A',
                $row['phone'] ?: 'N/A',
                $typeLabel,
                $row['assigned_user_name'] ?: 'Unassigned',
                $row['program_interest'] ?: 'N/A',
                strtoupper($row['status']),
                $row['notes'] ?: '',
                $formattedDate
            ]);
        }

        fclose($output);
        exit;
    }
}

