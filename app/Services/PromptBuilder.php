<?php

namespace App\Services;

use App\Config\Database;
use PDO;

class PromptBuilder
{
    /**
     * Build fresh structured Admissions Counselor prompt routed by conversational phase.
     */
    public static function build(
        int $organizationId,
        array $knowledgeContextSources = [],
        ?array $activeProgram = null,
        bool $leadCaptured = false,
        array $leadFormsShown = [],
        int $userMessageCount = 0,
        array $conversationHistory = [],
        ?string $chatbotPromptOverride = null,
        string $userMessage = ''
    ): string {
        $db = Database::getConnection();

        // 1. Fetch Organization Details
        $stmtOrg = $db->prepare("SELECT name FROM organizations WHERE id = :id");
        $stmtOrg->execute([':id' => $organizationId]);
        $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
        $collegeName = !empty($org['name']) ? $org['name'] : 'our institution';

        // 2. Assemble Knowledge Base Context Blocks (top retrieved sources)
        $contextBlock = "";
        if (!empty($knowledgeContextSources)) {
            foreach ($knowledgeContextSources as $idx => $source) {
                $num = $idx + 1;
                $title = $source['title'] ?? 'Admissions Guide';
                $content = trim($source['processed_content'] ?? '');
                $contextBlock .= "[SOURCE {$num}: {$title}]\n{$content}\n\n";
            }
        } else {
            $contextBlock = "[NO SPECIFIC KNOWLEDGE BASE CONTEXT MATCHED FOR THIS QUERY. Answer based on general admissions principles or explain that our admissions team can confirm it for them.]\n";
        }

        // 3. Visitor State Variables
        $programInterestKnown = !empty($activeProgram['course_name']);
        $programInterestName = $activeProgram['course_name'] ?? '';
        $programDisplayName = $programInterestKnown ? $programInterestName : 'our academic programs';
        $leadCapturedStr = $leadCaptured ? "Yes" : "No";

        // 4. Lead Activity (Offers already presented in this session)
        $offerCallback = in_array('counselor_callback', $leadFormsShown, true) ? "Yes" : "No";
        $offerBrochure = (in_array('brochure', $leadFormsShown, true) || in_array('asset_delivery', $leadFormsShown, true)) ? "Yes" : "No";
        $offerTour = in_array('campus_tour', $leadFormsShown, true) ? "Yes" : "No";
        $offerScholarship = (in_array('scholarship_calculator', $leadFormsShown, true) || in_array('scholarship_eval', $leadFormsShown, true)) ? "Yes" : "No";

        // 5. Cadence Counter
        $userMsgCountInt = max(0, (int)$userMessageCount);

        // 6. Recent Dialogue (Past 6 messages max)
        $historyLines = [];
        $recentSlice = array_slice($conversationHistory, -6);
        foreach ($recentSlice as $msg) {
            $roleLabel = ($msg['role'] === 'user') ? 'User' : 'Assistant';
            $msgContent = trim($msg['content'] ?? '');
            if ($msgContent !== '') {
                $historyLines[] = "{$roleLabel}: {$msgContent}";
            }
        }
        $historyStr = !empty($historyLines) ? implode("\n", $historyLines) : "[No previous conversation]";

        // 7. Determine Phase Key
        $activePhaseKey = self::determinePhase($userMessage, $leadCaptured, $activeProgram, $leadFormsShown, $conversationHistory);

        // 8. Calculate Phase 2 Alternate Offer State
        $phase2Offer = self::computePhase2Offer($conversationHistory, $leadFormsShown, $userMessage);

        // 9. Retrieve Prompt Template from platform_config or disk fallbacks
        $rawTemplate = self::getPhasePromptTemplate($db, $activePhaseKey);

        // 10. Replace Dynamic Session Placeholders
        $replacements = [
            '{{COLLEGE_NAME}}'       => $collegeName,
            '{{KNOWLEDGE_CONTEXT}}'  => $contextBlock,
            '{{PROGRAM_INTEREST}}'   => $programDisplayName,
            '{{LEAD_CAPTURED}}'      => $leadCapturedStr,
            '{{OFFER_CALLBACK}}'     => $offerCallback,
            '{{OFFER_BROCHURE}}'     => $offerBrochure,
            '{{OFFER_TOUR}}'         => $offerTour,
            '{{OFFER_SCHOLARSHIP}}'  => $offerScholarship,
            '{{OFFER_TURN}}'         => $phase2Offer['offer_turn'],
            '{{ASSIGNED_OFFER}}'     => $phase2Offer['assigned_offer'],
            '{{CADENCE_COUNTER}}'    => (string)$userMsgCountInt,
            '{{RECENT_DIALOGUE}}'    => $historyStr,
        ];
        $prompt = str_replace(array_keys($replacements), array_values($replacements), $rawTemplate);

        if (!empty($chatbotPromptOverride)) {
            $prompt .= "\n\n================================================================================\n";
            $prompt .= "INSTITUTION SPECIFIC INSTRUCTIONS:\n" . trim($chatbotPromptOverride) . "\n";
        }

        return $prompt;
    }

    /**
     * Determine active conversational phase key based on session state and query context.
     */
    public static function determinePhase(
        string $userMessage,
        bool $leadCaptured,
        ?array $activeProgram,
        array $leadFormsShown = [],
        array $conversationHistory = []
    ): string {
        // Phase 3: Lead contact is already captured
        if ($leadCaptured) {
            return 'master_prompt_phase_3';
        }

        $programKnown = !empty($activeProgram['course_name']);

        // Check if current user query or recent turn relates to fees, tuition, or scholarships
        $cleanMsg = strtolower(trim($userMessage));
        $isFinancialAidQuery = (bool)preg_match('/\b(scholarship|scholarships|financial aid|tuition|fees?|costs?|waivers?|installments?|afford|grants?)\b/i', $cleanMsg);

        // Check if last assistant message offered scholarship calculator and user is affirming
        $lastAssistantMsg = '';
        for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
            if (($conversationHistory[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMsg = strtolower($conversationHistory[$i]['content'] ?? '');
                break;
            }
        }
        $lastOfferedScholarship = (bool)preg_match('/\b(scholarship evaluation|scholarship calculator|financial aid evaluation|check your eligibility)\b/i', $lastAssistantMsg);
        $isAffirmative = (bool)preg_match('/^(yes|yeah|yep|sure|ok|okay|please|definitely|certainly|check|evaluate)\b/i', $cleanMsg);

        // Scholarship phase: Triggered when program is known and user asks about fees/aid or affirms scholarship offer
        if ($programKnown && ($isFinancialAidQuery || ($lastOfferedScholarship && $isAffirmative))) {
            return 'master_prompt_scholarship';
        }

        // Phase 2: Program interest is known
        if ($programKnown) {
            return 'master_prompt_phase_2';
        }

        // Phase 1: Program interest unknown (default discovery)
        return 'master_prompt_phase_1';
    }

    /**
     * Calculate alternate offer cadence and rotation for Phase 2.
     * Rotation order: 1. brochure -> 2. campus_tour -> 3. counselor_callback
     */
    public static function computePhase2Offer(
        array $conversationHistory,
        array $leadFormsShown,
        string $userMessage
    ): array {
        // Check if the immediate last assistant message made an explicit offer
        $lastAssistantOffer = null;
        for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
            if (($conversationHistory[$i]['role'] ?? '') === 'assistant') {
                $content = strtolower($conversationHistory[$i]['content'] ?? '');
                if (preg_match('/\b(brochure|prospectus|curriculum guide)\b/i', $content)) {
                    $lastAssistantOffer = 'brochure';
                } elseif (preg_match('/\b(campus tour|visit campus|tour of our campus)\b/i', $content)) {
                    $lastAssistantOffer = 'campus_tour';
                } elseif (preg_match('/\b(counselor callback|callback|phone call|call from (an|our) admissions counselor)\b/i', $content)) {
                    $lastAssistantOffer = 'counselor_callback';
                } elseif (preg_match('/\b(scholarship evaluation|scholarship calculator|check your eligibility)\b/i', $content)) {
                    $lastAssistantOffer = 'scholarship_calculator';
                }
                break; // Unconditionally stop after inspecting the immediate previous assistant message
            }
        }

        // If the immediate last assistant message made an offer:
        if ($lastAssistantOffer !== null) {
            $cleanUser = strtolower(trim($userMessage));
            $isAffirmative = (bool)preg_match('/^(yes|yeah|yep|sure|ok|okay|please|definitely|certainly|send it|book it|call me|share it)\b/i', $cleanUser);
            if ($isAffirmative) {
                // User is affirming! Do not generate a new offer; let ACCEPTING_OFFER handle it.
                return [
                    'offer_turn'     => 'No',
                    'assigned_offer' => 'none',
                    'is_accepting'   => true,
                    'accepted_offer' => $lastAssistantOffer
                ];
            }

            // User did not affirm (asked another question) -> Pure value turn (Alternate message)
            return [
                'offer_turn'     => 'No',
                'assigned_offer' => 'none',
                'is_accepting'   => false,
                'accepted_offer' => null
            ];
        }

        // If last assistant message did not make an offer -> This turn IS an OFFER turn!
        $assigned = 'brochure';
        if (in_array('brochure', $leadFormsShown, true) || in_array('asset_delivery', $leadFormsShown, true)) {
            $assigned = 'campus_tour';
            if (in_array('campus_tour', $leadFormsShown, true)) {
                $assigned = 'counselor_callback';
                if (in_array('counselor_callback', $leadFormsShown, true)) {
                    // All 3 already presented
                    return [
                        'offer_turn'     => 'No',
                        'assigned_offer' => 'none',
                        'is_accepting'   => false,
                        'accepted_offer' => null
                    ];
                }
            }
        }

        return [
            'offer_turn'     => 'Yes',
            'assigned_offer' => $assigned,
            'is_accepting'   => false,
            'accepted_offer' => null
        ];
    }

    /**
     * Retrieve prompt template by key from platform_config with file fallback.
     */
    public static function getPhasePromptTemplate(PDO $db, string $phaseKey): string
    {
        $stmt = $db->prepare("SELECT value_text FROM platform_config WHERE key_name = :k LIMIT 1");
        $stmt->execute([':k' => $phaseKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($row['value_text']) && trim($row['value_text']) !== '') {
            return trim($row['value_text']);
        }

        // Fallback to disk file in app/Config/Prompts/
        $fileMap = [
            'master_prompt_phase_1'     => 'phase_1.txt',
            'master_prompt_phase_2'     => 'phase_2.txt',
            'master_prompt_scholarship' => 'scholarship.txt',
            'master_prompt_phase_3'     => 'phase_3.txt',
        ];

        if (isset($fileMap[$phaseKey])) {
            $diskPath = dirname(__DIR__) . '/Config/Prompts/' . $fileMap[$phaseKey];
            if (file_exists($diskPath) && is_readable($diskPath)) {
                $content = trim(file_get_contents($diskPath));
                if (!empty($content)) {
                    return $content;
                }
            }
        }

        // Fallback to legacy master_prompt
        $stmtLegacy = $db->query("SELECT value_text FROM platform_config WHERE key_name = 'master_prompt' LIMIT 1");
        $legacyRow = $stmtLegacy ? $stmtLegacy->fetch(PDO::FETCH_ASSOC) : null;
        if (!empty($legacyRow['value_text']) && trim($legacyRow['value_text']) !== '') {
            return trim($legacyRow['value_text']);
        }

        return self::getDefaultMasterPrompt();
    }

    /**
     * Default Master Prompt Template Fallback
     */
    public static function getDefaultMasterPrompt(): string
    {
        $filePath = dirname(__DIR__, 2) . '/master_prompt.txt';
        if (file_exists($filePath) && is_readable($filePath)) {
            $content = file_get_contents($filePath);
            if (!empty($content)) {
                return trim($content);
            }
        }

        return <<<'EOT'
You are a friendly Admissions Counselor for {{COLLEGE_NAME}}. Speak warmly and conversationally — never sound robotic or repetitive. Ground all factual answers strictly in KNOWLEDGE and DIALOGUE.

================================================================================
SESSION STATE
================================================================================
KNOWLEDGE: {{KNOWLEDGE_CONTEXT}}
PROGRAM_INTEREST: {{PROGRAM_INTEREST}}
DIALOGUE: {{RECENT_DIALOGUE}}

================================================================================
CARDINAL RULES
================================================================================
1. ZERO LEAD PITCHING WITHOUT PROGRAM INTEREST:
   - Do NOT offer callbacks, brochures, tours, or scholarships until program interest is known.
2. ANSWER FACTUALLY FIRST:
   - Provide direct factual answers from KNOWLEDGE in bubble_1.
3. OUTPUT STRICT JSON ONLY.
EOT;
    }
}
