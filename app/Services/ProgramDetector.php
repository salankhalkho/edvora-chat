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
        'list of courses',
        'list all courses',
        'list of programs',
        'show courses',
        'show programs',
        'all courses',
        'all programs',
        'available courses',
        'available programs',
        'courses offered',
        'programs offered',
        'what can i study',
        'which degrees do you offer',
        'what degrees are available'
    ];

    public static function detect(PDO $db, int $orgId, string $query, array $contextSources = []): ?array
    {
        $cleanQuery = trim(mb_strtolower($query, 'UTF-8'));

        foreach (self::$genericCatalogKeywords as $broad) {
            if (str_contains($cleanQuery, $broad) && strlen($cleanQuery) <= strlen($broad) + 15) {
                return null;
            }
        }

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

            $stmtConv = $db->prepare("
                UPDATE conversations
                SET lead_program_interest = :pname, program_id = :pid
                WHERE id = :cid AND organization_id = :oid
            ");
            $stmtConv->execute([
                ':pname' => $programName,
                ':pid' => $programId,
                ':cid' => $convId,
                ':oid' => $orgId
            ]);

            $stmtLead = $db->prepare("
                SELECT id, program_interest, program_id
                FROM leads
                WHERE conversation_id = :cid AND organization_id = :oid
                ORDER BY id DESC LIMIT 1
            ");
            $stmtLead->execute([':cid' => $convId, ':oid' => $orgId]);
            $existingLead = $stmtLead->fetch(PDO::FETCH_ASSOC);

            if ($existingLead) {
                $stmtUpdate = $db->prepare("
                    UPDATE leads
                    SET program_interest = :pname, program_id = :pid, updated_at = NOW()
                    WHERE id = :lid
                ");
                $stmtUpdate->execute([
                    ':pname' => $programName,
                    ':pid' => $programId,
                    ':lid' => (int)$existingLead['id']
                ]);
            } else {
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

                $stmtInsert = $db->prepare("
                    INSERT INTO leads (
                        organization_id, chatbot_id, conversation_id, program_id, assigned_user_id,
                        name, program_interest, lead_type, status, pipeline_stage,
                        conversion_score, conversion_score_rationale, notes, created_at, updated_at
                    ) VALUES (
                        :oid, :bot_id, :cid, :pid, :assigned_uid,
                        'Prospective Student', :pname, 'program_interest', 'new', 'qualified',
                        50, 'Academic program interest identified', :notes, NOW(), NOW()
                    )
                ");
                $stmtInsert->bindValue(':oid', $orgId, PDO::PARAM_INT);
                $stmtInsert->bindValue(':bot_id', $botId, PDO::PARAM_INT);
                $stmtInsert->bindValue(':cid', $convId, PDO::PARAM_INT);
                $stmtInsert->bindValue(':pid', $programId, PDO::PARAM_INT);
                if ($assignedUserId !== null) {
                    $stmtInsert->bindValue(':assigned_uid', $assignedUserId, PDO::PARAM_INT);
                } else {
                    $stmtInsert->bindValue(':assigned_uid', null, PDO::PARAM_NULL);
                }
                $stmtInsert->bindValue(':pname', $programName, PDO::PARAM_STR);
                $stmtInsert->bindValue(':notes', 'Identified interest in ' . $programName . ' during admissions counseling.', PDO::PARAM_STR);
                $stmtInsert->execute();
            }
        } catch (Throwable $e) {
            error_log('[ProgramDetector] syncProgramLead error: ' . $e->getMessage());
        }
    }
}