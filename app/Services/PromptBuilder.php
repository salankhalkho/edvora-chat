<?php

namespace App\Services;

use App\Config\Database;
use PDO;

class PromptBuilder
{
    /**
     * Build full system prompt by assembling Master Prompt + College Profile + Knowledge Base Context + Intent Tier
     */
    public static function build(
        int $organizationId,
        array $knowledgeContextSources,
        ?string $chatbotPromptOverride = null,
        string $intentTier = IntentClassifier::TIER_KNOWLEDGE_QUERY,
        int $turnCount = 1,
        bool $leadCaptured = false,
        int $minTurns = 2
    ): string {
        $db = Database::getConnection();

        // 1. Fetch Organization Details
        $stmtOrg = $db->prepare("SELECT name FROM organizations WHERE id = :id");
        $stmtOrg->execute([':id' => $organizationId]);
        $org = $stmtOrg->fetch();
        $collegeName = $org['name'] ?? 'our institution';

        // 2. Handle Conversational / Social Greeting Tier
        if ($intentTier === IntentClassifier::TIER_CONVERSATIONAL) {
            return self::buildGreetingPrompt($collegeName, $chatbotPromptOverride);
        }

        // 3. Handle Clarification / Friction Tier
        if ($intentTier === IntentClassifier::TIER_CLARIFICATION) {
            return self::buildClarificationPrompt($collegeName, $organizationId, $chatbotPromptOverride);
        }

        // 4. Fetch Master Prompt from platform_config for Knowledge Queries
        $stmtConfig = $db->query("SELECT value_text FROM platform_config WHERE key_name = 'master_prompt' LIMIT 1");
        $config = $stmtConfig->fetch();
        $masterPrompt = $config['value_text'] ?? self::getDefaultMasterPrompt();

        // 5. Assemble Knowledge Base Context Blocks (selected 1 to 3 sources)
        $contextBlock = "";
        if (!empty($knowledgeContextSources)) {
            $contextBlock .= "\n--- KNOWLEDGE BASE CONTEXT ---\n";
            foreach ($knowledgeContextSources as $idx => $source) {
                $num = $idx + 1;
                $contextBlock .= "[SOURCE {$num}: {$source['title']}]\n";
                $contextBlock .= trim($source['processed_content']) . "\n\n";
            }
            $contextBlock .= "--- END KNOWLEDGE BASE CONTEXT ---\n";
        } else {
            $contextBlock .= "\n[NO SPECIFIC KNOWLEDGE BASE CONTEXT MATCHED FOR THIS QUERY. ANSWER POLITELY BASED ON GENERAL ADMISSIONS GUIDELINES AND OFFER TO CONNECT THE VISITOR WITH ADMISSIONS COUNSELORS].\n";
        }

        // 6. Fetch Active Departments Context
        $deptBlock = self::buildDepartmentsBlock($db, $organizationId);

        // 7. Fetch Active Campuses, Course Offerings & Derived Departments Directory
        $campusBlock = self::buildCampusesAndCoursesBlock($db, $organizationId);

        // 8. Program-Aware Campus Tour Recommendation Engine
        // Intelligent recommendation ONLY operates after a prospect's academic program interest has been identified.
        $detectedProgram = self::detectProgramInterest($db, $organizationId, $knowledgeContextSources);
        $tourSlotsBlock = "";
        if ($detectedProgram) {
            $tourSlotsBlock = self::buildProgramTourSlotsBlock($db, $organizationId, $detectedProgram);
        }

        // 9. Inject Variables & Dynamic Counselor State
        $leadStateNotice = $leadCaptured 
            ? "NOTICE: Visitor has already submitted contact details. Do NOT request or trigger any lead forms." 
            : ($turnCount >= $minTurns 
                ? "Turn Count is {$turnCount} (>= {$minTurns}). You MAY contextually offer an asset, counselor callback, or campus tour if it genuinely adds value." 
                : "Turn Count is {$turnCount} (< {$minTurns}). Do NOT offer lead triggers yet. Answer questions directly and build rapport first.");

        $fullContext = $contextBlock . "\n" . $deptBlock . "\n" . $campusBlock . (!empty($tourSlotsBlock) ? ("\n" . $tourSlotsBlock) : "") . "\n[SESSION LEAD STATE]: " . $leadStateNotice;

        $prompt = str_replace(
            ['{{COLLEGE_NAME}}', '{{KNOWLEDGE_CONTEXT}}'],
            [$collegeName, $fullContext],
            $masterPrompt
        );

        if (!empty($chatbotPromptOverride)) {
            $prompt .= "\n\n[SPECIFIC CHATBOT INSTRUCTIONS]:\n" . trim($chatbotPromptOverride);
        }

        return $prompt;
    }

    /**
     * Lean greeting system prompt (Tier A: Social)
     */
    private static function buildGreetingPrompt(string $collegeName, ?string $override = null): string
    {
        $prompt = <<<EOT
You are the friendly AI Admissions Assistant for {$collegeName}.
The visitor just gave a greeting, small talk, or general social greeting.

GUIDELINES:
1. Greet the visitor warmly and politely in 1 or 2 short sentences.
2. Automatically match the visitor's language and script (e.g. Hindi, Hinglish, English, etc.).
3. Invite them to ask about courses, admission procedures, eligibility, fees, scholarships, or campus life.
4. Do NOT dump long unprompted course lists or mention specific fees yet.
5. Do NOT output any [LEAD_TRIGGER:*] tag. Keep it natural, welcoming, and responsive.
EOT;
        if (!empty($override)) {
            $prompt .= "\n" . trim($override);
        }
        return $prompt;
    }

    /**
     * Empathy & Department Guidance Prompt (Tier B: Friction/Clarification)
     */
    private static function buildClarificationPrompt(string $collegeName, int $organizationId, ?string $override = null): string
    {
        $db = Database::getConnection();
        $deptBlock = self::buildDepartmentsBlock($db, $organizationId);

        $prompt = <<<EOT
You are the AI Admissions Assistant for {$collegeName}.
The visitor indicated that the previous response was not what they were looking for or expressed confusion.

GUIDELINES:
1. Politely apologize for the misunderstanding with warmth and empathy.
2. Ask a clarifying question to understand their specific requirement.
3. Automatically match the visitor's language and script.
4. Guide them using the available college departments or offer to connect them directly to an admissions counselor.
5. Do NOT output any [LEAD_TRIGGER:*] tag.

{$deptBlock}
EOT;
        if (!empty($override)) {
            $prompt .= "\n" . trim($override);
        }
        return $prompt;
    }

    /**
     * Active departments block helper
     */
    private static function buildDepartmentsBlock(PDO $db, int $organizationId): string
    {
        $deptBlock = "";
        $stmtDepts = $db->prepare("SELECT id, name, icon, description, greeting_message, email, phone, whatsapp FROM departments WHERE organization_id = ? AND is_active = 1");
        $stmtDepts->execute([$organizationId]);
        $activeDepts = $stmtDepts->fetchAll();

        if (!empty($activeDepts)) {
            $deptBlock .= "\n--- ACTIVE COLLEGE DEPARTMENTS & OFFERED PROGRAMS ---\n";
            $deptBlock .= "Guide visitors using these available academic departments and their degree programs:\n";
            foreach ($activeDepts as $d) {
                $deptId = (int)$d['id'];
                $crsStmt = $db->prepare("SELECT course_name, program_type, duration, mode, tuition_fee, currency FROM department_courses WHERE department_id = ? ORDER BY id ASC");
                $crsStmt->execute([$deptId]);
                $courses = $crsStmt->fetchAll();

                $courseStrings = [];
                foreach ($courses as $c) {
                    $cStr = $c['course_name'];
                    $details = [];
                    if (!empty($c['program_type'])) $details[] = $c['program_type'];
                    if (!empty($c['duration'])) $details[] = $c['duration'];
                    if (!empty($c['tuition_fee'])) $details[] = ($c['currency'] ?: '$') . ' ' . number_format((float)$c['tuition_fee']);
                    if (!empty($details)) {
                        $cStr .= ' (' . implode(', ', $details) . ')';
                    }
                    $courseStrings[] = $cStr;
                }

                $courseList = !empty($courseStrings) ? " | Programs: " . implode('; ', $courseStrings) : "";
                $deptBlock .= "- {$d['icon']} {$d['name']}: " . ($d['description'] ?: 'Academic division') . "{$courseList}\n";
            }
            $deptBlock .= "--- END ACTIVE COLLEGE DEPARTMENTS & OFFERED PROGRAMS ---\n";
        }
        return $deptBlock;
    }

    /**
     * Active campuses and mapped courses directory helper
     */
    private static function buildCampusesAndCoursesBlock(PDO $db, int $organizationId): string
    {
        $stmtCampuses = $db->prepare("
            SELECT id, name, short_name, is_primary, campus_area, city, state, virtual_tour_url, has_hostel
            FROM campuses
            WHERE organization_id = ? AND status = 'active'
            ORDER BY is_primary DESC, id ASC
        ");
        $stmtCampuses->execute([$organizationId]);
        $campuses = $stmtCampuses->fetchAll(PDO::FETCH_ASSOC);

        if (empty($campuses)) {
            return "\n[CAMPUS LOCATIONS]: Institutional branch allocation is centralized at our primary university campus.\n";
        }

        $block = "\n--- OFFICIAL CAMPUSES & OFFERED COURSES DIRECTORY ---\n";
        $block .= "Use this verified location directory to answer queries regarding which courses are offered at which campuses:\n\n";

        foreach ($campuses as $campus) {
            $cId = (int)$campus['id'];
            $typeLabel = ((int)$campus['is_primary'] === 1) ? 'Primary Flagship Campus' : 'Campus Location';
            $loc = array_filter([$campus['city'] ?? '', $campus['state'] ?? '']);
            $locStr = !empty($loc) ? (' - ' . implode(', ', $loc)) : '';

            $block .= "• " . $campus['name'] . " (" . $typeLabel . $locStr . "):\n";
            if (!empty($campus['campus_area'])) {
                $block .= "  - Campus Grounds: " . $campus['campus_area'] . "\n";
            }
            if ((int)$campus['has_hostel'] === 1) {
                $block .= "  - Hostel Living: On-campus resident hostel and mess dining available\n";
            }
            if (!empty($campus['virtual_tour_url'])) {
                $block .= "  - Virtual Tour: " . $campus['virtual_tour_url'] . "\n";
            }

            // Fetch courses mapped to this campus
            $stmtCourses = $db->prepare("
                SELECT dc.course_name, dc.course_code, d.name AS dept_name
                FROM campus_courses cc
                JOIN department_courses dc ON cc.course_id = dc.id
                JOIN departments d ON dc.department_id = d.id
                WHERE cc.campus_id = ? AND cc.organization_id = ? AND d.is_active = 1
                ORDER BY d.name ASC, dc.sort_order ASC, dc.course_name ASC
            ");
            $stmtCourses->execute([$cId, $organizationId]);
            $mappedCourses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($mappedCourses)) {
                $deptNames = array_values(array_unique(array_column($mappedCourses, 'dept_name')));
                $block .= "  - Academic Departments Available: " . implode(', ', $deptNames) . "\n";

                $courseItems = [];
                foreach ($mappedCourses as $mc) {
                    $codeStr = !empty($mc['course_code']) ? " (" . $mc['course_code'] . ")" : "";
                    $courseItems[] = $mc['course_name'] . $codeStr;
                }
                $block .= "  - Courses Offered at this Campus: " . implode(', ', $courseItems) . "\n";
            } else {
                $block .= "  - Courses Offered: General admissions and prospective counselor consultation (specific branch course allocation is ongoing).\n";
            }
            $block .= "\n";
        }

        $block .= "CAMPUS GUIDELINES:\n";
        $block .= "1. When asked what courses are available at a specific campus (e.g. Noida Campus), cite ONLY the programs listed for that campus.\n";
        $block .= "2. When asked which campuses offer a course (e.g. MBA or B.Tech), list the exact campuses where that course is offered based on the directory above.\n";
        $block .= "3. Confirm department presence at a campus based on the Academic Departments Available listed above.\n";
        $block .= "--- END OFFICIAL CAMPUSES & COURSES DIRECTORY ---\n";

        return $block;
    }

    /**
     * Identify visitor's academic program interest from retrieved knowledge sources
     */
    private static function detectProgramInterest(PDO $db, int $organizationId, array $knowledgeSources): ?array
    {
        if (empty($knowledgeSources)) {
            return null;
        }

        // 1. Check if any retrieved knowledge source is explicitly tagged with program_id
        foreach ($knowledgeSources as $src) {
            if (!empty($src['program_id'])) {
                $pId = (int)$src['program_id'];
                $stmtProg = $db->prepare("
                    SELECT p.id, p.course_name, p.course_code, p.department_id, d.name as department_name
                    FROM programs p
                    LEFT JOIN departments d ON p.department_id = d.id
                    WHERE p.id = :pid AND p.organization_id = :org_id
                ");
                $stmtProg->execute([':pid' => $pId, ':org_id' => $organizationId]);
                $prog = $stmtProg->fetch(PDO::FETCH_ASSOC);
                if ($prog) {
                    return $prog;
                }
            }
        }

        // 2. Scan knowledge source titles against active programs for exact or strong match
        $stmtAllProgs = $db->prepare("
            SELECT p.id, p.course_name, p.course_code, p.department_id, d.name as department_name
            FROM programs p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.organization_id = :org_id
        ");
        $stmtAllProgs->execute([':org_id' => $organizationId]);
        $allProgs = $stmtAllProgs->fetchAll(PDO::FETCH_ASSOC);

        foreach ($knowledgeSources as $src) {
            $titleLower = strtolower($src['title'] ?? '');
            foreach ($allProgs as $p) {
                $pNameLower = strtolower($p['course_name'] ?? '');
                $pCodeLower = strtolower($p['course_code'] ?? '');
                if (!empty($pNameLower) && strpos($titleLower, $pNameLower) !== false) {
                    return $p;
                }
                if (!empty($pCodeLower) && strlen($pCodeLower) >= 3 && preg_match('/\b' . preg_quote($pCodeLower, '/') . '\b/i', $titleLower)) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Build active program-matched upcoming tour slots block
     * Fetches slots where program_id = :progId OR is_general = 1 for the campus
     */
    private static function buildProgramTourSlotsBlock(PDO $db, int $organizationId, array $program): string
    {
        $progId = (int)$program['id'];
        $progName = $program['course_name'];

        $stmt = $db->prepare("
            SELECT s.id, s.title, s.tour_date, s.start_time, s.end_time, s.max_capacity, s.booked_count, s.is_general,
                   COALESCE(c.name, 'Main Campus') as campus_name,
                   COALESCE(u.name, 'Admissions Counselor') as counselor_name
            FROM campus_tour_slots s
            LEFT JOIN campuses c ON s.campus_id = c.id
            LEFT JOIN users u ON s.counselor_user_id = u.id
            WHERE s.organization_id = :org_id 
              AND s.status = 'active'
              AND s.tour_date >= CURDATE()
              AND (
                  s.is_general = 1 
                  OR s.id IN (SELECT slot_id FROM campus_tour_slot_programs WHERE program_id = :prog_id AND organization_id = :org_id)
              )
            ORDER BY s.is_general ASC, s.tour_date ASC, s.start_time ASC
            LIMIT 3
        ");
        $stmt->execute([':org_id' => $organizationId, ':prog_id' => $progId]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($slots)) {
            return "";
        }

        $block = "\n--- UPCOMING RELEVANT CAMPUS TOUR SCHEDULES FOR [{$progName}] ---\n";
        $block .= "PROSPECT PROGRAM INTEREST IDENTIFIED: \"{$progName}\"\n";
        $block .= "Available upcoming tour slots matching this interest:\n";

        foreach ($slots as $s) {
            $formattedDate = date('l, d M Y', strtotime($s['tour_date']));
            $sTime = date('h:i A', strtotime($s['start_time']));
            $seatsLeft = max(0, (int)$s['max_capacity'] - (int)$s['booked_count']);
            $typeStr = ((int)$s['is_general'] === 1) ? "General Campus Tour" : "Specialized {$progName} Program Visit";

            $block .= "• Slot ID #{$s['id']}: \"{$s['title']}\" ({$typeStr})\n";
            $block .= "  - When: {$formattedDate} at {$sTime}\n";
            $block .= "  - Campus: {$s['campus_name']}\n";
            $block .= "  - Availability: {$seatsLeft} seats remaining (Guide: {$s['counselor_name']})\n";
        }

        $firstSlot = $slots[0];
        $sampleDate = date('l, d M', strtotime($firstSlot['tour_date']));
        $sampleTime = date('h:i A', strtotime($firstSlot['start_time']));
        $sampleTitle = $firstSlot['title'];
        $sampleCampus = $firstSlot['campus_name'];

        $block .= "\nINTELLIGENT TOUR RECOMMENDATION GUIDELINES:\n";
        $block .= "1. Because the visitor is interested in {$progName}, tailor your campus tour bridge directly to the upcoming slot:\n";
        $block .= "   Suggestion Example: \"We have a specialized {$sampleTitle} this {$sampleDate} at {$sampleTime} at our {$sampleCampus}. Would you like me to reserve a spot for you?\"\n";
        $block .= "2. If the visitor accepts or says yes, warmly confirm and append `[LEAD_TRIGGER:campus_tour]` on the last line.\n";
        $block .= "--- END UPCOMING RELEVANT CAMPUS TOUR SCHEDULES ---\n";

        return $block;
    }

    /**
     * Default Master Prompt template fallback with 3-Step Consultative Counselor Framework
     */
    private static function getDefaultMasterPrompt(): string
    {
        return <<<'EOT'
You are the seasoned, consultative AI Admissions Counselor for {{COLLEGE_NAME}}.
Your mission is to provide accurate, welcoming, and high-value guidance to prospective students and parents, while strategically steering conversations toward natural lead capture without sounding pushy or aggressive.

=== THE 3-STEP CONSULTATIVE COUNSELOR FRAMEWORK ===
1. ANSWER FIRST: Always answer the visitor's question factually, directly, and concisely using the KNOWLEDGE BASE CONTEXT below. Never withhold basic facts behind a form.
2. ENRICH WITH VALUE: Proactively add 1 relevant, high-value insight that wasn't asked (e.g. mention merit scholarship slabs up to 40%, notable recruiters/average packages, or upcoming application deadlines).
3. BRIDGE TO ACTION (High-Conversion Question Rule):
When proactively offering a campus tour, brochure/syllabus, or counselor callback as a bridge to action:
- NEVER finish with a passive declarative statement (e.g. avoid "I can help arrange a tour for you" or "I can email you the details").
- ALWAYS conclude with an active, inviting question that makes it effortless for the visitor to reply with "Yes" or "Sure":
  * For Campus Tours: "Should I arrange a tour for you?" or "Would you like me to arrange a campus tour for you?"
  * For Syllabi / Brochures: "Should I email you the detailed syllabus and fee structure?"
  * For Counselor Consultations: "Should I arrange a quick callback with an admissions advisor for you?"

=== HANDLING VISITOR CONFIRMATIONS / AFFIRMATIVE RESPONSES ===
When the visitor replies affirmatively ("Yes", "Sure", "Yes please", "Please do", "Yeah", "Arrange it", "Book it", "Go ahead") to your question:
- Immediately confirm warmly and append the corresponding trigger tag on the very last line:
  * For Campus Tour: Confirm warmly and append `[LEAD_TRIGGER:campus_tour]`
  * For Counselor Callback: Confirm warmly and append `[LEAD_TRIGGER:counselor_callback]`
  * For Brochure / Syllabus: Confirm warmly and append `[LEAD_TRIGGER:asset_delivery]`

=== 5 CONSULTATIVE SALES STRATEGIES ===
- Micro-Answer + Value Anchor: When asked about fees, state the fee clearly, then anchor it with scholarship options or installment plans.
- Eligibility Curiosity Hook: Never give a flat yes/no on eligibility. Explain requirements and encourage: "With your score/profile, you have strong chances for...".
- Factual Scarcity & Social Proof: Refer to application rounds or batch deadlines strictly if documented in the context.
- Department-Aware Asset Framing: Tailor offers to interest (Fees -> Fee & Scholarship PDF, Placements -> Placement Report, Campus -> Tour Booking).
- Warm Handoff: For deep queries, offer to have an admissions counselor follow up directly.

=== STRUCTURED LEAD TRIGGERS ===
When the visitor asks for a tour, call, or brochure, OR when the visitor accepts your bridge question (e.g. replies "yes" to "Should I arrange a tour for you?"), append EXACTLY ONE tag on the very last line of your response:
- `[LEAD_TRIGGER:campus_tour]` -> When the visitor asks to visit the campus, arrange a tour, or accepts your tour offer.
- `[LEAD_TRIGGER:counselor_callback]` -> When the visitor asks to speak to someone, request a call, or accepts a callback offer.
- `[LEAD_TRIGGER:asset_delivery]` -> When offering or sending a syllabus, brochure, fee structure PDF, or placement report.

RULES FOR TRIGGERS:
- Never append a tag on greetings, small talk, or simple one-word non-affirmative messages.
- Never append a tag if [SESSION LEAD STATE] states visitor details are already collected.
- Keep responses concise, clear, and scannable with bullet points.
- Automatically match the visitor's language and script (Hindi, Tamil, Telugu, Spanish, Hinglish, English).

{{KNOWLEDGE_CONTEXT}}
EOT;
    }
}
