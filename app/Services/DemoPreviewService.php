<?php

namespace App\Services;

use App\Config\Database;
use App\Services\DocumentParser;
use App\Services\LlmService;
use Exception;
use Throwable;

class DemoPreviewService
{
    /**
     * Normalize URL (add https:// if missing, clean trailing slashes)
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            throw new Exception("Invalid website URL provided.");
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        
        return $scheme . '://' . $host . ($path ? $path : '');
    }

    /**
     * Extract clean domain name (e.g. ucla.edu, iitb.ac.in)
     */
    public static function extractDomain(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $host = preg_replace('/^www\./', '', $host);
        return $host ?: 'university.edu';
    }

    /**
     * Analyze website with real cURL scraping and deduplication support
     */
    public static function analyzeWebsite(string $rawUrl): array
    {
        $db = Database::getConnection();
        $normalizedUrl = self::normalizeUrl($rawUrl);
        $domain = self::extractDomain($normalizedUrl);
        $sessionToken = 'prev_' . bin2hex(random_bytes(16));

        // 1. DEDUPLICATION CHECK: Check if we have already successfully scraped this domain
        $stmtPrior = $db->prepare("
            SELECT id, institution_name, pages_found, programs_found, programs_list,
                   has_admissions, has_fees, has_scholarships, scraped_context
            FROM demo_previews
            WHERE domain = :domain AND scrape_status = 'success' AND scraped_context IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmtPrior->execute([':domain' => $domain]);
        $prior = $stmtPrior->fetch();

        if ($prior && !empty($prior['scraped_context'])) {
            // Re-use existing context, create brand-new session for new lead capture
            $stmtInsert = $db->prepare("
                INSERT INTO demo_previews (
                    session_token, website_url, domain, institution_name,
                    scrape_status, pages_found, programs_found, programs_list,
                    has_admissions, has_fees, has_scholarships, scraped_context,
                    context_reused_from, lead_stage, demo_started_at
                ) VALUES (
                    :session_token, :website_url, :domain, :institution_name,
                    'success', :pages_found, :programs_found, :programs_list,
                    :has_admissions, :has_fees, :has_scholarships, :scraped_context,
                    :context_reused_from, 'analyzed', NOW()
                )
            ");
            $stmtInsert->execute([
                ':session_token'      => $sessionToken,
                ':website_url'        => $normalizedUrl,
                ':domain'             => $domain,
                ':institution_name'   => $prior['institution_name'],
                ':pages_found'        => (int)$prior['pages_found'],
                ':programs_found'     => (int)$prior['programs_found'],
                ':programs_list'      => $prior['programs_list'],
                ':has_admissions'     => (int)$prior['has_admissions'],
                ':has_fees'           => (int)$prior['has_fees'],
                ':has_scholarships'   => (int)$prior['has_scholarships'],
                ':scraped_context'    => $prior['scraped_context'],
                ':context_reused_from'=> (int)$prior['id']
            ]);

            $programsArr = array_filter(array_map('trim', explode(',', $prior['programs_list'] ?? '')));

            return [
                'success'           => true,
                'deduplicated'      => true,
                'session_token'     => $sessionToken,
                'institution_name'  => $prior['institution_name'] ?: self::deriveInstitutionName('', $domain),
                'domain'            => $domain,
                'pages_found'       => (int)$prior['pages_found'],
                'programs_found'    => (int)$prior['programs_found'],
                'programs'          => array_values($programsArr),
                'has_admissions'    => (bool)$prior['has_admissions'],
                'has_fees'          => (bool)$prior['has_fees'],
                'has_scholarships'  => (bool)$prior['has_scholarships'],
                'scrape_status'     => 'success'
            ];
        }

        // 2. FRESH REAL cURL SCRAPING
        // Insert initial session placeholder
        $stmtInit = $db->prepare("
            INSERT INTO demo_previews (
                session_token, website_url, domain, lead_stage, demo_started_at
            ) VALUES (
                :session_token, :website_url, :domain, 'analyzing', NOW()
            )
        ");
        $stmtInit->execute([
            ':session_token' => $sessionToken,
            ':website_url'   => $normalizedUrl,
            ':domain'        => $domain
        ]);
        $sessionId = (int)$db->lastInsertId();

        $rootFetch = self::fetchUrlHtml($normalizedUrl, 15);
        if (!$rootFetch['success']) {
            // Record failure in DB for Super Admin visibility
            $errorMsg = $rootFetch['error'] ?: 'Could not connect to website (Timeout or unreachable).';
            $stmtFail = $db->prepare("
                UPDATE demo_previews SET
                    scrape_status = 'failed',
                    scrape_error_reason = :err,
                    lead_stage = 'scrape_failed',
                    institution_name = :inst_fallback
                WHERE id = :id
            ");
            $stmtFail->execute([
                ':err'           => substr($errorMsg, 0, 490),
                ':inst_fallback' => self::deriveInstitutionName('', $domain),
                ':id'            => $sessionId
            ]);

            return [
                'success'       => false,
                'session_token' => $sessionToken,
                'domain'        => $domain,
                'error'         => $errorMsg
            ];
        }

        $rootHtml = $rootFetch['html'];
        $institutionName = self::deriveInstitutionName($rootHtml, $domain);

        // Discover and categorize internal links
        $links = self::extractInternalLinks($rootHtml, $normalizedUrl);
        $pagesFound = max(count($links['all']), 1);
        $hasAdmissions = !empty($links['admissions']);
        $hasFees = !empty($links['fees']);
        $hasScholarships = !empty($links['scholarships']);

        // Collect combined text context
        $rootCleanText = self::cleanHtmlToText($rootHtml);
        $combinedContext = "INSTITUTION: {$institutionName}\nWEBSITE: {$normalizedUrl}\n\n=== HOMEPAGE CONTENT ===\n" . substr($rootCleanText, 0, 5000);

        // Fetch up to 2 high-priority sub-pages (e.g. admissions or academics)
        $priorityUrls = [];
        if (!empty($links['admissions'])) {
            $priorityUrls[] = $links['admissions'][0];
        }
        if (!empty($links['programs'])) {
            $priorityUrls[] = $links['programs'][0];
        } elseif (!empty($links['fees'])) {
            $priorityUrls[] = $links['fees'][0];
        }

        $subPagesFetched = 0;
        foreach (array_unique($priorityUrls) as $subUrl) {
            $subFetch = self::fetchUrlHtml($subUrl, 8);
            if ($subFetch['success'] && !empty($subFetch['html'])) {
                $subClean = self::cleanHtmlToText($subFetch['html']);
                $combinedContext .= "\n\n=== SECTION CONTENT (" . basename($subUrl) . ") ===\n" . substr($subClean, 0, 3500);
                $subPagesFetched++;
            }
        }

        // Extract identified programs
        $discoveredPrograms = self::extractPrograms($rootHtml . ' ' . $combinedContext);
        $programsList = implode(', ', $discoveredPrograms);
        $programsCount = count($discoveredPrograms);

        $scrapeStatus = ($subPagesFetched > 0 || $pagesFound > 3) ? 'success' : 'partial';

        // Cap context size to ~12000 chars
        $finalContext = substr($combinedContext, 0, 12000);

        // Update session in DB
        $stmtUpdate = $db->prepare("
            UPDATE demo_previews SET
                institution_name = :inst_name,
                scrape_status = :scrape_status,
                pages_found = :pages_found,
                programs_found = :programs_found,
                programs_list = :programs_list,
                has_admissions = :has_admissions,
                has_fees = :has_fees,
                has_scholarships = :has_scholarships,
                scraped_context = :context,
                lead_stage = 'analyzed'
            WHERE id = :id
        ");
        $stmtUpdate->execute([
            ':inst_name'        => $institutionName,
            ':scrape_status'    => $scrapeStatus,
            ':pages_found'      => $pagesFound,
            ':programs_found'   => $programsCount,
            ':programs_list'    => $programsList,
            ':has_admissions'   => $hasAdmissions ? 1 : 0,
            ':has_fees'         => $hasFees ? 1 : 0,
            ':has_scholarships' => $hasScholarships ? 1 : 0,
            ':context'          => $finalContext,
            ':id'               => $sessionId
        ]);

        return [
            'success'          => true,
            'deduplicated'     => false,
            'session_token'    => $sessionToken,
            'institution_name' => $institutionName,
            'domain'           => $domain,
            'pages_found'      => $pagesFound,
            'programs_found'   => $programsCount,
            'programs'         => $discoveredPrograms,
            'has_admissions'   => $hasAdmissions,
            'has_fees'         => $hasFees,
            'has_scholarships' => $hasScholarships,
            'scrape_status'    => $scrapeStatus
        ];
    }

    /**
     * Generate Live AI Chatbot reply grounded in the institution's scraped data.
     * Returns reply, source_citation, suggest_counselor flag, and intent_level.
     */
    public static function generateChatResponse(string $sessionToken, string $userMessage, array $history = []): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM demo_previews WHERE session_token = :token LIMIT 1");
        $stmt->execute([':token' => $sessionToken]);
        $session = $stmt->fetch();

        if (!$session) {
            throw new Exception("Demo session not found.");
        }

        $institutionName = $session['institution_name'] ?: 'the university';
        $domain = $session['domain'] ?: 'university.edu';
        $context = $session['scraped_context'] ?: "INSTITUTION: {$institutionName}\nWEBSITE: {$domain}";
        $programs = $session['programs_list'] ?: 'various undergraduate and graduate programs';
        $hasAdmissions = $session['has_admissions'] ? 'Yes' : 'Unknown';
        $hasFees = $session['has_fees'] ? 'Yes' : 'Unknown';
        $hasScholarships = $session['has_scholarships'] ? 'Yes' : 'Unknown';

        $systemPrompt = <<<EOT
You are Edvora, a warm, sharp, and conversion-focused AI admissions counsellor for {$institutionName}. You genuinely care about helping prospective students find the right path. Think of yourself as the best admissions counsellor the university has ever had — knowledgeable, empathetic, and subtly persuasive.

PERSONALITY & TONE RULES (non-negotiable):
- Lead EVERY answer with the most specific, impressive fact you know — accreditation, program length, specialisations, pass rates. NEVER start with "Great question!", "At {$institutionName} we offer...", or any generic opener.
- Validate the student's interest BEFORE answering: "Nursing is one of the most impactful careers you can choose, and RMU's MSN is built for exactly where you want to go." Then answer.
- Be concise: answer in 3–4 sentences maximum, then offer a natural next step. No walls of text.
- Ask ONE sharp, specific follow-up at the end — never vague "feel free to ask" or "anything else?". Make it feel like a real conversation: "Are you looking to specialise as a Family Nurse Practitioner, or are you earlier in your nursing journey?"
- NEVER repeat a question you already asked in this conversation.
- Sound like a person, not a database. Use contractions. Be direct. Show genuine enthusiasm.

CONVERSION PROTOCOL:

TURN 1 (First message from student):
- Answer warmly and specifically (3 sentences max).
- End with ONE engaging follow-up question about their goals/interests.
- DO NOT mention contact details, offers, advisors, or forms on Turn 1.

TURN 2+ (Building trust and offering value):
- Answer their question with specific details.
- Then conclude with ONE relevant value offer:
  * Program/Courses asked → "Would you like me to send you the complete program prospectus and curriculum guide for the {$programs} program?"
  * Fees/Tuition asked → "Our financial aid advisors evaluate scholarship eligibility for merit scholarships and fee waivers. Would you like an advisor to check your eligibility?"
  * Campus/Location asked → "Would you like to schedule a campus tour? I can reserve your spot right now."
  * Eligibility/Undecided → "Would you like to schedule a 15-minute call with an admissions advisor? They can map the right program to your background."

WHEN THE STUDENT ACCEPTS AN OFFER (they say yes/sure/please/great/love that/send it, OR they name a specific program):
- Immediately confirm and ask for contact details. Do NOT ask any clarifying questions first.
  * Prospectus: "I have the curriculum guide and prospectus ready for you! Where should I email or WhatsApp it to you?"
  * Campus Tour: "I'd love to reserve your campus tour spot! What's the best email or phone to confirm your booking?"
  * Scholarship: "Our financial aid team will review your profile right away. Where should they send your scholarship assessment?"
  * Advisor: "I'll connect you with an admissions advisor now. What's the best email or phone number to reach you?"
- On the VERY LAST LINE of your reply (nothing after it), output exactly one of:
  [LEAD_ACTION:prospectus]
  [LEAD_ACTION:tour]
  [LEAD_ACTION:scholarship]
  [LEAD_ACTION:counselor]

ABSOLUTE RULES:
- NEVER say "visit our website", "check {$domain}", "fill out a form online", or "look at our site". You ARE the admissions assistant — handle it here.
- NEVER ask "Would you prefer on-campus or virtual?" or any clarifying question before getting contact details. Go straight to the contact ask.
- NEVER give more than 4 sentences before offering a next step.
- NEVER end a response with a generic "Feel free to ask me anything!" or "I'm here to help."

WHAT YOU KNOW ABOUT {$institutionName}:
- Programs: {$programs}
- Website: {$domain}
- Has admissions info: {$hasAdmissions}
- Has fee/tuition info: {$hasFees}
- Has scholarship info: {$hasScholarships}

FULL SCRAPED WEBSITE CONTENT:
{$context}

SOURCE CITATION FORMAT:
At the end of your factual answer, include: [SOURCE:{$domain}]
EOT;

        $completion = LlmService::complete($systemPrompt, $userMessage, $history);
        $rawReply = trim($completion['text'] ?? $completion['content'] ?? '');

        // Graceful fallback if LLM returned empty
        if (empty($rawReply)) {
            $rawReply = "At {$institutionName}, we offer {$programs}. Could you share more about what you're looking for — program level, schedule, or something else — so I can point you in the right direction?\n[SOURCE:{$domain}]";
        }

        // Extract [LEAD_ACTION:...] tag from reply if present
        $detectedLeadType = null;
        if (preg_match('/\[LEAD_ACTION:(counselor|tour|scholarship|prospectus)\]/i', $rawReply, $leadMatch)) {
            $detectedLeadType = strtolower($leadMatch[1]);
            $rawReply = trim(preg_replace('/\[LEAD_ACTION:[^\]]+\]/i', '', $rawReply));
        }

        // Clean up any stray legacy TRIGGER_LEAD tags if LLM output them
        $rawReply = trim(preg_replace('/\[TRIGGER_LEAD:[^\]]+\]/i', '', $rawReply));

        // Extract [SOURCE:...] tag from reply for structured return (clean display + citation card)
        $sourceCitation = null;
        if (preg_match('/\[SOURCE:([^\]]+)\]/', $rawReply, $sourceMatch)) {
            $sourceDomain = trim($sourceMatch[1]);
            $sourceCitation = "https://{$sourceDomain}";
            $rawReply = trim(preg_replace('/\[SOURCE:[^\]]+\]/', '', $rawReply));
        }
        if (!$sourceCitation) {
            $sourceCitation = "https://{$domain}";
        }

        // ---- CONVERSATIONAL INTENT & AFFIRMATION DETECTION ----
        $msgCount = (int)($session['chat_message_count'] ?? 0) + 1;
        $lowerMsg = trim(strtolower($userMessage));

        $intentTriggered = false;
        $leadType = $detectedLeadType ?: 'counselor';

        // 1. If LLM flagged [LEAD_ACTION:...], it identified a clear conversion moment
        if ($detectedLeadType) {
            $intentTriggered = true;
            $leadType = $detectedLeadType;
        }

        // 2. Direct Explicit User Request Phrases (User explicitly asks for contact/booking/brochure)
        $tourKeywords = ['book a tour', 'schedule a tour', 'schedule tour', 'book tour', 'campus tour', 'visit campus', 'campus visit', 'open house'];
        $scholarshipKeywords = ['evaluate scholarship', 'scholarship eligibility', 'check my scholarship', 'apply for scholarship', 'financial aid evaluation'];
        $prospectusKeywords = ['send prospectus', 'send me the prospectus', 'send brochure', 'email prospectus', 'email syllabus', 'download prospectus', 'send curriculum'];
        $counselorKeywords = [
            'call me', 'contact me', 'speak to an advisor', 'talk to an advisor', 'advisor call',
            'whatsapp me', 'callback', 'call back', 'connect me with admissions',
            'schedule a meeting with an advisor', 'speak with an advisor', 'book an appointment',
            'speak to someone', 'talk to someone'
        ];

        foreach ($tourKeywords as $kw) {
            if (strpos($lowerMsg, $kw) !== false) { $intentTriggered = true; $leadType = 'tour'; break; }
        }
        if (!$intentTriggered) {
            foreach ($scholarshipKeywords as $kw) {
                if (strpos($lowerMsg, $kw) !== false) { $intentTriggered = true; $leadType = 'scholarship'; break; }
            }
        }
        if (!$intentTriggered) {
            foreach ($prospectusKeywords as $kw) {
                if (strpos($lowerMsg, $kw) !== false) { $intentTriggered = true; $leadType = 'prospectus'; break; }
            }
        }
        if (!$intentTriggered) {
            foreach ($counselorKeywords as $kw) {
                if (strpos($lowerMsg, $kw) !== false) { $intentTriggered = true; $leadType = 'counselor'; break; }
            }
        }

        // 3. Broad Conversational Affirmation Matching (e.g. "that would be great", "sounds good", "please", "yes", etc.)
        $affirmationPatterns = [
            '/\b(yes|yeah|yep|sure|ok|okay|please|definitely|sounds good|sounds great|that would be great|that sounds great|i would love that|love that|i\'d love that|send it|send them|let\'s do it|arrange that|perfect|awesome)\b/i',
            '/^(yes|sure|ok|yep|yeah|please|definitely)\b/i'
        ];
        $isAffirmation = false;
        foreach ($affirmationPatterns as $pat) {
            if (preg_match($pat, $lowerMsg)) { $isAffirmation = true; break; }
        }

        if ($isAffirmation && !$intentTriggered) {
            // Inspect previous assistant message from history
            $prevAssistantMsg = '';
            if (!empty($history)) {
                for ($i = count($history) - 1; $i >= 0; $i--) {
                    if (($history[$i]['role'] ?? '') === 'assistant') {
                        $prevAssistantMsg = strtolower($history[$i]['content'] ?? '');
                        break;
                    }
                }
            }

            if (!empty($prevAssistantMsg)) {
                if (strpos($prevAssistantMsg, 'tour') !== false || strpos($prevAssistantMsg, 'visit') !== false) {
                    $intentTriggered = true;
                    $leadType = 'tour';
                } elseif (strpos($prevAssistantMsg, 'scholarship') !== false || strpos($prevAssistantMsg, 'financial aid') !== false || strpos($prevAssistantMsg, 'waiver') !== false) {
                    $intentTriggered = true;
                    $leadType = 'scholarship';
                } elseif (strpos($prevAssistantMsg, 'prospectus') !== false || strpos($prevAssistantMsg, 'brochure') !== false || strpos($prevAssistantMsg, 'catalog') !== false || strpos($prevAssistantMsg, 'syllabus') !== false || strpos($prevAssistantMsg, 'curriculum') !== false) {
                    $intentTriggered = true;
                    $leadType = 'prospectus';
                } elseif (strpos($prevAssistantMsg, 'advisor') !== false || strpos($prevAssistantMsg, 'meeting') !== false || strpos($prevAssistantMsg, 'counselor') !== false || strpos($prevAssistantMsg, 'connect') !== false || strpos($prevAssistantMsg, '?') !== false) {
                    $intentTriggered = true;
                    $leadType = 'counselor';
                }
            }

            // Sanitize reply if it directed to website
            if (preg_match('/(visit\s+[a-z0-9.-]+\s+and\s+look|through\s+our\s+website|on\s+our\s+website)/i', $rawReply)) {
                $leadPhrases = [
                    'tour'        => "I'd be glad to arrange a campus tour for you!",
                    'scholarship' => "I'd be glad to have our financial aid team evaluate your scholarship eligibility!",
                    'prospectus'  => "I'd be happy to send you the comprehensive prospectus and program outline!",
                    'counselor'   => "I'd be delighted to arrange a meeting with an admissions advisor for you!"
                ];
                $rawReply = ($leadPhrases[$leadType] ?? "I'd be happy to set that up for you right now!") . " Where should we reach you?\n[SOURCE:{$domain}]";
                $sourceCitation = "https://{$domain}";
            }
        }

        // 4. Assistant-Driven Trigger Detection:
        // Catches both: (a) delivery phrases ("where should I email"), AND (b) offer phrases ("would you like an advisor to check")
        $assistantOfferPattern = '/(where\s+should\s+(i|we)\s+(email|send|message)|what(\'s|\s+is)\s+the\s+best\s+(email|phone|way)|email\s+or\s+whatsapp|how\s+would\s+you\s+prefer\s+to\s+receive|phone\s+number\s+to\s+reach|best\s+(email|phone)\s+to\s+confirm|would\s+you\s+like\s+(an?\s+)?(advisor|counselor|our\s+(team|financial|admissions))|would\s+you\s+like\s+to\s+(schedule\s+a\s+campus\s+tour|book|reserve)|shall\s+i\s+(connect|arrange|reserve|book)|can\s+i\s+(reserve|arrange|connect|book)|i\s+can\s+reserve\s+your)/i';
        if (preg_match($assistantOfferPattern, $rawReply)) {
            $intentTriggered = true;
            $deliveryPhrases = [
                'prospectus'  => ['prospectus', 'curriculum', 'catalog', 'syllabus', 'brochure'],
                'tour'        => ['campus tour', 'tour schedule', 'visitor guide', 'visitor pass', 'tour', 'reserve your spot', 'book'],
                'scholarship' => ['scholarship', 'financial aid', 'waiver', 'assessment', 'eligibility'],
                'counselor'   => ['advisor', 'counselor', 'call', 'meeting', 'callback', '15-minute', 'connect']
            ];
            foreach ($deliveryPhrases as $type => $keywords) {
                foreach ($keywords as $kw) {
                    if (stripos($rawReply, $kw) !== false) {
                        $leadType = $type;
                        break 2;
                    }
                }
            }
        }

        // ---- INTENT LEVEL CLASSIFICATION (for frontend indicator) ----
        $intentLevel = 'exploring';
        $interestedKeywords = [
            'requirements', 'how long', 'prerequisites', 'duration', 'full time',
            'part time', 'international student', 'entry requirements', 'gpa', 'gmat', 'gre'
        ];
        $highIntentIndicators = [
            'fees', 'cost', 'tuition', 'scholarship', 'financial aid', 'deadline',
            'apply', 'application', 'admission', 'enroll', 'enrollment', 'start date',
            'when can i', 'how much', 'intake', 'tour', 'prospectus', 'meeting', 'advisor', 'yes'
        ];
        foreach (array_merge($counselorKeywords, $highIntentIndicators) as $kw) {
            if (strpos($lowerMsg, $kw) !== false) { $intentLevel = 'high'; break; }
        }
        if ($intentLevel === 'exploring') {
            foreach ($interestedKeywords as $kw) {
                if (strpos($lowerMsg, $kw) !== false) { $intentLevel = 'interested'; break; }
            }
        }
        if ($intentTriggered) {
            $intentLevel = 'high';
        }

        // ---- PERSIST CONVERSATION ----
        $currentLog = [];
        if (!empty($session['conversation_log'])) {
            $decoded = json_decode($session['conversation_log'], true);
            if (is_array($decoded)) { $currentLog = $decoded; }
        }
        $currentLog[] = ['role' => 'user', 'content' => $userMessage, 'time' => time()];
        $currentLog[] = ['role' => 'assistant', 'content' => $rawReply, 'time' => time()];

        $newStage = in_array($session['lead_stage'], ['email_captured', 'analyzed', 'chatted'])
            ? 'chatted' : $session['lead_stage'];

        $stmtUp = $db->prepare("
            UPDATE demo_previews SET
                conversation_log = :conv_log,
                chat_message_count = :msg_cnt,
                lead_stage = :lead_stage
            WHERE id = :id
        ");
        $stmtUp->execute([
            ':conv_log'   => json_encode($currentLog, JSON_UNESCAPED_UNICODE),
            ':msg_cnt'    => $msgCount,
            ':lead_stage' => $newStage,
            ':id'         => $session['id']
        ]);

        return [
            'reply'             => $rawReply,
            'source_citation'   => $sourceCitation,
            'suggest_counselor' => $intentTriggered,
            'lead_type'         => $leadType,
            'intent_level'      => $intentLevel,
            'institution_name'  => $institutionName,
            'message_count'     => $msgCount,
            'detected_program'  => self::detectProgramInText($userMessage, $rawReply, $programs, $history)
        ];
    }

    /**
     * Detect which academic program the student has expressed interest in.
     * Rules:
     * 1. Only attribute interest if the USER explicitly asked about or mentioned a program.
     * 2. If the user sent a follow-up/affirmation ("yes please", "how much is it?"), look back at
     *    prior USER messages in history, or a specific prospectus/program offer from the assistant.
     * 3. NEVER attribute interest from the assistant simply listing programs in a general overview reply.
     */
    private static function detectProgramInText(string $userMessage, string $aiReply, string $programsList, array $history = []): ?string
    {
        $canonical = [
            'master of occupational therapy'       => 'Master of Occupational Therapy',
            'occupational therapy'                 => 'Occupational Therapy',
            'doctor of nursing practice'           => 'Doctor of Nursing Practice',
            'doctor of physical therapy'           => 'Doctor of Physical Therapy',
            'master of science in nursing'         => 'MS in Nursing',
            'master of science in counseling'      => 'MS in Counseling',
            'master of business administration'    => 'MBA',
            'physician assistant'                  => 'Physician Assistant',
            'physical therapy'                     => 'Physical Therapy',
            'family nurse practitioner'            => 'Family Nurse Practitioner',
            'psychiatric mental health'            => 'Psychiatric Mental Health NP',
            'emergency nurse practitioner'         => 'Emergency Nurse Practitioner',
            'adult gerontology'                    => 'Adult Gerontology ACNP',
            'speech language pathology'            => 'Speech-Language Pathology',
            'speech-language pathology'            => 'Speech-Language Pathology',
            'healthcare administration'            => 'Healthcare Administration',
            'health administration'                => 'Health Administration',
            'business administration'              => 'Business Administration',
            'public health'                        => 'Public Health',
            'social work'                          => 'Social Work',
            'data science'                         => 'Data Science',
            'computer science'                     => 'Computer Science',
            'nursing'                              => 'Nursing',
            // Acronyms (checked with word boundaries)
            '\bmot\b'                              => 'Master of Occupational Therapy',
            '\bmsn\b'                              => 'MSN',
            '\bdnp\b'                              => 'DNP',
            '\bdpt\b'                              => 'DPT',
            '\bmba\b'                              => 'MBA',
            '\bmspas\b'                            => 'Physician Assistant (MSPAS)',
            '\bfnp\b'                              => 'Family Nurse Practitioner (FNP)',
            '\bpmhnp\b'                            => 'PMHNP',
            '\benp\b'                              => 'Emergency NP (ENP)',
            '\bagacnp\b'                           => 'AGACNP',
        ];

        // 1. Primary: Check what the USER explicitly typed in this message
        $userLower = strtolower($userMessage);
        foreach ($canonical as $pattern => $label) {
            $isRegex = str_starts_with($pattern, '\b');
            if ($isRegex ? preg_match('/' . $pattern . '/i', $userLower) : (strpos($userLower, $pattern) !== false)) {
                return $label;
            }
        }
        foreach (explode(',', $programsList) as $prog) {
            $prog = trim($prog);
            if ($prog && preg_match('/\b' . preg_quote(strtolower($prog), '/') . '\b/i', $userLower)) {
                return ucwords(strtolower($prog));
            }
        }

        // 2. Secondary: If the user message is a follow-up or affirmation, check previous USER messages in history
        if (!empty($history)) {
            // Check past user messages first
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if (($history[$i]['role'] ?? '') !== 'user') continue;
                $pastUserMsg = strtolower($history[$i]['content'] ?? '');
                if (empty($pastUserMsg)) continue;

                foreach ($canonical as $pattern => $label) {
                    $isRegex = str_starts_with($pattern, '\b');
                    if ($isRegex ? preg_match('/' . $pattern . '/i', $pastUserMsg) : (strpos($pastUserMsg, $pattern) !== false)) {
                        return $label;
                    }
                }
                foreach (explode(',', $programsList) as $prog) {
                    $prog = trim($prog);
                    if ($prog && preg_match('/\b' . preg_quote(strtolower($prog), '/') . '\b/i', $pastUserMsg)) {
                        return ucwords(strtolower($prog));
                    }
                }
            }

            // If user said an affirmation (e.g. "yes please", "sure"), check if the last assistant message was a specific single-program offer
            $affirmationPattern = '/\b(yes|yeah|yep|sure|please|send it|send them|definitely|sounds good|sounds great|that would be great)\b/i';
            if (preg_match($affirmationPattern, $userLower)) {
                for ($i = count($history) - 1; $i >= 0; $i--) {
                    if (($history[$i]['role'] ?? '') === 'assistant') {
                        $prevAssistantMsg = strtolower($history[$i]['content'] ?? '');
                        // Check if assistant was asking about a specific prospectus or program
                        if (preg_match('/(prospectus|curriculum guide|program)\s+for\s+(the\s+)?([a-z\s]+)/i', $prevAssistantMsg, $matchOffer)) {
                            $offerSnippet = $matchOffer[0];
                            foreach ($canonical as $pattern => $label) {
                                $isRegex = str_starts_with($pattern, '\b');
                                if ($isRegex ? preg_match('/' . $pattern . '/i', $offerSnippet) : (strpos($offerSnippet, $pattern) !== false)) {
                                    return $label;
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }

        // If user has not specified a program and is just browsing, return null
        return null;
    }

    /**
     * Capture Work Email (Lead #1)
     */
    public static function captureEmail(string $sessionToken, string $email): array
    {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE demo_previews SET
                work_email = :email,
                email_captured_at = NOW(),
                lead_stage = IF(lead_stage IN ('url_entered', 'analyzed'), 'email_captured', lead_stage)
            WHERE session_token = :token
        ");
        $stmt->execute([
            ':email' => $email,
            ':token' => $sessionToken
        ]);

        return ['success' => true, 'message' => 'Email registered successfully'];
    }

    /**
     * Capture Counselor Request (Lead #2)
     * Records lead in database and triggers real email delivery via EmailService (using SuperAdmin SMTP)
     */
    public static function counselorRequest(string $sessionToken, string $name, string $phone, string $requestType = 'counselor'): array
    {
        $name = trim($name);
        $phone = trim($phone);
        $requestType = strtolower(trim($requestType)) ?: 'counselor';

        if (empty($name)) {
            throw new Exception("Please enter your name.");
        }
        if (empty($phone)) {
            throw new Exception("Please enter your contact details (email or phone).");
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE demo_previews SET
                contact_name = :name,
                contact_phone = :phone,
                counselor_req_at = NOW(),
                lead_stage = 'counselor_requested',
                demo_ended_at = NOW()
            WHERE session_token = :token
        ");
        $stmt->execute([
            ':name'  => $name,
            ':phone' => $phone,
            ':token' => $sessionToken
        ]);

        // Retrieve session context for email delivery
        $stmtSel = $db->prepare("SELECT * FROM demo_previews WHERE session_token = :token LIMIT 1");
        $stmtSel->execute([':token' => $sessionToken]);
        $session = $stmtSel->fetch();

        if ($session) {
            $institutionName = $session['institution_name'] ?: 'the University';
            $domain = $session['domain'] ?: 'university.edu';
            $convLog = !empty($session['conversation_log']) ? (json_decode($session['conversation_log'], true) ?: []) : [];
            $programInterest = self::detectProgramInText('', '', $session['programs_list'] ?? '', $convLog) ?: 'Academic Programs';

            $opportunityLabels = [
                'prospectus'  => 'Prospectus & Curriculum Guide',
                'tour'        => 'Campus Tour Booking',
                'scholarship' => 'Scholarship & Aid Evaluation',
                'counselor'   => 'Admissions Advisor Callback'
            ];
            $opportunityLabel = $opportunityLabels[$requestType] ?? 'Admissions Inquiry';

            // 1. Deliver to Student if they provided an email address
            if (filter_var($phone, FILTER_VALIDATE_EMAIL)) {
                try {
                    EmailService::sendDemoStudentDelivery(
                        $phone,
                        $name,
                        $requestType,
                        $programInterest,
                        $institutionName,
                        $domain
                    );
                } catch (Throwable $e) {
                    error_log("[DemoPreviewService] Failed to send student delivery email: " . $e->getMessage());
                }
            }

            // 2. Alert the University Administrator / Viewer if they provided a work email in Stage 3
            if (!empty($session['work_email']) && filter_var($session['work_email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    $leadData = [
                        'name'             => $name,
                        'contact'          => $phone,
                        'method'           => filter_var($phone, FILTER_VALIDATE_EMAIL) ? 'Email' : 'Phone / WhatsApp',
                        'program_interest' => $programInterest,
                        'opportunity'      => $opportunityLabel
                    ];
                    EmailService::sendDemoLeadNotificationToAdmin(
                        $session['work_email'],
                        $leadData,
                        $institutionName,
                        $convLog
                    );
                } catch (Throwable $e) {
                    error_log("[DemoPreviewService] Failed to send admin lead notification email: " . $e->getMessage());
                }
            }
        }

        return ['success' => true, 'message' => 'Counselor request captured and notifications dispatched'];
    }

    // =========================================================================
    // PRIVATE CRAWLER & SCRAPING HELPERS
    // =========================================================================

    /**
     * Perform real HTTP fetch via cURL
     */
    private static function fetchUrlHtml(string $url, int $timeout = 12): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (Edvora Admissions Bot/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache'
            ]
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400 || empty($html)) {
            $errDesc = $error ?: "HTTP Status {$httpCode}";
            return ['success' => false, 'error' => $errDesc, 'html' => ''];
        }

        return ['success' => true, 'html' => $html, 'error' => ''];
    }

    /**
     * Derive clean institution name from HTML or domain
     */
    private static function deriveInstitutionName(string $html, string $domain): string
    {
        if (!empty($html)) {
            // 1. og:site_name
            if (preg_match('/<meta[^>]+property=[\'"]og:site_name[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $m)) {
                $site = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (strlen($site) > 2 && strlen($site) < 60 && !preg_match('/^(Home|Welcome|Index|Main)/i', $site)) {
                    return $site;
                }
            }
            // 2. <title>
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
                $rawTitle = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $parts = preg_split('/[\—\|\-\:\•]/u', $rawTitle);
                
                $collegiatePattern = '/(University|College|Institute|School|Academy|IIT|NIT|BITS|UCLA|MIT|NYU|USC|Yale|Harvard|Stanford|Princeton|Columbia|Oxford|Cambridge|Caltech|IIM|AIIMS)/i';

                // Prioritize parts containing collegiate keywords
                foreach ($parts as $p) {
                    $cleaned = trim($p);
                    if (preg_match($collegiatePattern, $cleaned) && strlen($cleaned) < 65) {
                        return $cleaned;
                    }
                }

                // If domain prefix appears in one of the parts
                $domainPrefix = preg_replace('/\.(edu|ac\.[a-z]{2}|org|com|net|in|us|uk)$/i', '', $domain);
                foreach ($parts as $p) {
                    $cleaned = trim($p);
                    if (stripos($cleaned, $domainPrefix) !== false && strlen($cleaned) < 65) {
                        return $cleaned;
                    }
                }

                // Otherwise pick first non-generic part
                foreach ($parts as $p) {
                    $cleaned = trim($p);
                    if (strlen($cleaned) > 2 && strlen($cleaned) < 65 && !preg_match('/^(Home|Welcome|Index|Main|Official Website|Homepage|A World Leader)/i', $cleaned)) {
                        return $cleaned;
                    }
                }
            }
        }

        // 3. Fallback: domain formatting (e.g. stanford.edu -> Stanford University, iitb.ac.in -> IIT Bombay)
        $knownAcronyms = [
            'iitb' => 'IIT Bombay', 'iitd' => 'IIT Delhi', 'iitm' => 'IIT Madras',
            'iitk' => 'IIT Kanpur', 'ucla' => 'UCLA', 'mit' => 'MIT', 'nyu' => 'NYU',
            'ucb' => 'UC Berkeley', 'cmu' => 'Carnegie Mellon University'
        ];
        $prefix = preg_replace('/\.(edu|ac\.[a-z]{2}|org|com|net|in|us|uk)$/i', '', $domain);
        if (isset($knownAcronyms[strtolower($prefix)])) {
            return $knownAcronyms[strtolower($prefix)];
        }

        $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $prefix);
        $clean = ucwords(trim($clean));
        if (!preg_match('/(University|College|Institute|School|Academy)/i', $clean)) {
            $clean .= ' University';
        }
        return $clean;
    }

    /**
     * Scan and classify internal anchor links
     */
    private static function extractInternalLinks(string $html, string $baseUrl): array
    {
        $parsedBase = parse_url($baseUrl);
        $baseHost = strtolower($parsedBase['host'] ?? '');
        $scheme = $parsedBase['scheme'] ?? 'https';

        $links = [
            'all'          => [],
            'admissions'   => [],
            'programs'     => [],
            'fees'         => [],
            'scholarships' => []
        ];

        if (preg_match_all('/<a\s+[^>]*href=[\'"]([^\'"#\s]+)[\'"][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $href = trim($m[1]);
                $anchorText = strtolower(strip_tags($m[2]));

                if (preg_match('/^(mailto:|tel:|javascript:|#)/i', $href)) continue;

                // Resolve relative URLs
                if (strpos($href, '//') === 0) {
                    $fullUrl = $scheme . ':' . $href;
                } elseif (strpos($href, '/') === 0) {
                    $fullUrl = $scheme . '://' . $baseHost . $href;
                } elseif (!preg_match('#^https?://#i', $href)) {
                    $fullUrl = rtrim($baseUrl, '/') . '/' . $href;
                } else {
                    $fullUrl = $href;
                }

                $linkHost = parse_url($fullUrl, PHP_URL_HOST);
                if (!$linkHost || (strpos($linkHost, $baseHost) === false && strpos($baseHost, $linkHost) === false)) {
                    continue; // Skip external links
                }

                $links['all'][] = $fullUrl;

                $haystack = strtolower($fullUrl . ' ' . $anchorText);

                if (preg_match('/(admission|apply|application|prospect|enroll)/i', $haystack)) {
                    $links['admissions'][] = $fullUrl;
                }
                if (preg_match('/(program|course|degree|major|academic|department|undergraduate|postgraduate|faculty)/i', $haystack)) {
                    $links['programs'][] = $fullUrl;
                }
                if (preg_match('/(fee|tuition|cost|expense)/i', $haystack)) {
                    $links['fees'][] = $fullUrl;
                }
                if (preg_match('/(scholarship|financial-aid|fellowship|grant|aid)/i', $haystack)) {
                    $links['scholarships'][] = $fullUrl;
                }
            }
        }

        $links['all'] = array_values(array_unique($links['all']));
        $links['admissions'] = array_values(array_unique($links['admissions']));
        $links['programs'] = array_values(array_unique($links['programs']));
        $links['fees'] = array_values(array_unique($links['fees']));
        $links['scholarships'] = array_values(array_unique($links['scholarships']));

        return $links;
    }

    /**
     * Parse program names from HTML
     */
    private static function extractPrograms(string $html): array
    {
        $programs = [];
        $commonMajors = [
            'Computer Science', 'Business Administration', 'MBA', 'Mechanical Engineering',
            'Electrical Engineering', 'Data Science', 'Nursing', 'Economics', 'Psychology',
            'Artificial Intelligence', 'Civil Engineering', 'Biomedical Sciences', 'Law',
            'Accounting & Finance', 'Architecture', 'Information Technology', 'B.Tech'
        ];

        // Search for known majors mentioned in headings or strong tags
        foreach ($commonMajors as $major) {
            if (preg_match('/(?:<h[1-4][^>]*>|<li[^>]*>|<strong>)\s*[^<]*' . preg_quote($major, '/') . '[^<]*\s*(?:<\/h[1-4]>|<\/li>|<\/strong>)/i', $html)) {
                $programs[] = $major;
            } elseif (stripos($html, $major) !== false) {
                $programs[] = $major;
            }
            if (count($programs) >= 6) break;
        }

        if (empty($programs)) {
            $programs = ['Computer Science', 'Business Administration', 'Engineering', 'Economics', 'Information Technology'];
        }

        return array_values(array_unique($programs));
    }

    /**
     * Strip HTML down to clean plain text
     */
    private static function cleanHtmlToText(string $html): string
    {
        // Strip scripts, styles, svgs
        $clean = preg_replace([
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/<style\b[^>]*>(.*?)<\/style>/is',
            '/<svg\b[^>]*>(.*?)<\/svg>/is',
            '/<noscript\b[^>]*>(.*?)<\/noscript>/is',
            '/<nav\b[^>]*>(.*?)<\/nav>/is',
            '/<footer\b[^>]*>(.*?)<\/footer>/is'
        ], ' ', $html);

        $clean = preg_replace('/<(p|br|div|h1|h2|h3|h4|h5|li|tr)[^>]*>/i', "\n", $clean);
        $text = strip_tags($clean);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n", $text);

        return trim($text);
    }
}
