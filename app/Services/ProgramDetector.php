<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class ProgramDetector
{
    private static array $genericCatalogKeywords = [
        'what courses are available',
        'what courses do you offer',
        'what programs are available',
        'what programs do you offer',
        'what degrees are available',
        'which degrees do you offer',
        'what courses can i study',
        'what can i study',
        'list of courses',
        'list all courses',
        'list of programs',
        'list all programs',
        'show courses',
        'show programs',
        'all courses',
        'all programs',
        'available courses',
        'available programs',
        'courses offered',
        'programs offered',
        'courses you have',
        'programs you have',
        'degrees offered',
        'degrees available',
        'course list',
        'program list',
        'options for graduation',
        'graduation options',
        'graduation courses',
        'graduation programs',
        'what options do i have for graduation',
        'what are my options for graduation',
        'options for graduate',
        'undergraduate courses',
        'undergraduate programs',
        'undergraduate degrees',
        'undergraduate options',
        'postgraduate courses',
        'postgraduate programs',
        'postgraduate degrees',
        'postgraduate options',
        'graduate courses',
        'graduate programs',
        'graduate degrees',
        'graduate options',
        'bachelors programs',
        'bachelor programs',
        'bachelor degrees',
        'masters programs',
        'master programs',
        'master degrees',
        'doctoral programs',
        'phd programs'
    ];

    private static array $genericFeeKeywords = [
        'what is the fee',
        'what is the fees',
        'what are the fees',
        'how much is the fee',
        'how much are the fees',
        'how much does it cost',
        'fee structure',
        'tuition fee',
        'course fee',
        'program fee'
    ];

    public static function isGenericCatalogQuery(string $query): bool
    {
        $clean = trim(mb_strtolower($query, 'UTF-8'));
        $clean = preg_replace('/[?!.,]/', '', $clean);
        foreach (self::$genericCatalogKeywords as $kw) {
            if (str_contains($clean, $kw)) {
                return true;
            }
        }
        return false;
    }

    public static function isGenericFeeQuery(string $query): bool
    {
        $clean = trim(mb_strtolower($query, 'UTF-8'));
        $clean = preg_replace('/[?!.,]/', '', $clean);
        foreach (self::$genericFeeKeywords as $kw) {
            if (str_contains($clean, $kw) && strlen($clean) <= strlen($kw) + 15) {
                return true;
            }
        }
        return false;
    }

    public static function detect(PDO $db, int $orgId, string $query, array $contextSources = []): ?array
    {
        if (self::isGenericCatalogQuery($query)) {
            return null;
        }

        $cleanQuery = trim(mb_strtolower($query, 'UTF-8'));

        $stmtProgs = $db->prepare("
            SELECT id, course_name, course_code, program_type
            FROM programs
            WHERE organization_id = :org_id AND is_admissions_open = 1
            ORDER BY LENGTH(course_name) DESC
        ");
        $stmtProgs->execute([':org_id' => $orgId]);
        $programs = $stmtProgs->fetchAll(PDO::FETCH_ASSOC);

        if (empty($programs)) {
            return null;
        }

        foreach ($programs as $prog) {
            $name = mb_strtolower($prog['course_name'], 'UTF-8');
            $code = !empty($prog['course_code']) ? mb_strtolower($prog['course_code'], 'UTF-8') : null;

            if (str_contains($cleanQuery, $name)) {
                return $prog;
            }

            if ($code && strlen($code) >= 3 && preg_match('/\b' . preg_quote($code, '/') . '\b/i', $cleanQuery)) {
                return $prog;
            }

            $subName = preg_replace('/^(b\.?s\.?|b\.?tech\.?|m\.?s\.?|m\.?tech\.?|b\.?b\.?a\.?|m\.?b\.?a\.?|ph\.?d\.?|diploma|certificate)\s+(in|of)?\s*/i', '', $name);
            $subName = trim($subName);
            if (!empty($subName) && strlen($subName) >= 5 && str_contains($cleanQuery, $subName)) {
                return $prog;
            }

            $parts = preg_split('/\s*(&|and)\s*/i', $subName);
            foreach ($parts as $part) {
                $part = trim($part);
                if (strlen($part) >= 4 && str_contains($cleanQuery, $part)) {
                    return $prog;
                }
            }

            $aliases = self::getProgramAliases($name);
            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $cleanQuery)) {
                    return $prog;
                }
            }
        }

        foreach ($contextSources as $src) {
            if (!empty($src['program_id'])) {
                foreach ($programs as $prog) {
                    if ((int)$prog['id'] === (int)$src['program_id']) {
                        return $prog;
                    }
                }
            }
            $srcTitle = mb_strtolower($src['title'] ?? '', 'UTF-8');
            foreach ($programs as $prog) {
                $pName = mb_strtolower($prog['course_name'], 'UTF-8');
                if (str_contains($srcTitle, $pName)) {
                    return $prog;
                }
            }
        }

        return null;
    }

    private static function getProgramAliases(string $courseName): array
    {
        $aliases = [];
        $lower = strtolower($courseName);

        if (str_contains($lower, 'computer science')) {
            $aliases = array_merge($aliases, ['computer science', 'comp sci', 'cs', 'cse', 'ai', 'artificial intelligence']);
        }
        if (str_contains($lower, 'finance') || str_contains($lower, 'bba')) {
            $aliases = array_merge($aliases, ['bba', 'finance', 'business administration', 'international finance', 'analytics']);
        }
        if (str_contains($lower, 'data science') || str_contains($lower, 'machine learning')) {
            $aliases = array_merge($aliases, ['data science', 'ds', 'machine learning', 'ml']);
        }
        if (str_contains($lower, 'mba')) {
            $aliases = array_merge($aliases, ['mba', 'executive mba', 'emba', 'management']);
        }
        if (str_contains($lower, 'biomedical')) {
            $aliases = array_merge($aliases, ['biomedical', 'biomedical engineering', 'bme']);
        }
        if (str_contains($lower, 'cyber security') || str_contains($lower, 'cybersecurity')) {
            $aliases = array_merge($aliases, ['cyber security', 'cybersecurity', 'infosec']);
        }

        return $aliases;
    }

    public static function syncProgramLead(PDO $db, int $orgId, int $botId, int $convId, array $program): void
    {
        try {
            $programName = $program['course_name'];
            $programId = (int)$program['id'];

            // Step 1: Update conversations table (same as before)
            $stmtConv = $db->prepare("
                UPDATE conversations
                SET lead_program_interest = :pname, program_id = :pid
                WHERE id = :cid AND organization_id = :oid
            ");
            $stmtConv->execute([
                ':pname' => $programName,
                ':pid'   => $programId,
                ':cid'   => $convId,
                ':oid'   => $orgId
            ]);

            // Step 2: Round-robin staff assignment (always computed upfront for INSERT path)
            $assignedUserId = null;
            $stmtRr = $db->prepare("
                SELECT u.id
                FROM users u
                LEFT JOIN leads l ON l.assigned_user_id = u.id AND l.organization_id = :org_id_1
                WHERE u.organization_id = :org_id_2 AND u.role IN ('counselor', 'agent', 'admin', 'owner')
                GROUP BY u.id
                ORDER BY COUNT(l.id) ASC, u.id ASC
                LIMIT 1
            ");
            $stmtRr->execute([':org_id_1' => $orgId, ':org_id_2' => $orgId]);
            $rrStaff = $stmtRr->fetch();
            if ($rrStaff) {
                $assignedUserId = (int)$rrStaff['id'];
            }

            // Step 3: Atomic UPSERT — INSERT on first detection, UPDATE on shift
            // The unique key uq_leads_conv_type(conversation_id, lead_type) guarantees
            // only one program_interest lead per conversation regardless of concurrency.
            // On duplicate: update program info and append shift note only if program changed.
            $shiftNote = "\n[Program interest updated to " . $programName . " on " . date('Y-m-d H:i:s') . "]";
            $insertNotes = 'Identified interest in ' . $programName . ' during admissions counseling.';

            $stmtUpsert = $db->prepare("
                INSERT INTO leads (
                    organization_id, chatbot_id, conversation_id, program_id, assigned_user_id,
                    name, program_interest, lead_type, status, pipeline_stage,
                    conversion_score, conversion_score_rationale, notes, created_at, updated_at
                ) VALUES (
                    :oid, :bot_id, :cid, :pid, :assigned_uid,
                    'Prospective Student', :pname, 'program_interest', 'new', 'qualified',
                    50, 'Academic program interest identified', :insert_notes, NOW(), NOW()
                )
                ON DUPLICATE KEY UPDATE
                    program_id       = VALUES(program_id),
                    notes            = IF(program_interest != VALUES(program_interest),
                                         CONCAT(COALESCE(notes, ''), :shift_note),
                                         notes),
                    program_interest = VALUES(program_interest),
                    updated_at       = NOW()
            ");
            $stmtUpsert->bindValue(':oid',          $orgId,       PDO::PARAM_INT);
            $stmtUpsert->bindValue(':bot_id',        $botId,       PDO::PARAM_INT);
            $stmtUpsert->bindValue(':cid',           $convId,      PDO::PARAM_INT);
            $stmtUpsert->bindValue(':pid',           $programId,   PDO::PARAM_INT);
            if ($assignedUserId !== null) {
                $stmtUpsert->bindValue(':assigned_uid', $assignedUserId, PDO::PARAM_INT);
            } else {
                $stmtUpsert->bindValue(':assigned_uid', null, PDO::PARAM_NULL);
            }
            $stmtUpsert->bindValue(':pname',        $programName, PDO::PARAM_STR);
            $stmtUpsert->bindValue(':insert_notes', $insertNotes, PDO::PARAM_STR);
            $stmtUpsert->bindValue(':shift_note',   $shiftNote,   PDO::PARAM_STR);
            $stmtUpsert->execute();

        } catch (Throwable $e) {
            error_log('[ProgramDetector] syncProgramLead error: ' . $e->getMessage());
        }
    }
}