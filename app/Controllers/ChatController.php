<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ContentEngine;
use App\Services\IntentClassifier;
use App\Services\LlmService;
use App\Services\ProgramDetector;
use App\Services\PromptBuilder;
use App\Services\QueryTranslator;
use PDO;
use Throwable;

class ChatController
{
    /**
     * GET /v1/widget/config/{bot_token} — Public widget configuration endpoint
     */
    public function widgetConfig(Request $request, array $params = []): void
    {
        $botToken = trim($params['bot_token'] ?? '');
        if (empty($botToken)) {
            Response::error('Bot token is required.', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT c.*, o.name as org_name, o.logo_url as org_logo, o.primary_color as org_color
            FROM chatbots c
            JOIN organizations o ON c.organization_id = o.id
            WHERE c.bot_token = :token AND c.is_active = 1
        ");
        $stmt->execute([':token' => $botToken]);
        $bot = $stmt->fetch();

        if (!$bot) {
            Response::error('Chatbot not found or inactive.', 404);
        }

        // Load widget customization with cascade: org → defaults
        $customization = \App\Controllers\WidgetCustomizationController::cascadeLookup(
            $db,
            (int)$bot['id'],
            (int)$bot['organization_id']
        );

        $botDisplayName = !empty($customization['header_bot_name']) ? $customization['header_bot_name'] : ($bot['name'] ?: $bot['org_name']);
        $welcomeMsg = !empty($customization['welcome_message']) ? $customization['welcome_message'] : ($bot['welcome_message'] ?: "Hi there! 👋 Welcome to {$bot['org_name']}. How can I assist you with admissions, programs, or campus life today?");

        // Check authoritative counts for passive lead capture action badges
        $hasAssets = (int)$db->query("SELECT COUNT(*) FROM lead_assets WHERE organization_id = " . (int)$bot['organization_id'] . " AND is_active = 1")->fetchColumn() > 0;
        $hasCampuses = (int)$db->query("SELECT COUNT(*) FROM campuses WHERE organization_id = " . (int)$bot['organization_id'] . " AND status = 'active'")->fetchColumn() > 0;
        // Check if callbacks are actually configured: has staff users
        $orgIdInt = (int)$bot['organization_id'];
        $hasStaff = (int)$db->query("SELECT COUNT(*) FROM users WHERE organization_id = {$orgIdInt} AND role IN ('admin', 'staff')")->fetchColumn() > 0;
        $hasCallbacks = ((bool)$bot['lead_capture_enabled']) && $hasStaff;

        $quickChips = [];
        if (isset($customization['quick_chips'])) {
            $rawCustChips = $customization['quick_chips'];
            if (is_array($rawCustChips) && !empty($rawCustChips)) {
                $quickChips = array_map(function($c) {
                    $lbl = is_array($c) ? ($c['label'] ?? $c['message'] ?? '') : (string)$c;
                    return ['label' => $lbl, 'message' => $lbl];
                }, $rawCustChips);
            } else if (is_string($rawCustChips) && trim($rawCustChips) !== '') {
                $chipsParts = array_filter(array_map('trim', explode(',', $rawCustChips)));
                $quickChips = array_map(function($c) {
                    return ['label' => $c, 'message' => $c];
                }, $chipsParts);
            } else {
                $quickChips = [];
            }
        } else if (!empty($bot['quick_chips'])) {
            $rawBotChips = is_string($bot['quick_chips']) ? (json_decode($bot['quick_chips'], true) ?: []) : $bot['quick_chips'];
            if (is_array($rawBotChips)) {
                $quickChips = array_map(function($c) {
                    if (is_array($c)) {
                        return [
                            'label' => $c['label'] ?? $c['message'] ?? '',
                            'message' => $c['message'] ?? $c['label'] ?? ''
                        ];
                    }
                    return ['label' => (string)$c, 'message' => (string)$c];
                }, $rawBotChips);
            }
        }

        Response::success([
            'bot_id' => (int)$bot['id'],
            'organization_name' => $bot['org_name'],
            'name' => $botDisplayName,
            'welcome_message' => $welcomeMsg,
            'primary_color' => $customization['header_bg'] ?? ($bot['primary_color'] ?: ($bot['org_color'] ?: '#6366F1')),
            'secondary_color' => $bot['secondary_color'] ?? '#38BDF8',
            'bot_avatar' => $customization['avatar_url'] ?? ($bot['bot_avatar_url'] ?: ($bot['org_logo'] ?: null)),
            'lead_capture_enabled' => (bool)$bot['lead_capture_enabled'],
            'widget_style' => $bot['widget_style'] ?? 'glassmorphism',
            'theme_mode' => $customization['theme'] ?? ($bot['theme_mode'] ?? 'dark'),
            'header_subtitle' => $customization['header_subtitle'] ?? ($bot['header_subtitle'] ?? 'Online • Replies instantly'),
            'launcher_icon' => $customization['launcher_icon'] ?? ($bot['launcher_icon'] ?? 'chat'),
            'launcher_text' => $customization['launcher_text'] ?? ($bot['launcher_text'] ?? 'Ask AI'),
            'border_radius' => $bot['border_radius'] ?? 'curved',
            'avatar_icon' => $bot['avatar_icon'] ?? '🤖',
            'quick_chips' => $quickChips,
            'has_assets' => $hasAssets,
            'has_campuses' => $hasCampuses,
            'has_callbacks' => $hasCallbacks,
            'customization' => $customization,
            'min_turns_before_lead' => 2,
            'passive_lead_bar_enabled' => ($hasAssets || $hasCampuses || $hasCallbacks)
        ]);
    }

    /**
     * POST /v1/chat/completions — Public widget chat endpoint
     */
    public function complete(Request $request, array $params = []): void
    {
        $botToken = trim((string)($request->get('bot_token') ?? ''));
        $visitorId = trim((string)($request->get('visitor_id') ?? ''));
        if (empty($visitorId)) {
            $visitorId = 'mob_' . substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
        }
        $userMessage = trim((string)($request->get('message') ?? $request->get('query') ?? $request->get('prompt') ?? ''));
        $isTest = (int)(bool)($request->get('is_test') ?? false);

        if (empty($botToken) || empty($userMessage)) {
            Response::error('bot_token and message are required fields.', 422);
        }

        $db = Database::getConnection();

        // 1. Authenticate chatbot token & active status
        $stmtBot = $db->prepare("
            SELECT c.*, o.name as org_name, o.subscription_status
            FROM chatbots c
            JOIN organizations o ON c.organization_id = o.id
            WHERE c.bot_token = :token AND c.is_active = 1
        ");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Invalid or inactive chatbot token.', 403);
        }

        $orgId = (int)$bot['organization_id'];
        $botId = (int)$bot['id'];

        // 2. Find or Create Conversation Session & check existing Lead Status
        $stmtConv = $db->prepare("
            SELECT id, is_test, lead_name_collected, lead_email_collected, lead_phone_collected,
                   visitor_name, visitor_email, visitor_phone, lead_captured_at
            FROM conversations
            WHERE organization_id = :org_id AND chatbot_id = :bot_id AND visitor_id = :visitor_id
            ORDER BY id DESC LIMIT 1
        ");
        $stmtConv->execute([
            ':org_id' => $orgId,
            ':bot_id' => $botId,
            ':visitor_id' => $visitorId
        ]);
        $conv = $stmtConv->fetch();

        $leadCaptured = false;
        $visitorEmail = null;
        $visitorName = null;
        $currentProgramInterest = null;
        $currentProgramId = null;

        if (!$conv) {
            $stmtNewConv = $db->prepare("
                INSERT INTO conversations (organization_id, chatbot_id, visitor_id, is_test, page_url, page_title, started_at, last_message_at)
                VALUES (:org_id, :bot_id, :visitor_id, :is_test, :page_url, :page_title, NOW(), NOW())
            ");
            $stmtNewConv->execute([
                ':org_id' => $orgId,
                ':bot_id' => $botId,
                ':visitor_id' => $visitorId,
                ':is_test' => $isTest,
                ':page_url' => $request->get('page_url'),
                ':page_title' => $request->get('page_title')
            ]);
            $convId = (int)$db->lastInsertId();
        } else {
            $convId = (int)$conv['id'];
            $isTest = (int)($conv['is_test'] ?? $isTest);
            $leadCaptured = (!empty($conv['lead_captured_at']) || !empty($conv['lead_email_collected']) || !empty($conv['visitor_email']));
            $visitorEmail = $conv['visitor_email'] ?? null;
            $visitorName = $conv['visitor_name'] ?? null;
            $currentProgramInterest = $conv['lead_program_interest'] ?? null;
            $currentProgramId = !empty($conv['program_id']) ? (int)$conv['program_id'] : null;
            $db->exec("UPDATE conversations SET last_message_at = NOW() WHERE id = {$convId}");
        }

        // 3. Save User Message to DB
        $stmtUserMsg = $db->prepare("
            INSERT INTO messages (conversation_id, organization_id, role, content, created_at)
            VALUES (:conv_id, :org_id, 'user', :content, NOW())
        ");
        $stmtUserMsg->execute([
            ':conv_id' => $convId,
            ':org_id' => $orgId,
            ':content' => $userMessage
        ]);

        // 4. Fetch Recent Conversation History (last 6 messages including current user message)
        $stmtHistory = $db->prepare("
            SELECT role, content FROM messages
            WHERE conversation_id = :conv_id
            ORDER BY id DESC LIMIT 6
        ");
        $stmtHistory->execute([':conv_id' => $convId]);
        $history = array_reverse($stmtHistory->fetchAll());

        // Previous messages prior to the current turn
        $prevHistory = array_slice($history, 0, -1);

        // 5. Stage 1: Deterministic Intent Classification with conversational context
        $intentTier = IntentClassifier::classify($userMessage, $prevHistory);

        $contextSources = [];
        if ($intentTier === IntentClassifier::TIER_KNOWLEDGE_QUERY) {
            $retrievalTarget = $userMessage;
            // If user responded affirmatively (e.g., "yes", "sure", "please do"), retrieve context using previous assistant message
            if (IntentClassifier::isAffirmativeResponse($userMessage, $prevHistory) && !empty($prevHistory)) {
                for ($hIdx = count($prevHistory) - 1; $hIdx >= 0; $hIdx--) {
                    if (($prevHistory[$hIdx]['role'] ?? '') === 'assistant') {
                        $retrievalTarget = $prevHistory[$hIdx]['content'] ?? $userMessage;
                        break;
                    }
                }
            }
            // Translate Visitor Query to English for retrieval if non-English
            $englishQuery = QueryTranslator::translateToEnglish($retrievalTarget);
            // Retrieve Top 1–5 Knowledge Context Items (vector search, scoped by known program)
            $contextSources = ContentEngine::selectContext($orgId, $englishQuery, $botId, $currentProgramId ?? null);
        }

        // 6. Proactive Program Interest Detection & Early Lead Sync to DB
        $detectedProgram = ProgramDetector::detect($db, $orgId, $userMessage, $contextSources);
        if ($detectedProgram) {
            $currentProgramInterest = $detectedProgram['course_name'];
            $currentProgramId = (int)$detectedProgram['id'];
            ProgramDetector::syncProgramLead($db, $orgId, $botId, $convId, $detectedProgram);
        }

        // Check if an entry exists in the `leads` table with program interest
        $hasProgramLeadInDb = false;
        $activeProgramData = null;

        // Check if current user query is a broad catalog inquiry or generic fee inquiry
        $isCatalogQuery = ProgramDetector::isGenericCatalogQuery($userMessage);
        $isFeeQuery = ProgramDetector::isGenericFeeQuery($userMessage);

        // If user explicitly inquired about a program on this current turn, prioritize it immediately
        if ($detectedProgram) {
            $hasProgramLeadInDb = true;
            $activeProgramData = [
                'id' => $currentProgramId,
                'course_name' => $currentProgramInterest
            ];
        } elseif (!$isCatalogQuery) {
            // Only inherit prior program interest if this is NOT a broad catalog query
            $stmtLeadCheck = $db->prepare("
                SELECT id, program_interest, program_id
                FROM leads
                WHERE conversation_id = :cid AND organization_id = :oid AND program_interest IS NOT NULL AND program_interest != ''
                ORDER BY id DESC LIMIT 1
            ");
            $stmtLeadCheck->execute([':cid' => $convId, ':oid' => $orgId]);
            $leadRecord = $stmtLeadCheck->fetch(PDO::FETCH_ASSOC);

            if ($leadRecord && !empty($leadRecord['program_interest'])) {
                $hasProgramLeadInDb = true;
                $activeProgramData = [
                    'id' => (int)($leadRecord['program_id'] ?? $currentProgramId),
                    'course_name' => $leadRecord['program_interest']
                ];
            } elseif (!empty($currentProgramInterest)) {
                $hasProgramLeadInDb = true;
                $activeProgramData = [
                    'id' => $currentProgramId,
                    'course_name' => $currentProgramInterest
                ];
            }
        }

        // 7. Calculate Current Turn Count and Anti-Fatigue Offer Cadence
        $stmtTurns = $db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = :cid AND role = 'user'");
        $stmtTurns->execute([':cid' => $convId]);
        $turnCount = (int)$stmtTurns->fetchColumn();

        $minTurns = 2; // Strict Rule: Zero offers on Turn 1
        $cooldownTurns = 2; // Strict Rule: Minimum 2 turns between proactive offers
        $maxOffersPerSession = 3; // Strict Rule: Anti-fatigue session cap

        $lastOfferTurn = (int)($conv['last_offer_turn'] ?? 0);
        $totalOffersCount = (int)($conv['total_offers_count'] ?? 0);

        // Can we make a proactive offer on this turn?
        // STRICT RULE: Never make ANY of the 4 offers unless the visitor's program interest is captured in DB!
        $canMakeOffer = (!$leadCaptured)
            && $hasProgramLeadInDb
            && ((bool)$bot['lead_capture_enabled'])
            && ($turnCount >= $minTurns)
            && ($totalOffersCount < $maxOffersPerSession)
            && (($turnCount - $lastOfferTurn) >= $cooldownTurns)
            && !$isCatalogQuery;

        // 8. Build System Prompt with Counselor Brain, Intent Tier, and Offer Cadence
        $systemPrompt = PromptBuilder::build(
            $orgId,
            $contextSources,
            $bot['system_prompt_override'],
            $intentTier,
            $turnCount,
            $leadCaptured,
            $minTurns,
            $canMakeOffer,
            $activeProgramData,
            $isCatalogQuery,
            $isFeeQuery
        );

        // 9. Invoke LLM Service
        try {
            $llmResult = LlmService::complete($systemPrompt, $userMessage, $history, [
                'organization_id' => $bot['organization_id'] ?? null,
                'chatbot_id' => $bot['id'] ?? null,
                'activity_type' => 'chat_completion',
                'reference_type' => 'conversation',
                'reference_id' => $conversationId,
                'description' => "Chat conversation turn #{$turnCount} (Intent: {$intentTier})"
            ]);
            $rawAiResponse = $llmResult['text'];
            $tokensUsed = $llmResult['tokens_used'];

            // 10. Parse structured JSON response from LLM
            // The LLM is instructed to always return a JSON object.
            // Fail-safe: if JSON is malformed, treat raw text as the response with null analytics.
            $parsed         = null;
            $aiResponseText = '';
            $followUpMessage = null;
            $analytics = [
                'sentiment'          => null,
                'emotion'            => null,
                'frustration'        => null,
                'conversation_trend' => null,
                'intent_label'       => null,
                'conversation_stage' => null,
                'lead_intent'        => null,
                'needs_human'        => 0,
            ];

            // Strip markdown code fences if any (some LLMs wrap JSON in ```json ... ```)
            $cleanedRaw = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```\s*$/i', '', $rawAiResponse)));

            $parsed = json_decode($cleanedRaw, true);

            if (is_array($parsed)) {
                // Successfully parsed — extract all fields
                $aiResponseText  = trim($parsed['response'] ?? '');
                $followUpMessage = !empty($parsed['follow_up']) ? trim($parsed['follow_up']) : null;

                // Extract analytics fields with type-safe casting
                $analytics['sentiment']          = in_array($parsed['sentiment'] ?? '', ['positive', 'neutral', 'negative']) ? $parsed['sentiment'] : null;
                $analytics['emotion']            = !empty($parsed['emotion']) ? substr(trim($parsed['emotion']), 0, 50) : null;
                $analytics['frustration']        = isset($parsed['frustration']) ? max(0.0, min(1.0, (float)$parsed['frustration'])) : null;
                $analytics['conversation_trend'] = in_array($parsed['conversation_trend'] ?? '', ['improving', 'stable', 'declining']) ? $parsed['conversation_trend'] : null;
                $analytics['intent_label']       = !empty($parsed['intent']) ? substr(trim($parsed['intent']), 0, 50) : null;
                $analytics['conversation_stage'] = in_array($parsed['conversation_stage'] ?? '', ['discovery', 'consideration', 'decision', 'application']) ? $parsed['conversation_stage'] : null;
                $analytics['lead_intent']        = in_array($parsed['lead_intent'] ?? '', ['low', 'medium', 'high']) ? $parsed['lead_intent'] : null;
                $analytics['needs_human']        = !empty($parsed['needs_human']) ? 1 : 0;

                // Normalise lead_trigger value ('scholarship_calculator' legacy → 'scholarship_eval')
                $rawTriggerType = strtolower(trim($parsed['lead_trigger'] ?? ''));
                if ($rawTriggerType === 'scholarship_calculator') {
                    $rawTriggerType = 'scholarship_eval';
                }
            } else {
                // Fail-safe: LLM returned non-JSON — use raw text as response, analytics stay null
                error_log("[ChatController] LLM JSON parse failed. Raw: " . substr($rawAiResponse, 0, 300));
                $aiResponseText = trim($rawAiResponse);
                $rawTriggerType = '';
            }

            // 11. Resolve lead trigger payload
            $leadTriggerPayload = null;

            if (!empty($rawTriggerType) && in_array($rawTriggerType, ['asset_delivery', 'campus_tour', 'counselor_callback', 'scholarship_eval'])) {
                // Only generate trigger form if lead not yet captured, program known, lead capture enabled, turn >= minTurns
                if (!$leadCaptured && $hasProgramLeadInDb && $turnCount >= $minTurns && (bool)$bot['lead_capture_enabled']) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, $rawTriggerType, $userMessage, $activeProgramData);
                }
            }

            // Fail-safe: affirmative response with no lead_trigger set in JSON
            // (LLM confirmed the offer in text but forgot to set the lead_trigger field)
            if (!$leadCaptured && empty($leadTriggerPayload) && (bool)$bot['lead_capture_enabled'] && IntentClassifier::isAffirmativeResponse($userMessage, $prevHistory)) {
                $lastOfferText = '';
                for ($hIdx = count($prevHistory) - 1; $hIdx >= 0; $hIdx--) {
                    if (($prevHistory[$hIdx]['role'] ?? '') === 'assistant') {
                        $lastOfferText = strtolower($prevHistory[$hIdx]['content'] ?? '');
                        break;
                    }
                }
                if (str_contains($lastOfferText, 'tour') || str_contains($lastOfferText, 'visit')) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, 'campus_tour', $userMessage, $activeProgramData);
                } elseif (str_contains($lastOfferText, 'scholarship') || str_contains($lastOfferText, 'waiver')) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, 'scholarship_eval', $userMessage, $activeProgramData);
                } elseif (str_contains($lastOfferText, 'syllabus') || str_contains($lastOfferText, 'brochure') || str_contains($lastOfferText, 'fee structure')) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, 'asset_delivery', $userMessage, $activeProgramData);
                } elseif (str_contains($lastOfferText, 'call') || str_contains($lastOfferText, 'advisor') || str_contains($lastOfferText, 'counselor') || str_contains($lastOfferText, 'callback')) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, 'counselor_callback', $userMessage, $activeProgramData);
                }
            }

            // Cadence gate for follow_up: apply same rules as before
            // (Only show if canMakeOffer is true, program known, no lead trigger already firing)
            if (!empty($followUpMessage) && (!$canMakeOffer || !$hasProgramLeadInDb || !empty($leadTriggerPayload) || $intentTier !== IntentClassifier::TIER_KNOWLEDGE_QUERY)) {
                $followUpMessage = null;
            }

            // Guard: ensure main response is never empty
            if (empty($aiResponseText) && !empty($followUpMessage)) {
                $aiResponseText  = $followUpMessage;
                $followUpMessage = null;
            }

            // 12. Update cadence state if a follow-up offer was delivered this turn
            if (!empty($followUpMessage)) {
                $db->exec("UPDATE conversations SET last_offer_turn = {$turnCount}, total_offers_count = total_offers_count + 1 WHERE id = {$convId}");
            }

            $sourceIdsUsed = array_map(fn($s) => $s['id'], $contextSources);

            // 13. Save AI response to messages table (with analytics fields)
            $stmtAiMsg = $db->prepare("
                INSERT INTO messages (
                    conversation_id, organization_id, role, content,
                    knowledge_sources_used, tokens_used,
                    sentiment, emotion, frustration, conversation_trend,
                    intent_label, conversation_stage, lead_intent, needs_human,
                    created_at
                ) VALUES (
                    :conv_id, :org_id, 'assistant', :content,
                    :sources, :tokens,
                    :sentiment, :emotion, :frustration, :conv_trend,
                    :intent_label, :conv_stage, :lead_intent, :needs_human,
                    NOW()
                )
            ");
            $stmtAiMsg->execute([
                ':conv_id'     => $convId,
                ':org_id'      => $orgId,
                ':content'     => $aiResponseText,
                ':sources'     => json_encode($sourceIdsUsed),
                ':tokens'      => $tokensUsed,
                ':sentiment'   => $analytics['sentiment'],
                ':emotion'     => $analytics['emotion'],
                ':frustration' => $analytics['frustration'],
                ':conv_trend'  => $analytics['conversation_trend'],
                ':intent_label'=> $analytics['intent_label'],
                ':conv_stage'  => $analytics['conversation_stage'],
                ':lead_intent' => $analytics['lead_intent'],
                ':needs_human' => $analytics['needs_human'],
            ]);

            // Save follow-up bubble to DB for future context
            if (!empty($followUpMessage)) {
                $stmtFuMsg = $db->prepare("
                    INSERT INTO messages (conversation_id, organization_id, role, content, knowledge_sources_used, tokens_used, created_at)
                    VALUES (:conv_id, :org_id, 'assistant', :content, '[]', 0, NOW())
                ");
                $stmtFuMsg->execute([
                    ':conv_id' => $convId,
                    ':org_id'  => $orgId,
                    ':content' => $followUpMessage
                ]);
            }

            // 14. Update conversation-level aggregated analytics state
            $needsHumanInt = $analytics['needs_human'];
            $latestSentimentSql  = $analytics['sentiment']          ? "'" . $analytics['sentiment'] . "'"          : 'NULL';
            $latestFrustSql      = $analytics['frustration'] !== null ? (float)$analytics['frustration']            : 'NULL';
            $latestLeadIntSql    = $analytics['lead_intent']         ? "'" . $analytics['lead_intent'] . "'"        : 'NULL';
            $latestStageSql      = $analytics['conversation_stage']  ? "'" . $analytics['conversation_stage'] . "'" : 'NULL';

            $db->exec("
                UPDATE conversations SET
                    latest_sentiment          = {$latestSentimentSql},
                    latest_frustration        = {$latestFrustSql},
                    latest_lead_intent        = {$latestLeadIntSql},
                    latest_conversation_stage = {$latestStageSql},
                    needs_human               = {$needsHumanInt}
                WHERE id = {$convId}
            ");

            // 15. Update Monthly Usage Logs (Skip test conversations)
            if ($isTest === 0) {
                $period = date('Y-m');
                $db->exec("
                    INSERT INTO usage_logs (organization_id, period, messages_count, tokens_used)
                    VALUES ({$orgId}, '{$period}', 1, {$tokensUsed})
                    ON DUPLICATE KEY UPDATE
                    messages_count = messages_count + 1,
                    tokens_used = tokens_used + {$tokensUsed}
                ");
            }

            // Build masked email for returning visitor
            $maskedEmail = null;
            if ($leadCaptured && $visitorEmail) {
                $parts = explode('@', $visitorEmail);
                $maskedUser  = substr($parts[0], 0, 2) . str_repeat('*', max(3, strlen($parts[0]) - 2));
                $maskedEmail = $maskedUser . '@' . ($parts[1] ?? 'email.com');
            }

            Response::success([
                'conversation_id'      => $convId,
                'is_test'              => $isTest,
                'response'             => $aiResponseText,
                'follow_up_message'    => $followUpMessage,
                'sources_used'         => array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title']], $contextSources),
                'lead_capture_trigger' => $leadTriggerPayload,
                'intent_tier'          => $intentTier,
                'turn_count'           => $turnCount,
                'lead_captured'        => $leadCaptured,
                'masked_email'         => $maskedEmail,
                // Analytics fields for frontend awareness
                'sentiment'            => $analytics['sentiment'],
                'frustration'          => $analytics['frustration'],
                'lead_intent'          => $analytics['lead_intent'],
                'conversation_stage'   => $analytics['conversation_stage'],
                'needs_human'          => (bool)$analytics['needs_human'],
            ]);

        } catch (Throwable $e) {
            Response::error("Failed to generate AI response: " . $e->getMessage(), 500);
        }
    }

    /**
     * Resolve structured lead trigger and match with active program knowledge_sources or campus tour slots
     */
    private static function resolveLeadTrigger(PDO $db, int $orgId, string $triggerType, string $userMessage, ?array $activeProgram = null): ?array
    {
        $progId = !empty($activeProgram['id']) ? (int)$activeProgram['id'] : 0;
        $progName = $activeProgram['course_name'] ?? '';

        if ($triggerType === 'asset_delivery') {
            // Must have a program identified
            if ($progId <= 0) {
                return null;
            }

            // Find active lead_magnet document in knowledge_sources table for this specific program
            $stmtAsset = $db->prepare("
                SELECT id, title, category, file_path, original_file_path
                FROM knowledge_sources
                WHERE organization_id = :oid
                  AND program_id = :pid
                  AND lead_magnet = 1
                  AND status = 'active'
                  AND file_path IS NOT NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtAsset->execute([':oid' => $orgId, ':pid' => $progId]);
            $asset = $stmtAsset->fetch();

            if (!$asset) {
                // If no active lead magnet document exists for this program in knowledge_sources, forbid the offer
                return null;
            }

            $assetTitle = $asset['title'] ?? ($progName . ' Detailed Guide (PDF)');
            $assetId = (int)$asset['id'];

            return [
                'type' => 'asset_delivery',
                'asset_id' => $assetId,
                'headline' => "Get " . $assetTitle,
                'program_name' => $progName,
                'description' => "Enter your details to receive {$assetTitle} sent directly to your email.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if ($triggerType === 'counselor_callback') {
            $progSubject = !empty($progName) ? " for {$progName}" : "";
            return [
                'type' => 'counselor_callback',
                'headline' => "Request a Counselor Callback",
                'program_name' => $progName,
                'description' => "Leave your contact number so our admissions counselor can connect with you regarding admissions{$progSubject}.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if ($triggerType === 'campus_tour') {
            return [
                'type' => 'campus_tour',
                'headline' => "Schedule a Guided Campus Tour",
                'program_name' => $progName,
                'description' => "Experience our campus, labs, and academic facilities firsthand with a personalized guided visit.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if ($triggerType === 'scholarship_calculator' || $triggerType === 'scholarship_eval' || $triggerType === 'scholarship') {
            return [
                'type' => 'scholarship_eval',
                'headline' => "Evaluate Scholarship Eligibility",
                'program_name' => $progName,
                'description' => "Check your merit score and calculate tuition fee waiver in 30 seconds.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        return null;
    }
}
