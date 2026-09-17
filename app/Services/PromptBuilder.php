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
        int $minTurns = 2,
        bool $canMakeOffer = true,
        ?array $activeProgram = null,
        bool $isCatalogQuery = false,
        bool $isFeeQuery = false
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

        // 6. Fetch Active Programs Context
        $progBlock = self::buildProgramsBlock($db, $organizationId);

        // 7. Fetch Active Campuses & Course Offerings Directory
        $campusBlock = self::buildCampusesAndCoursesBlock($db, $organizationId);

        // 8. Program-Aware Campus Tour Recommendation Engine
        // Intelligent recommendation ONLY operates after a prospect's academic program interest has been identified.
        $detectedProgram = $activeProgram ?? self::detectProgramInterest($db, $organizationId, $knowledgeContextSources);
        $tourSlotsBlock = "";
        if ($detectedProgram && !$isCatalogQuery) {
            $tourSlotsBlock = self::buildProgramTourSlotsBlock($db, $organizationId, $detectedProgram);
        }

        // 9. Inject Dynamic Counselor State & Strict Program-Qualification Rule
        if ($isCatalogQuery) {
            $leadStateNotice = "GENERAL CATALOG QUERY: Visitor is asking about all available courses/degrees/programs. INSTRUCTION: List ALL available college academic programs from the 'ACTIVE COLLEGE ACADEMIC PROGRAMS & DEGREES' block above, neatly categorized by degree level (Undergraduate, Postgraduate, Doctoral, etc.). Do NOT restrict your answer to only one program. Do NOT output [FOLLOW_UP] or any lead pitch. End warmly by asking which field or degree interests them.";
        } elseif ($leadCaptured) {
            $leadStateNotice = "NOTICE: Visitor contact details already collected. DO NOT MAKE ANY OFFER or trigger any lead forms. Focus 100% on answering questions directly.";
        } elseif (!$activeProgram) {
            if ($isFeeQuery) {
                $leadStateNotice = "FEE INQUIRY (NO PROGRAM SPECIFIED): Visitor is asking for course fees without mentioning which program. INSTRUCTION: Politely ask which specific program's fee details they would like to know (mentioning 2-3 popular options). Do NOT make any offer.";
            } else {
                $leadStateNotice = "PROGRAM DISCOVERY PHASE: Visitor program/degree interest is NOT yet known or recorded. STRICT MANDATE: DO NOT MAKE ANY OF THE 4 OFFERS (NO campus tour, NO counselor callback, NO scholarship calculator, NO brochure/lead-magnet). Do NOT output [FOLLOW_UP]. Provide a helpful, concise answer. You may ask an open-ended conversational counseling question to understand their academic interest (e.g. asking what degree or area they wish to pursue).";
            }
        } elseif (!$canMakeOffer) {
            $progTitle = $activeProgram['course_name'] ?? 'their chosen program';
            $leadStateNotice = "ANTI-FATIGUE COOLDOWN IN EFFECT: Visitor is inquiring about {$progTitle}. Answer their specific question directly, factually, and completely using the provided details (including tuition fees if asked). DO NOT MAKE ANY OFFER on this turn. Do NOT mention campus tours, brochures, callbacks, or scholarships. Do NOT output [FOLLOW_UP]. End with a helpful period or polite closer.";
        } elseif ($turnCount < $minTurns) {
            $leadStateNotice = "TURN 1 GREETING & RAPPORT: Turn Count is {$turnCount} (< {$minTurns}). DO NOT MAKE ANY OFFER. Answer the question directly and cleanly without pitch language.";
        } else {
            $progTitle = $activeProgram['course_name'] ?? 'their chosen program';
            $progId = !empty($activeProgram['id']) ? (int)$activeProgram['id'] : 0;

            // Check what offers actually exist for this specific program
            $hasDoc = false;
            $docTitle = '';
            if ($progId > 0) {
                $stmtDocCheck = $db->prepare("
                    SELECT id, title FROM knowledge_sources
                    WHERE organization_id = :oid AND program_id = :pid AND lead_magnet = 1 AND status = 'active' AND file_path IS NOT NULL
                    LIMIT 1
                ");
                $stmtDocCheck->execute([':oid' => $organizationId, ':pid' => $progId]);
                $docRow = $stmtDocCheck->fetch(PDO::FETCH_ASSOC);
                if ($docRow) {
                    $hasDoc = true;
                    $docTitle = $docRow['title'];
                }
            }

            $hasTourSlots = !empty($tourSlotsBlock);

            $stmtSchCheck = $db->prepare("
                SELECT id FROM scholarship_rules
                WHERE organization_id = :oid AND program_id = :pid AND is_active = 1
                LIMIT 1
            ");
            $stmtSchCheck->execute([':oid' => $organizationId, ':pid' => $progId]);
            $hasScholarships = (bool)$stmtSchCheck->fetch();

            $availableOffers = ["admissions counselor callback"];
            $prohibitions = [];

            if ($hasDoc) {
                $availableOffers[] = "official syllabus / fee brochure (\"{$docTitle}\")";
            } else {
                $prohibitions[] = "NO document or brochure (no active lead-magnet document exists in knowledge base for {$progTitle})";
            }

            if ($hasTourSlots) {
                $availableOffers[] = "campus tour of facilities";
            } else {
                $prohibitions[] = "NO campus tour (no scheduled slots for this program)";
            }

            if ($hasScholarships) {
                $availableOffers[] = "merit scholarship evaluation";
            } else {
                $prohibitions[] = "NO scholarship calculation";
            }

            $offersListStr = implode(", ", $availableOffers);
            $prohibitionsStr = !empty($prohibitions) ? (" STRICT PROHIBITIONS: " . implode("; ", $prohibitions) . ".") : "";

            $leadStateNotice = "OFFER ELIGIBLE (PROGRAM QUALIFIED FOR {$progTitle}): Visitor is inquiring about {$progTitle}. INSTRUCTION: Answer their specific question regarding {$progTitle} directly, factually, and completely. NEVER ask the visitor to confirm their interest in {$progTitle}—their inquiry already demonstrates interest! You MAY make ONE tailored next-step offer specifically relevant to {$progTitle} chosen from: [{$offersListStr}].{$prohibitionsStr} MANDATORY: The offer MUST be placed ONLY inside the [FOLLOW_UP] tag on the very last line. Do NOT write ANY offer or pitch in your main answer!";
        }

        $fullContext = $contextBlock . "\n" . $progBlock . "\n" . $campusBlock . (!empty($tourSlotsBlock) ? ("\n" . $tourSlotsBlock) : "") . "\n[SESSION LEAD STATE]: " . $leadStateNotice;

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
     * Empathy & Academic Program Guidance Prompt (Tier B: Friction/Clarification)
     */
    private static function buildClarificationPrompt(string $collegeName, int $organizationId, ?string $override = null): string
    {
        $db = Database::getConnection();
        $progBlock = self::buildProgramsBlock($db, $organizationId);

        $prompt = <<<EOT
You are the AI Admissions Assistant for {$collegeName}.
The visitor indicated that the previous response was not what they were looking for or expressed confusion.

GUIDELINES:
1. Politely apologize for the misunderstanding with warmth and empathy.
2. Ask a clarifying question to understand their specific requirement.
3. Automatically match the visitor's language and script.
4. Guide them using the available college academic programs or offer to connect them directly to an admissions counselor.
5. Do NOT output any [LEAD_TRIGGER:*] tag.

{$progBlock}
EOT;
        if (!empty($override)) {
            $prompt .= "\n" . trim($override);
        }
        return $prompt;
    }

    /**
     * Active academic programs block helper
     */
    private static function buildProgramsBlock(PDO $db, int $organizationId): string
    {
        $progBlock = "";
        $stmtProg = $db->prepare("
            SELECT course_name, course_code, program_type, duration, tuition_fee, total_fee, currency
            FROM programs 
            WHERE organization_id = ? AND is_admissions_open = 1
            ORDER BY sort_order ASC, course_name ASC
        ");
        $stmtProg->execute([$organizationId]);
        $activeProgs = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($activeProgs)) {
            $progBlock .= "\n--- ACTIVE COLLEGE ACADEMIC PROGRAMS & DEGREES ---\n";
            $progBlock .= "Guide visitors using these available academic programs (provide names and durations; state specific fees directly and accurately when the visitor asks for them):\n";
            foreach ($activeProgs as $p) {
                $pStr = $p['course_name'];
                $details = [];
                if (!empty($p['program_type'])) $details[] = ucfirst($p['program_type']);
                if (!empty($p['duration'])) $details[] = $p['duration'];
                if (!empty($p['tuition_fee'])) {
                    $curr = $p['currency'] ?? 'USD';
                    $feeFormatted = number_format((float)$p['tuition_fee'], 0);
                    $details[] = "Tuition Fee: {$feeFormatted} {$curr}";
                }
                $meta = !empty($details) ? " (" . implode(', ', $details) . ")" : "";
                $progBlock .= "- {$pStr}{$meta}\n";
            }
            $progBlock .= "--- END ACTIVE COLLEGE ACADEMIC PROGRAMS & DEGREES ---\n";
        }
        return $progBlock;
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

            // Fetch programs mapped to this campus
            $stmtCourses = $db->prepare("
                SELECT p.course_name, p.course_code
                FROM campus_courses cc
                JOIN programs p ON cc.course_id = p.id
                WHERE cc.campus_id = ? AND cc.organization_id = ? AND p.is_admissions_open = 1
                ORDER BY p.sort_order ASC, p.course_name ASC
            ");
            $stmtCourses->execute([$cId, $organizationId]);
            $mappedCourses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($mappedCourses)) {
                $courseItems = [];
                foreach ($mappedCourses as $mc) {
                    $codeStr = !empty($mc['course_code']) ? " (" . $mc['course_code'] . ")" : "";
                    $courseItems[] = $mc['course_name'] . $codeStr;
                }
                $block .= "  - Programs Offered at this Campus: " . implode(', ', $courseItems) . "\n";
            } else {
                $block .= "  - Programs Offered: General admissions and prospective counselor consultation (specific branch course allocation is ongoing).\n";
            }
            $block .= "\n";
        }

        $block .= "CAMPUS GUIDELINES:\n";
        $block .= "1. When asked what courses are available at a specific campus (e.g. Noida Campus), cite ONLY the programs listed for that campus.\n";
        $block .= "2. When asked which campuses offer a course (e.g. MBA or B.Tech), list the exact campuses where that course is offered based on the directory above.\n";
        $block .= "3. Confirm program availability at a campus based on the Programs Offered at this Campus listed above.\n";
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
                    SELECT p.id, p.course_name, p.course_code
                    FROM programs p
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
            SELECT p.id, p.course_name, p.course_code
            FROM programs p
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
                  OR s.id IN (SELECT slot_id FROM campus_tour_slot_programs WHERE program_id = :prog_id AND organization_id = :sub_org_id)
              )
            ORDER BY s.is_general ASC, s.tour_date ASC, s.start_time ASC
            LIMIT 3
        ");
        $stmt->execute([':org_id' => $organizationId, ':prog_id' => $progId, ':sub_org_id' => $organizationId]);
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

=== RESPONSE LENGTH & PRESENTATION RULES (MANDATORY) ===
- Be concise, compact, and scannable. Avoid vertical spacing bloat.
- Main answer limit: Maximum 80 words OR up to 4-5 short bullet points.
- Never write long walls of text or list unsolicited fees, deadlines, or eligibility unless the visitor specifically asked for them.
- When listing courses, list course names and durations only (e.g. "- B.S. in Computer Science (4 Years)"). Group by degree level without leaving empty lines between bullet items.

=== THE CONSULTATIVE COUNSELOR FRAMEWORK ===
1. NATURAL COUNSELING, INQUIRY IS INTEREST & DIRECT ANSWERS:
- Your goal is to guide prospective students warmly and understand what academic degree or field they are interested in.
- INQUIRY = CONFIRMED INTEREST: When a visitor asks about fees, eligibility, admission dates, or curriculum for ANY specific academic program, their interest in that program is ALREADY 100% qualified and recorded.
- ALWAYS ANSWER DIRECTLY: You must ALWAYS provide the requested facts (fees, duration, eligibility, etc.) directly, accurately, and immediately in your main response. 
- NEVER WITHHOLD FACTS OR DEMAND VERIFICATION: NEVER say "I can provide the fee structure... could you please confirm your interest?". Asking about a course IS the confirmation of interest! Answer their question right away without hesitation.
- DYNAMIC PROGRAM SHIFTING: If a visitor previously inquired about one program and now asks about another (e.g. shifts from B.S. CS to MBA), immediately answer their questions about the NEW program. Never say "You previously asked about X". Follow their lead naturally and fluidly.
- ZERO CONVERSION OFFERS IN MAIN ANSWER: You must NEVER include conversion offers or call-to-actions in your main answer (no offers to book campus tours, send brochures/syllabi/prospectus, schedule callbacks, or evaluate scholarships).
- Polite conversational assistance offers (e.g. "If you need more information about a specific program, feel free to ask!" or "Which field of study interests you most?") are natural and permitted in your main answer.
- ZERO OFFERS BEFORE PROGRAM INTEREST: You must NEVER suggest ANY of the 4 offers (campus tour, brochure/syllabus/prospectus, counselor callback, scholarship calculator) until the student's specific program interest is identified and qualified. When answering general catalog/course queries, help them discover their area of interest first.

2. EXCLUSIVE SPLIT OFFER VIA [FOLLOW_UP]:
If (and ONLY if) [SESSION LEAD STATE] permits an offer AND the visitor's academic program interest has been identified:
- Append your offer on a separate line at the very end using the [FOLLOW_UP] tag:
[FOLLOW_UP] Would you like me to ...?

STRICT RULES FOR [FOLLOW_UP]:
- Only emit [FOLLOW_UP] when permitted by [SESSION LEAD STATE] AND you have answered a substantive program inquiry where a concrete next step genuinely adds value to that program.
- Permitted offers (tailored to their program):
  * Specific Course/Program inquiries -> Offer to email detailed syllabus and fee structure for that program.
  * Campus/Facility inquiries for their program -> Offer to schedule a guided campus tour of the relevant department/labs.
  * Cutoff/Eligibility/Counseling inquiries -> Offer a quick callback with an admissions counselor.
  * Fee/Waiver inquiries -> Offer scholarship evaluation calculator
- The question MUST be specific, helpful, and action-oriented.
- If [SESSION LEAD STATE] states "DO NOT MAKE ANY OFFER" or "PROGRAM DISCOVERY PHASE", you must NOT output any [FOLLOW_UP] tag.
- NEVER put the offer question inside your main answer. Put it ONLY after [FOLLOW_UP].

=== HANDLING VISITOR CONFIRMATIONS / AFFIRMATIVE RESPONSES ===
When the visitor replies affirmatively ("Yes", "Sure", "Yes please", "Please do", "Yeah", "Arrange it", "Book it", "Go ahead") to your previous question:
- Immediately confirm warmly in 1 short sentence and append the corresponding trigger tag on the very last line:
  * For Campus Tour: Confirm warmly and append `[LEAD_TRIGGER:campus_tour]`
  * For Counselor Callback: Confirm warmly and append `[LEAD_TRIGGER:counselor_callback]`
  * For Brochure / Syllabus: Confirm warmly and append `[LEAD_TRIGGER:asset_delivery]`
  * For Scholarship Calculator / Eligibility: Confirm warmly and append `[LEAD_TRIGGER:scholarship_calculator]`

=== STRUCTURED LEAD TRIGGERS ===
When the visitor asks for a tour, call, brochure, or scholarship evaluation, OR when the visitor accepts your follow-up offer, append EXACTLY ONE tag on the very last line:
- `[LEAD_TRIGGER:campus_tour]` -> When the visitor asks to visit the campus, arrange a tour, or accepts your tour offer.
- `[LEAD_TRIGGER:counselor_callback]` -> When the visitor asks to speak to someone, request a call, or accepts a callback offer.
- `[LEAD_TRIGGER:asset_delivery]` -> When offering or sending a syllabus, brochure, fee structure PDF, or placement report.
- `[LEAD_TRIGGER:scholarship_calculator]` -> When evaluating scholarship eligibility, calculating tuition waiver, or checking scholarship criteria.

RULES FOR TRIGGERS:
- Never append a tag on greetings, small talk, or simple non-affirmative messages.
- Never append a tag if [SESSION LEAD STATE] states visitor details are already collected.
- Automatically match the visitor's language and script (Hindi, Tamil, Telugu, Spanish, Hinglish, English, or any other language supported by the LLM).

{{KNOWLEDGE_CONTEXT}}
EOT;
    }
}
