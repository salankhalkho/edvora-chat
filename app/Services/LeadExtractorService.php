<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\AuditLogger;
use PDO;
use Throwable;

class LeadExtractorService
{
    /**
     * Common words following "I am" / "I'm" / "This is" that indicate intent or state, NOT a personal name.
     */
    private const STOP_WORDS = [
        'interested', 'looking', 'a', 'an', 'the', 'student', 'inquiring', 'enquiring',
        'wondering', 'applying', 'planning', 'trying', 'here', 'ready', 'calling',
        'writing', 'asking', 'from', 'currently', 'working', 'graduated', 'confused',
        'seeking', 'hoping', 'waiting', 'not', 'just', 'searching', 'curious',
        'willing', 'able', 'going', 'new', 'good', 'fine', 'okay', 'ok', 'hello',
        'hi', 'hey', 'glad', 'please', 'thanks', 'thank', 'sure', 'sorry',
        'also', 'actually', 'already', 'still', 'now', 'today', 'thinking', 'reaching'
    ];

    /**
     * Domain words that can NEVER be a person's name in a college admissions context.
     */
    private const DOMAIN_TERMS = [
        'admission', 'admissions', 'fee', 'fees', 'course', 'courses', 'program',
        'programs', 'syllabus', 'curriculum', 'college', 'university', 'campus',
        'hostel', 'scholarship', 'scholarships', 'placement', 'placements', 'mba',
        'btech', 'mtech', 'bba', 'bca', 'mca', 'phd', 'degree', 'engineering',
        'science', 'arts', 'management', 'law', 'medicine', 'commerce', 'exam',
        'eligibility', 'cutoff', 'ranking', 'hostels', 'faculty', 'department'
    ];

    /**
     * Placeholder dummy emails to ignore.
     */
    private const DUMMY_EMAILS = [
        'example@example.com', 'test@test.com', 'user@domain.com', 'admin@example.com',
        'user@example.com', 'email@example.com', 'name@example.com', 'sample@example.com'
    ];

    /**
     * Proactively extract email, phone number, and name from user message.
     */
    public static function extract(string $message): array
    {
        $rawMessage = trim($message);
        if ($rawMessage === '') {
            return [
                'has_contact_info' => false,
                'has_lead_contact' => false,
                'email'            => null,
                'phone'            => null,
                'name'             => null,
            ];
        }

        $email = self::extractEmail($rawMessage);
        $phone = self::extractPhone($rawMessage);
        $name  = null; // Delegated to LLM semantic JSON analysis to eliminate false positives

        $hasLeadContact = ($email !== null || $phone !== null);
        $hasContactInfo = $hasLeadContact;

        return [
            'has_contact_info' => $hasContactInfo,
            'has_lead_contact' => $hasLeadContact,
            'email'            => $email,
            'phone'            => $phone,
            'name'             => $name,
        ];
    }

    /**
     * Extract and validate email address.
     */
    public static function extractEmail(string $text): ?string
    {
        if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $candidate = rtrim(trim($candidate), '.,;:!?)>"\'');
                $candidate = strtolower($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    if (!in_array($candidate, self::DUMMY_EMAILS, true)) {
                        return $candidate;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Extract and normalize phone number.
     */
    public static function extractPhone(string $text): ?string
    {
        // 1. Keyword context: e.g. "phone: 9876543210", "call me at +91 98765 43210", "whatsapp: +1 (555) 234-5678"
        if (preg_match('/(?:phone|mobile|cell|call|whatsapp|contact|tel|ph|contact\s*no|phone\s*no|mobile\s*no|cell\s*no|number|no)\s*(?:is|at|:|#|\-|\.)?\s*([+\d\(\)\s.\-]{7,25})/i', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) >= 7 && strlen($digits) <= 15 && !self::isFalsePositivePhone($m[1], $text)) {
                return self::cleanPhone($m[1]);
            }
        }

        // 2. Explicit international prefix: e.g. "+91 98765 43210", "+1-555-123-4567", "+44 7911 123456"
        if (preg_match('/(?:\+|00)[1-9]\d{0,2}[\s.\-]?(?:\(?\d{1,5}\)?[\s.\-]?)?\d{2,4}[\s.\-]?\d{3,4}/', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[0]);
            if (strlen($digits) >= 8 && strlen($digits) <= 15 && !self::isFalsePositivePhone($m[0], $text)) {
                return self::cleanPhone($m[0]);
            }
        }

        // 3. Formatted phone formats: e.g. "(555) 123-4567", "555-123-4567", "555.123.4567", "98765-43210"
        if (preg_match('/(?:\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4})/', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[0]);
            if (strlen($digits) === 10 && !self::isFalsePositivePhone($m[0], $text)) {
                return self::cleanPhone($m[0]);
            }
        }
        if (preg_match('/\b(\d{5}[\s.\-]\d{5})\b/', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if (strlen($digits) === 10 && !self::isFalsePositivePhone($m[1], $text)) {
                return self::cleanPhone($m[1]);
            }
        }

        // 4. Standalone 10-digit mobile number: e.g. "9876543210" (starting with 6-9 in India or 2-9 in NANP)
        if (preg_match('/\b([6-9]\d{9})\b/', $text, $m)) {
            if (!self::isFalsePositivePhone($m[1], $text)) {
                return self::cleanPhone($m[1]);
            }
        }
        if (preg_match('/\b(0[6-9]\d{9})\b/', $text, $m)) {
            if (!self::isFalsePositivePhone($m[1], $text)) {
                return self::cleanPhone($m[1]);
            }
        }

        return null;
    }

    /**
     * Check if a candidate phone string is a false positive (fees, years, scores, dates).
     */
    private static function isFalsePositivePhone(string $candidate, string $fullText): bool
    {
        $digits = preg_replace('/\D/', '', $candidate);

        // Repetitive identical digits: e.g. "0000000000", "1111111111"
        if (preg_match('/^(\d)\1+$/', $digits)) {
            return true;
        }

        // Check if preceded by currency symbols ($ € £ ₹ Rs INR USD) or fee words
        $pos = strpos($fullText, $candidate);
        if ($pos !== false) {
            $before = substr($fullText, max(0, $pos - 20), min(20, $pos));
            if (preg_match('/[\$€£₹]|(?:fee|fees|cost|price|budget|rs|inr|usd)\s*[:\s]?$/i', $before)) {
                return true;
            }
            $after = substr($fullText, $pos + strlen($candidate), 20);
            if (preg_match('/^\s*(?:%|percent|percentile|usd|inr|rs|k|lakhs|lpa)/i', $after)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format phone number string.
     */
    private static function cleanPhone(string $raw): string
    {
        $trimmed = trim($raw, " \t\n\r\0\x0B.,;:!?)>\"'");
        if (str_starts_with($trimmed, '+')) {
            return '+' . preg_replace('/\D/', '', $trimmed);
        }
        return preg_replace('/\D/', '', $trimmed);
    }

    /**
     * Extract personal name from message.
     */
    public static function extractName(string $text, ?string $email = null, ?string $phone = null): ?string
    {
        // 1. Explicit prefixes: "my name is ...", "i am ...", "i'm ...", "this is ...", "name: ..."
        if (preg_match('/\b(?:my name is|i am|i\'m|im|this is|myself|name\s*[:\-])\s+([^,.\n!?;]+)/i', $text, $m)) {
            $candidateStr = trim($m[1]);
            $name = self::cleanCandidateName($candidateStr);
            if ($name !== null) {
                return $name;
            }
        }

        // 2. Structured delimiters when email or phone is present (e.g. "John Doe, john@gmail.com, 9876543210")
        if ($email !== null || $phone !== null) {
            $parts = preg_split('/[,;\n|]+/', $text);
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if (empty($trimmed)) continue;
                if ($email && str_contains(strtolower($trimmed), strtolower($email))) continue;
                if ($phone && str_contains(preg_replace('/\D/', '', $trimmed), preg_replace('/\D/', '', $phone))) continue;

                $name = self::cleanCandidateName($trimmed);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Clean and validate candidate name string (supports LLM extracted name or manual input).
     */
    public static function sanitizeName(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || strcasecmp($trimmed, 'null') === 0 || strcasecmp($trimmed, 'none') === 0 || strcasecmp($trimmed, 'n/a') === 0) {
            return null;
        }
        return self::cleanCandidateName($trimmed);
    }

    private static function cleanCandidateName(string $raw): ?string
    {
        $words = preg_split('/\s+/', trim($raw));
        $validWords = [];

        foreach ($words as $w) {
            $cleanW = preg_replace('/[^a-zA-Z\'-]/', '', $w);
            if (empty($cleanW)) break;
            $lowerW = strtolower($cleanW);

            if (in_array($lowerW, self::STOP_WORDS, true)) break;
            if (in_array($lowerW, self::DOMAIN_TERMS, true)) break;
            if (in_array($lowerW, ['and', 'who', 'with', 'for', 'at', 'reach', 'contact', 'email', 'phone', 'call', 'send'], true)) break;

            if (strlen($cleanW) < 2 || strlen($cleanW) > 25) break;

            $validWords[] = ucfirst(strtolower($cleanW));
            if (count($validWords) >= 4) break;
        }

        if (count($validWords) >= 1 && count($validWords) <= 4) {
            // A single-word name must be at least 3 letters
            if (count($validWords) === 1 && strlen($validWords[0]) < 3) {
                return null;
            }
            return implode(' ', $validWords);
        }

        return null;
    }

    /**
     * Proactively synchronize conversational contact info to conversations, leads, visitor_sessions, and usage_logs.
     */
    public static function syncConversationalLead(
        PDO $db,
        int $orgId,
        int $botId,
        int $convId,
        array $contactInfo,
        ?int $programId = null,
        ?string $programInterest = null,
        ?string $sessionId = null,
        ?string $visitorId = null
    ): ?int {
        try {
            $extractedName  = $contactInfo['name'] ?? null;
            $extractedEmail = $contactInfo['email'] ?? null;
            $extractedPhone = $contactInfo['phone'] ?? null;

            if (empty($extractedName) && empty($extractedEmail) && empty($extractedPhone)) {
                return null;
            }

            // Step 1: Query current conversation state
            $stmtConv = $db->prepare("
                SELECT visitor_name, visitor_email, visitor_phone, lead_captured_at, program_id, lead_program_interest
                FROM conversations
                WHERE id = :cid AND organization_id = :oid
            ");
            $stmtConv->execute([':cid' => $convId, ':oid' => $orgId]);
            $convRow = $stmtConv->fetch(PDO::FETCH_ASSOC);

            if (!$convRow) {
                return null;
            }

            // Determine updated conversation values
            $finalName = $extractedName ?: ($convRow['visitor_name'] ?: null);
            $finalEmail = $extractedEmail ?: ($convRow['visitor_email'] ?: null);
            $finalPhone = $extractedPhone ?: ($convRow['visitor_phone'] ?: null);
            $finalProgId = $programId ?: (!empty($convRow['program_id']) ? (int)$convRow['program_id'] : null);
            $finalProgName = $programInterest ?: ($convRow['lead_program_interest'] ?: null);

            $hasLeadContactNow = (!empty($finalEmail) || !empty($finalPhone));
            $isInitialCapture = empty($convRow['lead_captured_at']) && $hasLeadContactNow;

            // Update conversations table
            $nameCollected = (!empty($finalName) && $finalName !== 'Prospective Student') ? 1 : ($convRow['lead_name_collected'] ?? 0);
            $emailCollected = !empty($finalEmail) ? 1 : ($convRow['lead_email_collected'] ?? 0);
            $phoneCollected = !empty($finalPhone) ? 1 : ($convRow['lead_phone_collected'] ?? 0);

            $stmtUpdateConv = $db->prepare("
                UPDATE conversations
                SET visitor_name = :vname,
                    visitor_email = :vemail,
                    visitor_phone = :vphone,
                    lead_name_collected = :name_col,
                    lead_email_collected = :email_col,
                    lead_phone_collected = :phone_col,
                    lead_program_interest = :pname,
                    program_id = :pid,
                    lead_captured_at = IF(:has_contact = 1 AND lead_captured_at IS NULL, NOW(), lead_captured_at)
                WHERE id = :cid AND organization_id = :oid
            ");
            $stmtUpdateConv->execute([
                ':vname'       => $finalName,
                ':vemail'      => $finalEmail,
                ':vphone'      => $finalPhone,
                ':name_col'    => $nameCollected,
                ':email_col'   => $emailCollected,
                ':phone_col'   => $phoneCollected,
                ':pname'       => $finalProgName,
                ':pid'         => $finalProgId,
                ':has_contact' => $hasLeadContactNow ? 1 : 0,
                ':cid'         => $convId,
                ':oid'         => $orgId
            ]);

            // Step 2: Check for existing lead row for this conversation
            $stmtLeadCheck = $db->prepare("
                SELECT id, name, email, phone, program_interest, program_id, lead_type, conversion_score, notes, pipeline_stage, conversion_score_rationale
                FROM leads
                WHERE conversation_id = :cid AND organization_id = :oid
                ORDER BY id DESC LIMIT 1
            ");
            $stmtLeadCheck->execute([':cid' => $convId, ':oid' => $orgId]);
            $existingLead = $stmtLeadCheck->fetch(PDO::FETCH_ASSOC);

            $leadId = null;
            $isNewlyPromoted = false;

            if ($existingLead) {
                // UPDATE existing lead record
                $leadId = (int)$existingLead['id'];

                $leadExistedWithoutContact = (empty($existingLead['email']) && empty($existingLead['phone']));
                if ($leadExistedWithoutContact && $hasLeadContactNow) {
                    $isNewlyPromoted = true;
                }

                $capturedItems = [];
                if ($extractedName) $capturedItems[] = "Name: {$extractedName}";
                if ($extractedEmail) $capturedItems[] = "Email: {$extractedEmail}";
                if ($extractedPhone) $capturedItems[] = "Phone: {$extractedPhone}";
                $captureNote = !empty($capturedItems)
                    ? "\n[Contact proactively captured from chat: " . implode(', ', $capturedItems) . " on " . date('Y-m-d H:i:s') . "]"
                    : "";

                $newNotes = ($existingLead['notes'] ?? '') . $captureNote;
                $newScore = $hasLeadContactNow ? max((int)($existingLead['conversion_score'] ?? 50), 80) : (int)($existingLead['conversion_score'] ?? 50);

                $targetLeadName = (!empty($existingLead['name']) && $existingLead['name'] !== 'Prospective Student')
                    ? $existingLead['name']
                    : ($finalName ?: 'Prospective Student');
                $targetLeadEmail = !empty($existingLead['email']) ? $existingLead['email'] : $finalEmail;
                $targetLeadPhone = !empty($existingLead['phone']) ? $existingLead['phone'] : $finalPhone;
                $targetStage = ($hasLeadContactNow && ($existingLead['pipeline_stage'] ?? 'new') === 'new')
                    ? 'qualified'
                    : ($existingLead['pipeline_stage'] ?? 'new');
                $targetRationale = $hasLeadContactNow
                    ? 'Contact details proactively captured from chat dialogue'
                    : ($existingLead['conversion_score_rationale'] ?? 'Active inquiry exploring academic programs');

                $stmtUpdateLead = $db->prepare("
                    UPDATE leads
                    SET name = :name,
                        email = :email,
                        phone = :phone,
                        program_interest = :prog_name,
                        program_id = :prog_id,
                        session_id = :sid,
                        pipeline_stage = :stage,
                        conversion_score = :score,
                        conversion_score_rationale = :rationale,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :lid AND organization_id = :oid
                ");
                $stmtUpdateLead->execute([
                    ':name'      => $targetLeadName,
                    ':email'     => $targetLeadEmail,
                    ':phone'     => $targetLeadPhone,
                    ':prog_name' => $finalProgName,
                    ':prog_id'   => $finalProgId,
                    ':sid'       => $sessionId ?: null,
                    ':stage'     => $targetStage,
                    ':score'     => $newScore,
                    ':rationale' => $targetRationale,
                    ':notes'     => $newNotes,
                    ':lid'       => $leadId,
                    ':oid'       => $orgId
                ]);
            } else {
                // INSERT new lead record
                $isNewlyPromoted = $hasLeadContactNow;

                // Round-robin counselor assignment
                $assignedUserId = null;
                $stmtRr = $db->prepare("
                    SELECT u.id
                    FROM users u
                    LEFT JOIN leads l ON l.assigned_user_id = u.id AND l.organization_id = :oid1
                    WHERE u.organization_id = :oid2 AND u.role IN ('counselor', 'agent', 'admin', 'owner')
                    GROUP BY u.id
                    ORDER BY COUNT(l.id) ASC, u.id ASC
                    LIMIT 1
                ");
                $stmtRr->execute([':oid1' => $orgId, ':oid2' => $orgId]);
                $rrStaff = $stmtRr->fetch();
                if ($rrStaff) {
                    $assignedUserId = (int)$rrStaff['id'];
                }

                $capturedItems = [];
                if ($extractedName) $capturedItems[] = "Name: {$extractedName}";
                if ($extractedEmail) $capturedItems[] = "Email: {$extractedEmail}";
                if ($extractedPhone) $capturedItems[] = "Phone: {$extractedPhone}";
                $initialNotes = 'Contact details proactively captured during admissions chat session: ' . implode(', ', $capturedItems);

                $stmtInsertLead = $db->prepare("
                    INSERT INTO leads (
                        organization_id, chatbot_id, conversation_id, session_id, program_id, assigned_user_id,
                        name, email, phone, program_interest, lead_type, status, pipeline_stage,
                        conversion_score, conversion_score_rationale, acquisition_source, notes, created_at, updated_at
                    ) VALUES (
                        :oid, :bot_id, :cid, :sid, :pid, :assigned_uid,
                        :name, :email, :phone, :program, 'chat_capture', 'new', :pipeline_stage,
                        :score, :rationale, 'Conversational Chat Ingestion', :notes, NOW(), NOW()
                    )
                ");
                $stmtInsertLead->execute([
                    ':oid'            => $orgId,
                    ':bot_id'         => $botId,
                    ':cid'            => $convId,
                    ':sid'            => $sessionId ?: null,
                    ':pid'            => $finalProgId,
                    ':assigned_uid'   => $assignedUserId,
                    ':name'           => $finalName ?: 'Prospective Student',
                    ':email'          => $finalEmail,
                    ':phone'          => $finalPhone,
                    ':program'        => $finalProgName,
                    ':pipeline_stage' => $hasLeadContactNow ? 'qualified' : 'new',
                    ':score'          => $hasLeadContactNow ? 80 : 50,
                    ':rationale'      => $hasLeadContactNow ? 'Contact details proactively captured from chat dialogue' : 'Conversational inquiry initiated',
                    ':notes'          => $initialNotes
                ]);
                $leadId = (int)$db->lastInsertId();
            }

            // Step 3: Update visitor_sessions conversion status if reachable contact exists
            if ($hasLeadContactNow) {
                if (!empty($sessionId)) {
                    try {
                        $db->prepare("UPDATE visitor_sessions SET conversion_status = 'lead_converted', converted_at = COALESCE(converted_at, NOW()) WHERE session_id = :sid")->execute([':sid' => $sessionId]);
                    } catch (Throwable $e) {}
                } elseif (!empty($visitorId)) {
                    try {
                        $db->prepare("UPDATE visitor_sessions SET conversion_status = 'lead_converted', converted_at = COALESCE(converted_at, NOW()) WHERE visitor_id = :vid AND organization_id = :oid")->execute([':vid' => $visitorId, ':oid' => $orgId]);
                    } catch (Throwable $e) {}
                }
            }

            // Step 4: Update monthly usage log if this is a newly captured lead
            if ($isNewlyPromoted || $isInitialCapture) {
                $period = date('Y-m');
                $db->exec("
                    INSERT INTO usage_logs (organization_id, period, leads_captured)
                    VALUES ({$orgId}, '{$period}', 1)
                    ON DUPLICATE KEY UPDATE leads_captured = leads_captured + 1
                ");

                // Audit log
                AuditLogger::log('lead_captured', 'lead', $leadId, [
                    'source' => 'chat_dialogue',
                    'name'   => $finalName,
                    'email'  => $finalEmail,
                    'phone'  => $finalPhone
                ]);

                // Send instant email notification to counselor/admin
                self::sendLeadNotification($db, $orgId, $leadId, [
                    'name'             => $finalName ?: 'Prospective Student',
                    'email'            => $finalEmail,
                    'phone'            => $finalPhone,
                    'lead_type'        => 'chat_capture',
                    'program_interest' => $finalProgName ?: 'General Inquiry'
                ]);
            }

            return $leadId;

        } catch (Throwable $e) {
            error_log('[LeadExtractorService] syncConversationalLead error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Dispatch notification email for captured conversational lead.
     */
    private static function sendLeadNotification(PDO $db, int $orgId, int $leadId, array $leadData): void
    {
        try {
            // Find assigned counselor or organization owner
            $recipientEmail = null;
            $stmtLead = $db->prepare("SELECT assigned_user_id FROM leads WHERE id = :lid AND organization_id = :oid");
            $stmtLead->execute([':lid' => $leadId, ':oid' => $orgId]);
            $assignedUid = (int)$stmtLead->fetchColumn();

            if ($assignedUid > 0) {
                $stmtAssigned = $db->prepare("SELECT email FROM users WHERE id = :uid AND organization_id = :oid");
                $stmtAssigned->execute([':uid' => $assignedUid, ':oid' => $orgId]);
                $assignedUserRow = $stmtAssigned->fetch();
                if (!empty($assignedUserRow['email'])) {
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
                EmailService::sendNewLeadNotification($notifyEmail, $leadData, $orgName);
            }
        } catch (Throwable $e) {
            error_log('[LeadExtractorService] sendLeadNotification error: ' . $e->getMessage());
        }
    }
}
