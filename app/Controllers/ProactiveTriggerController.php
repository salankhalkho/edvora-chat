<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\LlmService;
use PDO;
use Throwable;

class ProactiveTriggerController
{
    /**
     * POST /v1/chat/proactive
     * Handshake and generator for proactive chatbot greetings based on active page context.
     */
    public function handle(Request $request): void
    {
        $body = $request->getBody();
        $botToken = trim($body['bot_token'] ?? '');
        $visitorId = trim($body['visitor_id'] ?? '');
        $url = trim($body['url'] ?? '');
        $pageTitle = trim($body['page_title'] ?? '');
        $triggerType = trim($body['trigger_type'] ?? 'high_intent');
        $snippet = trim($body['snippet'] ?? '');

        if (empty($botToken)) {
            Response::error('Bot token is required.', 400);
            return;
        }

        if (empty($url)) {
            Response::error('Page URL is required.', 400);
            return;
        }

        $db = Database::getConnection();

        // 1. Fetch chatbot and organization
        $stmt = $db->prepare("
            SELECT c.id as chatbot_id, c.organization_id, c.name as bot_name,
                   o.name as org_name
            FROM chatbots c
            JOIN organizations o ON c.organization_id = o.id
            WHERE c.bot_token = :token AND c.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':token' => $botToken]);
        $bot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$bot) {
            Response::error('Chatbot not found or inactive.', 404);
            return;
        }

        $orgId = (int)$bot['organization_id'];
        $chatbotId = (int)$bot['chatbot_id'];
        $orgName = $bot['org_name'];
        $botName = !empty($bot['bot_name']) ? $bot['bot_name'] : 'Admissions Counselor';

        // Load widget customization for bot display name if customized
        $custStmt = $db->prepare("SELECT config FROM widget_customizations WHERE chatbot_id = :bot_id LIMIT 1");
        $custStmt->execute([':bot_id' => $chatbotId]);
        $custRow = $custStmt->fetch(PDO::FETCH_ASSOC);
        if ($custRow && !empty($custRow['config'])) {
            $custConfig = json_decode($custRow['config'], true);
            if (!empty($custConfig['header_bot_name'])) {
                $botName = $custConfig['header_bot_name'];
            }
        }

        // 2. Normalize URL and check visitor_pages registry
        $normalizedUrl = strtolower(rtrim($url, '/'));
        $urlHash = hash('sha256', $normalizedUrl);

        $pageStmt = $db->prepare("
            SELECT id, page_type, page_summary, page_title 
            FROM visitor_pages 
            WHERE organization_id = :org_id AND url_hash = :url_hash 
            LIMIT 1
        ");
        $pageStmt->execute([
            ':org_id' => $orgId,
            ':url_hash' => $urlHash
        ]);
        $pageRecord = $pageStmt->fetch(PDO::FETCH_ASSOC);

        $pageType = 'generic';
        $pageSummary = '';

        // Handshake: If not found in visitor_pages
        if (!$pageRecord) {
            // If client has not supplied the snippet yet, request it
            if (empty($snippet)) {
                Response::json([
                    'status' => 'need_snippet',
                    'message' => 'Page snippet required for summarization'
                ], 200);
                return;
            }

            // Client provided the text snippet -> generate 2-sentence summary and classify page type
            $summaryPrompt = "You are an expert higher-education website analyzer for {$orgName}.\n"
                . "Analyze the following webpage content from {$orgName}.\n"
                . "URL: {$url}\n"
                . "Page Title: {$pageTitle}\n"
                . "Visible Content: {$snippet}\n\n"
                . "Task:\n"
                . "1. Classify the page_type into EXACTLY ONE of: 'program', 'fees', 'admissions', 'scholarship', 'campus', 'contact', 'generic'.\n"
                . "2. Write a clear, concise 2-sentence summary of what information this specific page provides to prospective students.\n"
                . "Respond ONLY with valid JSON in this exact structure with no extra text or markdown:\n"
                . '{"page_type": "...", "page_summary": "..."}';

            try {
                $summaryResult = LlmService::complete(
                    $summaryPrompt,
                    "Analyze and summarize this page in JSON.",
                    [],
                    [
                        'organization_id' => $orgId,
                        'chatbot_id' => $chatbotId,
                        'description' => 'Visitor page proactive summarization'
                    ]
                );

                $rawText = trim($summaryResult['text'] ?? '');
                // Clean any markdown code fences if returned by LLM
                $rawText = preg_replace('/^```(?:json)?\s*/i', '', $rawText);
                $rawText = preg_replace('/\s*```$/', '', $rawText);
                $parsed = json_decode($rawText, true);

                if (is_array($parsed) && !empty($parsed['page_summary'])) {
                    $pageType = in_array($parsed['page_type'] ?? '', ['program', 'fees', 'admissions', 'scholarship', 'campus', 'contact', 'generic']) 
                        ? $parsed['page_type'] 
                        : 'generic';
                    $pageSummary = trim($parsed['page_summary']);
                } else {
                    $pageType = 'generic';
                    $pageSummary = substr(preg_replace('/\s+/', ' ', $snippet), 0, 200);
                }
            } catch (Throwable $e) {
                error_log("[ProactiveTriggerController] Summary error: " . $e->getMessage());
                $pageType = 'generic';
                $pageSummary = substr(preg_replace('/\s+/', ' ', $snippet), 0, 200);
            }

            // Save new record to visitor_pages
            try {
                $insertPage = $db->prepare("
                    INSERT INTO visitor_pages (organization_id, url, url_hash, page_title, page_type, page_summary)
                    VALUES (:org_id, :url, :url_hash, :page_title, :page_type, :page_summary)
                    ON DUPLICATE KEY UPDATE 
                        page_summary = VALUES(page_summary),
                        page_type = VALUES(page_type),
                        page_title = VALUES(page_title),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $insertPage->execute([
                    ':org_id' => $orgId,
                    ':url' => $url,
                    ':url_hash' => $urlHash,
                    ':page_title' => $pageTitle ?: null,
                    ':page_type' => $pageType,
                    ':page_summary' => $pageSummary
                ]);
            } catch (Throwable $e) {
                error_log("[ProactiveTriggerController] visitor_pages insert error: " . $e->getMessage());
            }
        } else {
            $pageType = $pageRecord['page_type'] ?: 'generic';
            $pageSummary = $pageRecord['page_summary'] ?: '';
            if (empty($pageTitle) && !empty($pageRecord['page_title'])) {
                $pageTitle = $pageRecord['page_title'];
            }
        }

        // 3. Generate proactive greeting using active page context
        $triggerDesc = match ($triggerType) {
            'program_visitors' => 'Spent 90+ seconds reading this program page',
            'exit_intent' => 'About to leave the website / moving cursor to close tab',
            default => 'High-intent visitor: spent 90+ seconds and scrolled deep into the page content'
        };

        $greetingPrompt = "You are {$botName}, the consultative AI Admissions Counselor for {$orgName}.\n"
            . "A prospective student is actively browsing the following webpage:\n"
            . "- Active Page Title: \"{$pageTitle}\"\n"
            . "- Active Page URL: {$url}\n"
            . "- Page Category: {$pageType}\n"
            . "- What this page covers: {$pageSummary}\n"
            . "- Visitor Context: {$triggerDesc}\n\n"
            . "CRITICAL RULES:\n"
            . "1. Speak directly about what they are viewing right now on this active page (e.g. curriculum, eligibility, tuition fees, scholarships, or application deadlines).\n"
            . "2. Generate exactly ONE warm, natural, consultative sentence (maximum 30 words).\n"
            . "3. Do NOT ask for contact info (name, email, or phone) yet.\n"
            . "4. Output ONLY the single conversational message string. No quotes, no markdown, no filler.";

        try {
            $greetingResult = LlmService::complete(
                $greetingPrompt,
                "Generate the single proactive opening sentence now.",
                [],
                [
                    'organization_id' => $orgId,
                    'chatbot_id' => $chatbotId,
                    'max_tokens_override' => 100,
                    'description' => 'Proactive trigger greeting generation'
                ]
            );
            $openingMessage = trim($greetingResult['text'] ?? '');
            // Strip any wrapping quotes
            $openingMessage = trim($openingMessage, '"\'');
        } catch (Throwable $e) {
            error_log("[ProactiveTriggerController] Greeting generation error: " . $e->getMessage());
            $openingMessage = '';
        }

        if (empty($openingMessage)) {
            $openingMessage = match ($pageType) {
                'program' => "Are you exploring {$pageTitle}? I can help you with eligibility, fees, curriculum, or career opportunities.",
                'fees' => "I see you're looking at tuition information. Would you like me to explain scholarships or installment payment options?",
                'admissions' => "Planning to apply? I can walk you through the admission requirements and application process step by step.",
                'scholarship' => "Looking for ways to reduce tuition? I can help you explore merit scholarships available for our programs.",
                'campus' => "Interested in the campus? I can tell you about facilities, accommodation, or help arrange a visit.",
                'contact' => "Would you like help finding the right counselor, or would you prefer to schedule a callback?",
                default => "Hi! I see you're exploring {$orgName}. Ask me anything about programs, admissions, fees, or student life!"
            };
        }

        // 4. Record the proactive session in conversations table
        $convId = null;
        try {
            $convStmt = $db->prepare("
                INSERT INTO conversations 
                    (organization_id, chatbot_id, visitor_id, page_url, page_title,
                     proactive_trigger_fired, proactive_page_type, proactive_trigger_type,
                     started_at, last_message_at)
                VALUES 
                    (:org_id, :bot_id, :vis_id, :page_url, :page_title,
                     1, :page_type, :trigger_type, NOW(), NOW())
            ");
            $convStmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => $chatbotId,
                ':vis_id' => $visitorId ?: 'v_' . bin2hex(random_bytes(6)),
                ':page_url' => substr($url, 0, 500),
                ':page_title' => substr($pageTitle, 0, 255),
                ':page_type' => substr($pageType, 0, 50),
                ':trigger_type' => substr($triggerType, 0, 50)
            ]);
            $convId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log("[ProactiveTriggerController] conversations insert error: " . $e->getMessage());
        }

        Response::success([
            'need_snippet' => false,
            'opening_message' => $openingMessage,
            'page_type' => $pageType,
            'page_summary' => $pageSummary,
            'conversation_id' => $convId
        ]);
    }
}
