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
            $turnStateNotice = "[STATE] Visitor is inquiring about available programs. Group programs neatly by degree level (Undergraduate vs. Graduate) and ask which level they wish to pursue. List all applicable programs completely without cutting off. No conversion offers this turn.";
        } elseif ($leadCaptured) {
            $turnStateNotice = "[STATE] Visitor contact details already collected. Answer questions directly. No offers.";
        } elseif (!$activeProgram) {
            if ($isFeeQuery) {
                $turnStateNotice = "[STATE] Visitor is asking for fees without specifying a program. Ask which program they want fee details for. No offers.";
            } else {
                $turnStateNotice = "[STATE] Visitor is in DISCOVERY stage. Program interest not yet known. Guide program discovery. No offers yet.";
            }
        } elseif (!$canMakeOffer) {
            $progTitle = $activeProgram['course_name'] ?? 'their chosen program';
            $turnStateNotice = "[STATE] Answer this question directly regarding {$progTitle}. Cooldown in effect. No offer this turn.";
        } elseif ($turnCount < $minTurns) {
            $turnStateNotice = "[STATE] Early turn (Greeting/Rapport). Answer warmly. No offers yet.";
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

            $turnStateNotice = "[STATE] Visitor is in CONSIDERATION/DECISION stage for {$progTitle}. Offer eligible. Place ONE natural next step offer in \"follow_up\" only. Available: [{$offersListStr}].{$prohibitionsStr}";
        }

        $knowledgeContext = $contextBlock . "\n" . $progBlock . "\n" . $campusBlock . (!empty($tourSlotsBlock) ? ("\n" . $tourSlotsBlock) : "");

        $prompt = str_replace(
            ['{{COLLEGE_NAME}}', '{{KNOWLEDGE_CONTEXT}}'],
            [$collegeName, $knowledgeContext],
            $masterPrompt
        );

        if (str_contains($prompt, '{{TURN_STATE}}')) {
            $prompt = str_replace('{{TURN_STATE}}', $turnStateNotice, $prompt);
        } else {
            $prompt .= "\n\n" . $turnStateNotice;
        }

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
5. Do NOT set any lead_trigger. Keep it natural, welcoming, and responsive.

=== MANDATORY JSON OUTPUT FORMAT ===
Respond ONLY with a valid JSON object. No markdown. No text outside JSON.
{
  "response": "<warm greeting>",
  "follow_up": null,
  "lead_trigger": null,
  "sentiment": "<positive | neutral | negative>",
  "emotion": "<specific emotion>",
  "frustration": 0.0,
  "conversation_trend": "stable",
  "intent": "general",
  "conversation_stage": "discovery",
  "lead_intent": "low",
  "needs_human": false
}
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

=== MANDATORY JSON OUTPUT FORMAT ===
You MUST respond ONLY with a single valid JSON object. No markdown code fences. No text outside the JSON.
{
  "response": "<your empathetic clarification message>",
  "follow_up": null,
  "lead_trigger": null,
  "sentiment": "<positive | neutral | negative>",
  "emotion": "<specific emotion label>",
  "frustration": <0.0 to 1.0>,
  "conversation_trend": "<improving | stable | declining>",
  "intent": "general",
  "conversation_stage": "discovery",
  "lead_intent": "<low | medium | high>",
  "needs_human": false
}
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
            $grouped = [
                'Undergraduate (Bachelor\'s)' => [],
                'Graduate / Postgraduate (Master\'s)' => [],
                'Doctoral (Ph.D.)' => [],
                'Certificates & Executive' => [],
                'Other Academic Programs' => []
            ];

            foreach ($activeProgs as $p) {
                $type = strtolower(trim($p['program_type'] ?? ''));
                $pStr = $p['course_name'];
                $details = [];
                if (!empty($p['duration'])) $details[] = $p['duration'];
                if (!empty($p['tuition_fee'])) {
                    $curr = $p['currency'] ?? 'USD';
                    $feeFormatted = number_format((float)$p['tuition_fee'], 0);
                    $details[] = "Tuition Fee: {$feeFormatted} {$curr}";
                }
                $meta = !empty($details) ? " (" . implode(', ', $details) . ")" : "";
                $itemStr = "- {$pStr}{$meta}";

                if (in_array($type, ['undergraduate', 'bachelor', 'bachelors', 'ug'], true)) {
                    $grouped['Undergraduate (Bachelor\'s)'][] = $itemStr;
                } elseif (in_array($type, ['postgraduate', 'graduate', 'master', 'masters', 'pg'], true)) {
                    $grouped['Graduate / Postgraduate (Master\'s)'][] = $itemStr;
                } elseif (in_array($type, ['doctoral', 'phd', 'doctorate'], true)) {
                    $grouped['Doctoral (Ph.D.)'][] = $itemStr;
                } elseif (in_array($type, ['certificate', 'diploma', 'executive'], true)) {
                    $grouped['Certificates & Executive'][] = $itemStr;
                } else {
                    $grouped['Other Academic Programs'][] = $itemStr;
                }
            }

            $progBlock .= "\n--- ACTIVE COLLEGE ACADEMIC PROGRAMS & DEGREES ---\n";
            $progBlock .= "Guide visitors using these verified programs grouped by degree level:\n";
            foreach ($grouped as $category => $items) {
                if (!empty($items)) {
                    $progBlock .= "\n[{$category}]:\n" . implode("\n", $items) . "\n";
                }
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
        $block .= "2. If the visitor accepts or says yes, warmly confirm in \"response\", set \"lead_trigger\": \"campus_tour\" in the JSON output.\n";
        $block .= "--- END UPCOMING RELEVANT CAMPUS TOUR SCHEDULES ---\n";

        return $block;
    }

    /**
     * Default Master Prompt — Consultative Admissions Counselor with Journey Steering
     * The LLM MUST return a valid JSON object on every turn.
     */
    public static function getDefaultMasterPrompt(): string
    {
        return <<<'EOT'
You are the AI Admissions Counselor for {{COLLEGE_NAME}}.
Your job is to genuinely help prospective students — and in doing so, guide them naturally toward the next step in their journey: from first question → program interest → counselor contact → campus visit → application.

COUNSELOR MINDSET (apply every turn):
1. UNDERSTAND THE REAL NEED: "What is the fee?" usually means "Can I afford this?" Surface the real concern, then answer it.
2. ANSWER FIRST, GUIDE NEXT: Always give the factual answer immediately. Then, based on where the student is in their journey, offer the single most useful next step.
3. READ THE JOURNEY STAGE: Know where this visitor is:
   - Exploring (asking general questions) → help them find their program.
   - Considering (asking fees, eligibility, scholarships) → give specifics, offer a way to go deeper.
   - Deciding (asking deadlines, payment plans, visit, "how do I apply") → move them to action.
4. BE HONEST ABOUT LIMITS: If the knowledge base does not clearly contain the answer, say: "I don't have that specific detail in my knowledge base right now — our admissions team can confirm it for you." Never guess fees, deadlines, or eligibility criteria.
5. MATCH THE STUDENT: Mirror their language (Hindi, Hinglish, English, Tamil, etc.) and their depth — brief question = brief answer, detailed question = detailed answer.
6. ONE OFFER, ONE TIME: Never repeat an offer. Never stack multiple offers. One natural next step in "follow_up" only, or null.
7. DEGREE LEVEL GUIDANCE:
   - When a visitor asks generally about available programs, courses, or graduation options without specifying a degree level, present the options clearly grouped by degree level (Undergraduate vs. Graduate) and ask which degree level they are looking to pursue.
   - When a visitor specifies a degree level (e.g. undergraduate or master's), list only programs from that specific category. Never mix undergraduate and graduate programs when a specific level was asked.

LEAD CAPTURE GOAL:
Your ultimate goal is to capture the visitor's contact details (name, email, phone) through a genuinely useful offer — a brochure/syllabus, scholarship calculator, counselor callback, or campus tour. These offers are only valuable AFTER you understand their program interest. Move the conversation naturally toward these touchpoints. Never push or pitch — guide.

RESPONSE FORMAT RULES:
- "response": Your direct answer. Typically concise (max ~80 words or 4 bullet points), EXCEPT when the visitor asks for available courses/programs, in which case list all applicable programs completely without cutting off. Plain text only, no markdown. Never include conversion offers or CTAs in this field.
- "follow_up": ONE natural next-step offer or question. Null when [STATE] says no offer, or if no genuinely useful next step applies. This is where you move the visitor forward in their journey.
- Always match the visitor's language in "response" and "follow_up". All other JSON fields stay in English.

{{KNOWLEDGE_CONTEXT}}

{{TURN_STATE}}

Respond ONLY with a single valid JSON object. No markdown code fences. No text outside the JSON.
{
  "response": "<your direct answer — plain text>",
  "follow_up": "<one natural next step, or null>",
  "lead_trigger": "<campus_tour | counselor_callback | asset_delivery | scholarship_eval | null>",
  "sentiment": "<positive | neutral | negative>",
  "emotion": "<curious | anxious | excited | confused | frustrated | satisfied | other>",
  "frustration": <0.0 to 1.0>,
  "conversation_trend": "<improving | stable | declining>",
  "intent": "<fees | admission | scholarship | program | campus | placement | hostel | eligibility | general>",
  "conversation_stage": "<discovery | consideration | decision | application>",
  "lead_intent": "<low | medium | high>",
  "needs_human": <true | false>
}
EOT;
    }
}
