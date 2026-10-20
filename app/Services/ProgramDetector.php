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
        $cleanQuery = trim(mb_strtolower($query, 'UTF-8'));
        if ($cleanQuery === '') {
            return null;
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

        if (self::isGenericCatalogQuery($query)) {
            // If user asked about a specific degree level (e.g. "what PhD programs do you offer?")
            // and the institution offers ONLY ONE program in that level, resolve to that single program.
            $requestedTypes = null;
            if (preg_match('/\b(phd|ph\.d|doctoral|doctorate)\b/i', $cleanQuery)) {
                $requestedTypes = ['doctoral', 'phd', 'doctorate'];
            } elseif (preg_match('/\b(undergraduate|undergrad|bachelor|bachelors)\b/i', $cleanQuery)) {
                $requestedTypes = ['undergraduate', 'bachelor', 'bachelors', 'ug'];
            } elseif (preg_match('/\b(certificate|certificates|diploma)\b/i', $cleanQuery)) {
                $requestedTypes = ['certificate', 'certificates', 'diploma'];
            }

            if ($requestedTypes !== null) {
                $typeMatches = array_values(array_filter($programs, function ($p) use ($requestedTypes) {
                    return in_array(strtolower(trim($p['program_type'] ?? '')), $requestedTypes, true);
                }));
                if (count($typeMatches) === 1) {
                    return $typeMatches[0];
                }
            }
            return null;
        }

        $normQuery = self::normalizeForMatch($cleanQuery);

        $exactNameMatches = [];
        $exactCodeMatches = [];
        $partialMatches = [];

        foreach ($programs as $prog) {
            $name = mb_strtolower($prog['course_name'], 'UTF-8');
            $normName = self::normalizeForMatch($name);
            $code = !empty($prog['course_code']) ? mb_strtolower(trim($prog['course_code']), 'UTF-8') : null;

            // 1. Direct exact name match (or normalized '&' <-> 'and' / punctuation match)
            if (str_contains($cleanQuery, $name) || ($normName !== '' && str_contains($normQuery, $normName))) {
                $exactNameMatches[] = $prog;
                continue;
            }

            // 2. Direct course code match (e.g. "WEMBA", "MQM-BA", "MBCS")
            if ($code && strlen($code) >= 3 && preg_match('/\b' . preg_quote($code, '/') . '\b/i', $cleanQuery)) {
                // Ensure the code is not a generic degree term shared inside other programs' names
                $codeSharedInOtherNames = false;
                foreach ($programs as $otherProg) {
                    if ((int)$otherProg['id'] === (int)$prog['id']) {
                        continue;
                    }
                    $otherNameLower = mb_strtolower($otherProg['course_name'], 'UTF-8');
                    if (preg_match('/\b' . preg_quote($code, '/') . '\b/i', $otherNameLower)) {
                        $codeSharedInOtherNames = true;
                        break;
                    }
                }
                if (!$codeSharedInOtherNames) {
                    $exactCodeMatches[] = $prog;
                    continue;
                }
            }

            // 3. Sub-name match without generic degree prefixes
            $subName = preg_replace('/^(b\.?s\.?|b\.?a\.?|b\.?tech\.?|m\.?s\.?|m\.?a\.?|m\.?tech\.?|b\.?b\.?a\.?|m\.?b\.?a\.?|ph\.?d\.?|diploma|certificate|master|bachelor)\s+(in|of)?\s*/i', '', $name);
            $subName = trim($subName);
            $normSubName = self::normalizeForMatch($subName);
            if (!empty($subName) && strlen($subName) >= 5 && (str_contains($cleanQuery, $subName) || ($normSubName !== '' && str_contains($normQuery, $normSubName)))) {
                $partialMatches[] = $prog;
                continue;
            }

            $genericPartStopwords = [
                'business', 'management', 'administration', 'studies', 'science', 'sciences',
                'foundation', 'foundations', 'university', 'college', 'school', 'program', 'programs',
                'course', 'courses', 'degree', 'degrees', 'master', 'masters', 'bachelor', 'bachelors',
                'certificate', 'certificates', 'diploma', 'doctoral', 'doctorate', 'accelerated', 'online'
            ];
            $parts = preg_split('/\s*(&|and|:|,|\(|\))\s*/i', $subName);
            $partMatched = false;
            foreach ($parts as $part) {
                $part = trim($part);
                if (strlen($part) >= 4 && !in_array($part, $genericPartStopwords, true) && preg_match('/\b' . preg_quote($part, '/') . '\b/i', $cleanQuery)) {
                    $partialMatches[] = $prog;
                    $partMatched = true;
                    break;
                }
            }
            if ($partMatched) {
                continue;
            }

            // 4. Dynamic self-derived acronym / abbreviation match
            $aliases = self::getProgramAliases($name);
            foreach ($aliases as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $cleanQuery)) {
                    $partialMatches[] = $prog;
                    break;
                }
            }
        }

        // Deduplicate exact name matches and resolve substring shadowing
        // (e.g. "Accelerated Daytime MBA" shadows "Daytime MBA")
        $uniqueExactName = [];
        foreach ($exactNameMatches as $p) {
            $uniqueExactName[(int)$p['id']] = $p;
        }
        if (count($uniqueExactName) > 1) {
            $filteredExact = [];
            foreach ($uniqueExactName as $idA => $progA) {
                $normA = self::normalizeForMatch(mb_strtolower($progA['course_name'], 'UTF-8'));
                $isShadowed = false;
                foreach ($uniqueExactName as $idB => $progB) {
                    if ($idA === $idB) continue;
                    $normB = self::normalizeForMatch(mb_strtolower($progB['course_name'], 'UTF-8'));
                    if ($normA !== $normB && str_contains($normB, $normA)) {
                        $isShadowed = true;
                        break;
                    }
                }
                if (!$isShadowed) {
                    $filteredExact[$idA] = $progA;
                }
            }
            $uniqueExactName = $filteredExact;
        }

        // 1a. If exactly one course has an exact name match, return it
        if (count($uniqueExactName) === 1) {
            return reset($uniqueExactName);
        }
        if (count($uniqueExactName) > 1) {
            return null;
        }

        // 1b. If exactly one course has an unambiguous course code match, return it
        $uniqueExactCode = [];
        foreach ($exactCodeMatches as $p) {
            $uniqueExactCode[(int)$p['id']] = $p;
        }
        if (count($uniqueExactCode) === 1) {
            return reset($uniqueExactCode);
        }
        if (count($uniqueExactCode) > 1) {
            return null;
        }

        $uniquePartial = [];
        foreach ($partialMatches as $p) {
            $uniquePartial[(int)$p['id']] = $p;
        }

        // 2. If exactly one course matched via partial/alias, return it
        if (count($uniquePartial) === 1) {
            return reset($uniquePartial);
        }
        // If multiple partial matches exist (e.g. "MBA" matching both Daytime MBA and Weekend Executive MBA):
        if (count($uniquePartial) > 1) {
            // Check if user specified a distinguishing keyword (length >= 3) that belongs to ONLY one of the candidates
            $candidateScores = [];
            $queryTokens = preg_split('/[\s,\.\?\!\-\(\)\/:]+/', $cleanQuery);
            $queryTokens = array_filter($queryTokens, fn($t) => strlen($t) >= 3);

            foreach ($uniquePartial as $pId => $prog) {
                $pNameTokens = preg_split('/[\s,\.\?\!\-\(\)\/:]+/', mb_strtolower($prog['course_name'], 'UTF-8'));
                $matchingUniqueTokens = 0;
                foreach ($queryTokens as $qTok) {
                    if (in_array($qTok, $pNameTokens, true)) {
                        $sharedWithOther = false;
                        foreach ($uniquePartial as $otherId => $otherProg) {
                            if ($otherId === $pId) continue;
                            $otherTokens = preg_split('/[\s,\.\?\!\-\(\)\/:]+/', mb_strtolower($otherProg['course_name'], 'UTF-8'));
                            if (in_array($qTok, $otherTokens, true)) {
                                $sharedWithOther = true;
                                break;
                            }
                        }
                        if (!$sharedWithOther) {
                            $matchingUniqueTokens++;
                        }
                    }
                }
                if ($matchingUniqueTokens > 0) {
                    $candidateScores[$pId] = $matchingUniqueTokens;
                }
            }

            // If exactly one candidate has unique distinguishing words from the user query, select it
            if (count($candidateScores) === 1) {
                $winnerId = array_key_first($candidateScores);
                return $uniquePartial[$winnerId];
            }

            // Otherwise it is an umbrella/multi-program inquiry. Do NOT prematurely lock; let the LLM present all options.
            return null;
        }

        // Do NOT guess program interest from RAG context sources when the user's query did not match any program token.
        // Semantic program detection for informal phrasings is handled by the LLM's detected_program_id output.
        return null;
    }

    private static function normalizeForMatch(string $text): string
    {
        $norm = str_replace('&', ' and ', $text);
        $norm = preg_replace('/[^a-z0-9\s]/i', ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm);
        return trim($norm);
    }

    /**
     * Dynamically derive acronyms and natural abbreviations strictly from the program's own name.
     */
    private static function getProgramAliases(string $courseName): array
    {
        $aliases = [];
        $lower = strtolower($courseName);

        // Extract parenthetical acronyms e.g. "(MBCS)" -> "mbcs"
        if (preg_match_all('/\(([a-z0-9\-]{2,10})\)/i', $lower, $matches)) {
            foreach ($matches[1] as $acr) {
                $aliases[] = strtolower($acr);
            }
        }

        // Extract colon prefix acronym e.g. "MQM: Business Analytics" -> "mqm"
        if (preg_match('/^([a-z]{2,8}):/i', $lower, $m)) {
            $aliases[] = strtolower($m[1]);
        }

        if (str_contains($lower, 'executive mba') || str_contains($lower, 'emba')) {
            $aliases = array_merge($aliases, ['executive mba', 'emba', 'mba']);
        } elseif (str_contains($lower, 'mba')) {
            $aliases = array_merge($aliases, ['mba', 'master of business administration']);
        }

        if (str_contains($lower, 'phd') || str_contains($lower, 'ph.d')) {
            $aliases = array_merge($aliases, ['phd', 'ph.d', 'doctorate', 'doctoral']);
        }

        return array_values(array_unique($aliases));
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

            // Retrieve conversation visitor info if available
            $stmtConvData = $db->prepare("SELECT visitor_name, visitor_email, visitor_phone FROM conversations WHERE id = :cid AND organization_id = :oid");
            $stmtConvData->execute([':cid' => $convId, ':oid' => $orgId]);
            $convData = $stmtConvData->fetch(PDO::FETCH_ASSOC);

            $shiftNote = "\n[Program interest updated to " . $programName . " on " . date('Y-m-d H:i:s') . "]";
            $insertNotes = 'Identified interest in ' . $programName . ' during admissions counseling.';

            // Step 2: Check if a lead row already exists for this conversation (e.g. chat_capture, program_interest, general)
            $stmtExisting = $db->prepare("
                SELECT id, program_interest, notes, name, email, phone, conversion_score
                FROM leads
                WHERE conversation_id = :cid AND organization_id = :oid
                ORDER BY id DESC LIMIT 1
            ");
            $stmtExisting->execute([':cid' => $convId, ':oid' => $orgId]);
            $existingLead = $stmtExisting->fetch(PDO::FETCH_ASSOC);

            if ($existingLead) {
                $newNotes = $existingLead['notes'] ?? '';
                if (($existingLead['program_interest'] ?? '') !== $programName) {
                    $newNotes .= $shiftNote;
                }
                $vName = (!empty($convData['visitor_name']) && $convData['visitor_name'] !== 'Prospective Student') ? $convData['visitor_name'] : null;
                $vEmail = !empty($convData['visitor_email']) ? $convData['visitor_email'] : null;
                $vPhone = !empty($convData['visitor_phone']) ? $convData['visitor_phone'] : null;

                $targetName = (!empty($existingLead['name']) && $existingLead['name'] !== 'Prospective Student') ? $existingLead['name'] : ($vName ?: 'Prospective Student');
                $targetEmail = !empty($existingLead['email']) ? $existingLead['email'] : $vEmail;
                $targetPhone = !empty($existingLead['phone']) ? $existingLead['phone'] : $vPhone;

                $stmtUpdate = $db->prepare("
                    UPDATE leads
                    SET program_id = :pid,
                        program_interest = :pname,
                        notes = :notes,
                        name = :name,
                        email = :email,
                        phone = :phone,
                        updated_at = NOW()
                    WHERE id = :id AND organization_id = :oid
                ");
                $stmtUpdate->execute([
                    ':pid'    => $programId,
                    ':pname'  => $programName,
                    ':notes'  => $newNotes,
                    ':name'   => $targetName,
                    ':email'  => $targetEmail,
                    ':phone'  => $targetPhone,
                    ':id'     => (int)$existingLead['id'],
                    ':oid'    => $orgId
                ]);
                return;
            }

            // Step 3: Round-robin staff assignment (always computed upfront for INSERT path)
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

            $leadName = (!empty($convData['visitor_name']) && $convData['visitor_name'] !== 'Prospective Student') ? $convData['visitor_name'] : 'Prospective Student';
            $leadEmail = !empty($convData['visitor_email']) ? $convData['visitor_email'] : null;
            $leadPhone = !empty($convData['visitor_phone']) ? $convData['visitor_phone'] : null;

            // Step 4: Atomic UPSERT — INSERT on first detection, UPDATE on shift
            $stmtUpsert = $db->prepare("
                INSERT INTO leads (
                    organization_id, chatbot_id, conversation_id, program_id, assigned_user_id,
                    name, email, phone, program_interest, lead_type, status, pipeline_stage,
                    conversion_score, conversion_score_rationale, notes, created_at, updated_at
                ) VALUES (
                    :oid, :bot_id, :cid, :pid, :assigned_uid,
                    :name, :email, :phone, :pname, 'program_interest', 'new', 'qualified',
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
            $stmtUpsert->bindValue(':oid',          $orgId,          PDO::PARAM_INT);
            $stmtUpsert->bindValue(':bot_id',       $botId,          PDO::PARAM_INT);
            $stmtUpsert->bindValue(':cid',          $convId,         PDO::PARAM_INT);
            $stmtUpsert->bindValue(':pid',          $programId,      PDO::PARAM_INT);
            if ($assignedUserId !== null) {
                $stmtUpsert->bindValue(':assigned_uid', $assignedUserId, PDO::PARAM_INT);
            } else {
                $stmtUpsert->bindValue(':assigned_uid', null,            PDO::PARAM_NULL);
            }
            $stmtUpsert->bindValue(':name',         $leadName,       PDO::PARAM_STR);
            $stmtUpsert->bindValue(':email',        $leadEmail,      $leadEmail ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpsert->bindValue(':phone',        $leadPhone,      $leadPhone ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmtUpsert->bindValue(':pname',        $programName,    PDO::PARAM_STR);
            $stmtUpsert->bindValue(':insert_notes', $insertNotes,    PDO::PARAM_STR);
            $stmtUpsert->bindValue(':shift_note',   $shiftNote,      PDO::PARAM_STR);
            $stmtUpsert->execute();

        } catch (Throwable $e) {
            error_log('[ProgramDetector] syncProgramLead error: ' . $e->getMessage());
        }
    }
}