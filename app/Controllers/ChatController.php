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
                   visitor_name, visitor_email, visitor_phone, lead_captured_at,
                   lead_program_interest, program_id, last_offer_turn, total_offers_count, lead_forms_shown
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
        $leadFormsShown = [];

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
            if (!empty($conv['lead_forms_shown'])) {
                $decoded = json_decode($conv['lead_forms_shown'], true);
                if (is_array($decoded)) {
                    $leadFormsShown = $decoded;
                }
            }
            $db->exec("UPDATE conversations SET last_message_at = NOW() WHERE id = {$convId}");
        }

        // 3. Save User Message to DB
        $stmtUserMsg = $db->prepare("
            INSERT INTO messages (conversation_id, organization_id, role, content, is_fallback, source, created_at)
            VALUES (:conv_id, :org_id, 'user', :content, 0, 'user', NOW())
        ");
        $stmtUserMsg->execute([
            ':conv_id' => $convId,
            ':org_id' => $orgId,
            ':content' => $userMessage
        ]);

        // 4. Fetch Recent Conversation History (strictly past 6 messages including the message just saved)
        $stmtHistory = $db->prepare("
            SELECT role, content FROM messages
            WHERE conversation_id = :conv_id
            ORDER BY id DESC LIMIT 6
        ");
        $stmtHistory->execute([':conv_id' => $convId]);
        $history = array_reverse($stmtHistory->fetchAll(PDO::FETCH_ASSOC));

        // Previous messages prior to the current turn (to prevent duplicating $userMessage in LLM completion array)
        $prevHistory = array_slice($history, 0, -1);

        // 5. Vector Search Knowledge Retrieval (scoped to detected program if present)
        $retrievalTarget = $userMessage;
        if (IntentClassifier::isAffirmativeResponse($userMessage, $prevHistory) && !empty($prevHistory)) {
            for ($hIdx = count($prevHistory) - 1; $hIdx >= 0; $hIdx--) {
                if (($prevHistory[$hIdx]['role'] ?? '') === 'assistant') {
                    $retrievalTarget = $prevHistory[$hIdx]['content'] ?? $userMessage;
                    break;
                }
            }
        }
        $englishQuery = QueryTranslator::translateToEnglish($retrievalTarget);
        $contextSources = ContentEngine::selectContext($orgId, $englishQuery, $botId, $currentProgramId ?? null);

        // 6. Proactive Program Interest Detection & Early Lead Sync to DB
        $detectedProgram = ProgramDetector::detect($db, $orgId, $userMessage, $contextSources);
        if ($detectedProgram) {
            $currentProgramInterest = $detectedProgram['course_name'];
            $currentProgramId = (int)$detectedProgram['id'];
            ProgramDetector::syncProgramLead($db, $orgId, $botId, $convId, $detectedProgram);
        }

        $activeProgramData = null;
        if ($detectedProgram) {
            $activeProgramData = [
                'id' => $currentProgramId,
                'course_name' => $currentProgramInterest
            ];
        } else {
            $stmtLeadCheck = $db->prepare("
                SELECT id, program_interest, program_id
                FROM leads
                WHERE conversation_id = :cid AND organization_id = :oid AND program_interest IS NOT NULL AND program_interest != ''
                ORDER BY id DESC LIMIT 1
            ");
            $stmtLeadCheck->execute([':cid' => $convId, ':oid' => $orgId]);
            $leadRecord = $stmtLeadCheck->fetch(PDO::FETCH_ASSOC);

            if ($leadRecord && !empty($leadRecord['program_interest'])) {
                $activeProgramData = [
                    'id' => (int)($leadRecord['program_id'] ?? $currentProgramId),
                    'course_name' => $leadRecord['program_interest']
                ];
            } elseif (!empty($currentProgramInterest)) {
                $activeProgramData = [
                    'id' => $currentProgramId,
                    'course_name' => $currentProgramInterest
                ];
            }
        }

        // 7. Calculate Turn Count and Cadence Counter (user_message_count since last assistant offer)
        $stmtTurns = $db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = :cid AND role = 'user'");
        $stmtTurns->execute([':cid' => $convId]);
        $turnCount = (int)$stmtTurns->fetchColumn();

        $lastOfferTurn = (int)($conv['last_offer_turn'] ?? 0);
        $userMessageCountSinceLastOffer = max(0, $turnCount - $lastOfferTurn);

        // 8. Build Fresh Structured System Prompt
        $systemPrompt = PromptBuilder::build(
            $orgId,
            $contextSources,
            $activeProgramData,
            $leadCaptured,
            $leadFormsShown,
            $userMessageCountSinceLastOffer,
            $history,
            $bot['system_prompt_override'] ?? null
        );

        // 9. Invoke LLM Service with Strict Structured Schema
        $llmContext = [
            'organization_id'          => $bot['organization_id'] ?? null,
            'chatbot_id'               => $bot['id'] ?? null,
            'activity_type'            => 'chat_completion',
            'reference_type'           => 'conversation',
            'reference_id'             => $convId,
            'description'              => "Chat turn #{$turnCount}",
            'response_format_override' => LlmService::getAdmissionsResponseSchema(),
        ];

        try {
            $llmResult = LlmService::complete($systemPrompt, $userMessage, $prevHistory, $llmContext);
            $rawAiResponse = $llmResult['text'];
            $tokensUsed = $llmResult['tokens_used'];

            // 10. Parse Structured JSON Response
            $parsed            = null;
            $aiResponseText    = null;
            $followUpMessage   = null;
            $parsedIntent      = 'b';
            $rawTriggerType    = null;
            $rawProgramTrigger = null;

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

            // Strip markdown code fences if any
            $cleanedRaw = trim(preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```\s*$/i', '', $rawAiResponse)));
            $parsed = json_decode($cleanedRaw, true);

            if (is_array($parsed)) {
                $parsedIntent = strtolower(trim($parsed['intent'] ?? 'b'));
                $bubble1 = isset($parsed['bubble_1']) ? ($parsed['bubble_1'] !== null ? trim($parsed['bubble_1']) : null) : (isset($parsed['response']) && $parsed['response'] !== null ? trim($parsed['response']) : null);
                $bubble2 = isset($parsed['bubble_2']) ? ($parsed['bubble_2'] !== null ? trim($parsed['bubble_2']) : null) : (isset($parsed['follow_up']) && $parsed['follow_up'] !== null ? trim($parsed['follow_up']) : null);

                $aiResponseText  = ($bubble1 !== null && $bubble1 !== '') ? $bubble1 : null;
                $followUpMessage = ($bubble2 !== null && $bubble2 !== '') ? $bubble2 : null;

                $rawTriggerType = !empty($parsed['lead_form_trigger']) ? strtolower(trim($parsed['lead_form_trigger'])) : (!empty($parsed['lead_trigger']) ? strtolower(trim($parsed['lead_trigger'])) : null);
                if ($rawTriggerType === 'scholarship_calculator') {
                    $rawTriggerType = 'scholarship_eval';
                }
                $rawProgramTrigger = !empty($parsed['program_trigger']) ? strtolower(trim($parsed['program_trigger'])) : null;

                $analytics['sentiment']          = in_array($parsed['sentiment'] ?? '', ['positive', 'neutral', 'negative']) ? $parsed['sentiment'] : null;
                $analytics['emotion']            = !empty($parsed['emotion']) ? substr(trim($parsed['emotion']), 0, 50) : null;
                $analytics['frustration']        = isset($parsed['frustration']) ? max(0.0, min(1.0, (float)$parsed['frustration'])) : null;
                $analytics['conversation_trend'] = in_array($parsed['conversation_trend'] ?? '', ['improving', 'stable', 'declining']) ? $parsed['conversation_trend'] : null;
                $analytics['intent_label']       = !empty($parsed['intent']) ? substr(trim($parsed['intent']), 0, 50) : null;
                $analytics['conversation_stage'] = in_array($parsed['conversation_stage'] ?? '', ['discovery', 'consideration', 'decision', 'application']) ? $parsed['conversation_stage'] : null;
                $analytics['lead_intent']        = in_array($parsed['lead_intent'] ?? '', ['low', 'medium', 'high']) ? $parsed['lead_intent'] : null;
                $analytics['needs_human']        = !empty($parsed['needs_human']) ? 1 : 0;
            } else {
                error_log("[ChatController] LLM JSON parse failed. Raw: " . substr($rawAiResponse, 0, 300));
                $aiResponseText = trim($rawAiResponse);
            }

            // 11. Intent-Specific Action Handlers
            $leadTriggerPayload = null;
            $programCatalogPayload = null;

            // [a] ACCEPTING_PREVIOUS_OFFER: Form opens with strictly zero conversational text
            $isAffirmative = IntentClassifier::isAffirmativeResponse($userMessage, $prevHistory);
            if ($parsedIntent === 'a' || (!empty($rawTriggerType) && empty($followUpMessage) && $isAffirmative)) {
                $aiResponseText = null;
                $followUpMessage = null;

                if (empty($rawTriggerType)) {
                    $lastOfferText = '';
                    for ($hIdx = count($prevHistory) - 1; $hIdx >= 0; $hIdx--) {
                        if (($prevHistory[$hIdx]['role'] ?? '') === 'assistant') {
                            $lastOfferText = strtolower($prevHistory[$hIdx]['content'] ?? '');
                            break;
                        }
                    }
                    if (str_contains($lastOfferText, 'tour') || str_contains($lastOfferText, 'visit')) {
                        $rawTriggerType = 'campus_tour';
                    } elseif (str_contains($lastOfferText, 'scholarship') || str_contains($lastOfferText, 'waiver')) {
                        $rawTriggerType = 'scholarship_eval';
                    } elseif (str_contains($lastOfferText, 'syllabus') || str_contains($lastOfferText, 'brochure') || str_contains($lastOfferText, 'prospectus')) {
                        $rawTriggerType = 'brochure';
                    } elseif (str_contains($lastOfferText, 'call') || str_contains($lastOfferText, 'advisor') || str_contains($lastOfferText, 'counselor') || str_contains($lastOfferText, 'staff') || str_contains($lastOfferText, 'complaint')) {
                        $rawTriggerType = 'counselor_callback';
                    }
                }

                if (!empty($rawTriggerType)) {
                    $leadTriggerPayload = self::resolveLeadTrigger($db, $orgId, $rawTriggerType, $userMessage, $activeProgramData);
                    $leadFormsShown[] = ($rawTriggerType === 'scholarship_eval') ? 'scholarship_calculator' : $rawTriggerType;
                    $leadFormsShown = array_values(array_unique($leadFormsShown));
                }
            }

            // [c] SEEKING_CATALOGUE: Interactive catalog triggers with strictly zero conversational filler
            if ($parsedIntent === 'c' || (!empty($rawProgramTrigger) && $rawProgramTrigger !== 'null')) {
                $aiResponseText = null;
                $followUpMessage = null;

                $catalogFilter = 'all';
                $cleanUserMsg = strtolower($userMessage);
                if (preg_match('/\b(undergrad|undergraduate|bachelor|bachelors|ug)\b/i', $cleanUserMsg) || $rawProgramTrigger === 'undergraduate') {
                    $catalogFilter = 'undergraduate';
                } elseif (preg_match('/\b(postgrad|postgraduate|graduate|master|masters|pg)\b/i', $cleanUserMsg) || in_array($rawProgramTrigger, ['graduate', 'postgraduate'], true)) {
                    $catalogFilter = 'graduate';
                } elseif (preg_match('/\b(phd|ph\.d|doctor|doctoral|doctorate)\b/i', $cleanUserMsg) || $rawProgramTrigger === 'doctoral') {
                    $catalogFilter = 'doctoral';
                } elseif (preg_match('/\b(certificate|diploma|executive)\b/i', $cleanUserMsg) || in_array($rawProgramTrigger, ['certificate', 'certificates', 'executive'], true)) {
                    $catalogFilter = 'certificates';
                }
                $programCatalogPayload = self::resolveProgramCatalog($db, $orgId, $catalogFilter);
            }

            // [g] COMPLAINT_OR_STATUS_CHECK: Politely ask to connect with appropriate staff
            if ($parsedIntent === 'g') {
                $aiResponseText = "Do you want me to connect you to the appropriate staff to get you the correct information or pass along your suggestion/complaint?";
                $followUpMessage = null;
                $leadTriggerPayload = null;
            }

            // [e] EMOTIONAL_DISTRESS & [f] WANTS_HUMAN: Escalate directly
            if ($parsedIntent === 'e' || $parsedIntent === 'f') {
                $analytics['needs_human'] = 1;
                $analytics['frustration'] = max($analytics['frustration'] ?? 0.85, 0.85);
            }

            // [h] OUT_OF_SCOPE & [i] SENSITIVE_OR_HIGH_RISK: No offers
            if ($parsedIntent === 'h' || $parsedIntent === 'i') {
                $followUpMessage = null;
                $leadTriggerPayload = null;
            }

            // Cadence Gate & Cardinal Rules on bubble_2 (follow_up_message):
            if (!empty($followUpMessage)) {
                $programKnown = !empty($activeProgramData['course_name']);
                $isEscalation = ($parsedIntent === 'e' || $parsedIntent === 'f');

                // Cardinal Rule 1: No proactive offers without program interest (except escalation e & f)
                if (!$programKnown && !$isEscalation) {
                    $followUpMessage = null;
                }
                // Cardinal Rule 4: Post-lead capture restriction (only campus_tour allowed)
                elseif ($leadCaptured) {
                    $isTourOffer = str_contains(strtolower($followUpMessage), 'tour') || str_contains(strtolower($followUpMessage), 'visit');
                    if (!$isTourOffer || $userMessageCountSinceLastOffer < 3 || in_array('campus_tour', $leadFormsShown, true)) {
                        $followUpMessage = null;
                    }
                }
                // Cadence Gate: user_message_count >= 3 required (except escalation e & f)
                elseif ($userMessageCountSinceLastOffer < 3 && !$isEscalation) {
                    $followUpMessage = null;
                }
            }

            // Track offer presentation in session state
            if (!empty($followUpMessage)) {
                $lowerFu = strtolower($followUpMessage);
                $offeredKey = 'counselor_callback';
                if (str_contains($lowerFu, 'tour') || str_contains($lowerFu, 'visit')) {
                    $offeredKey = 'campus_tour';
                } elseif (str_contains($lowerFu, 'scholarship') || str_contains($lowerFu, 'waiver')) {
                    $offeredKey = 'scholarship_calculator';
                } elseif (str_contains($lowerFu, 'brochure') || str_contains($lowerFu, 'prospectus') || str_contains($lowerFu, 'syllabus')) {
                    $offeredKey = 'brochure';
                }
                $leadFormsShown[] = $offeredKey;
                $leadFormsShown = array_values(array_unique($leadFormsShown));

                $stmtConvUpdate = $db->prepare("UPDATE conversations SET last_offer_turn = :turn, total_offers_count = total_offers_count + 1, lead_forms_shown = :shown WHERE id = :id");
                $stmtConvUpdate->execute([
                    ':turn' => $turnCount,
                    ':shown' => json_encode($leadFormsShown),
                    ':id' => $convId
                ]);
            } elseif (!empty($leadTriggerPayload)) {
                $stmtConvUpdate = $db->prepare("UPDATE conversations SET lead_forms_shown = :shown WHERE id = :id");
                $stmtConvUpdate->execute([
                    ':shown' => json_encode($leadFormsShown),
                    ':id' => $convId
                ]);
            }

            // Fail-safe: ensure chatbot never returns completely blank if no form or catalog is showing
            if (empty($aiResponseText) && empty($leadTriggerPayload) && empty($programCatalogPayload)) {
                if (!empty($followUpMessage)) {
                    $aiResponseText = $followUpMessage;
                    $followUpMessage = null;
                } else {
                    $aiResponseText = "How can I assist you with our academic programs and admissions today?";
                }
            }

            $sourceIdsUsed = array_map(fn($s) => $s['id'], $contextSources);
            $isFallback    = !empty($llmResult['is_fallback']) ? 1 : 0;
            $msgSource     = $llmResult['source'] ?? 'llm';

            // 12. Save AI response to messages table (ONLY IF TEXT IS NOT EMPTY)
            if (!empty($aiResponseText)) {
                $stmtAiMsg = $db->prepare("
                    INSERT INTO messages (
                        conversation_id, organization_id, role, content,
                        knowledge_sources_used, tokens_used,
                        sentiment, emotion, frustration, conversation_trend,
                        intent_label, conversation_stage, lead_intent, needs_human,
                        is_fallback, source,
                        created_at
                    ) VALUES (
                        :conv_id, :org_id, 'assistant', :content,
                        :sources, :tokens,
                        :sentiment, :emotion, :frustration, :conv_trend,
                        :intent_label, :conv_stage, :lead_intent, :needs_human,
                        :is_fallback, :source,
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
                    ':is_fallback' => $isFallback,
                    ':source'      => $msgSource,
                ]);
            }

            // Save follow-up bubble to DB for future context
            if (!empty($followUpMessage)) {
                $stmtFuMsg = $db->prepare("
                    INSERT INTO messages (conversation_id, organization_id, role, content, knowledge_sources_used, tokens_used, is_fallback, source, created_at)
                    VALUES (:conv_id, :org_id, 'assistant', :content, '[]', 0, :is_fallback, :source, NOW())
                ");
                $stmtFuMsg->execute([
                    ':conv_id'     => $convId,
                    ':org_id'      => $orgId,
                    ':content'     => $followUpMessage,
                    ':is_fallback' => $isFallback,
                    ':source'      => $msgSource,
                ]);
            }

            // 13. Update conversation-level aggregated analytics state
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

            // 14. Update Monthly Usage Logs (Skip test conversations)
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
                'response'             => (!empty($aiResponseText) && trim($aiResponseText) !== '') ? $aiResponseText : null,
                'follow_up_message'    => (!empty($followUpMessage) && trim($followUpMessage) !== '') ? $followUpMessage : null,
                'sources_used'         => array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title']], $contextSources),
                'lead_capture_trigger' => $leadTriggerPayload,
                'program_catalog'      => $programCatalogPayload,
                'intent_tier'          => $parsedIntent,
                'turn_count'           => $turnCount,
                'user_message_count'   => $userMessageCountSinceLastOffer,
                'lead_captured'        => $leadCaptured,
                'lead_forms_shown'     => $leadFormsShown,
                'active_program'       => $activeProgramData ? $activeProgramData['course_name'] : null,
                'masked_email'         => $maskedEmail,
                'sentiment'            => $analytics['sentiment'],
                'frustration'          => $analytics['frustration'],
                'lead_intent'          => $analytics['lead_intent'],
                'conversation_stage'   => $analytics['conversation_stage'],
                'needs_human'          => (bool)$analytics['needs_human'],
                'is_fallback'          => (bool)$isFallback,
                'source'               => $msgSource,
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

        if ($triggerType === 'asset_delivery' || $triggerType === 'brochure' || $triggerType === 'program_brochure') {
            $asset = null;
            if ($progId > 0) {
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
            }

            $assetTitle = $asset['title'] ?? (!empty($progName) ? ($progName . ' Prospectus & Brochure') : 'Official Academic Prospectus');
            $assetId = !empty($asset['id']) ? (int)$asset['id'] : null;

            return [
                'type' => 'asset_delivery',
                'asset_id' => $assetId,
                'headline' => "Download " . $assetTitle,
                'program_name' => $progName,
                'description' => "Enter your details to receive {$assetTitle} sent directly to your email.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if (in_array($triggerType, ['counselor_callback', 'callback', 'human_callback'], true)) {
            $progSubject = !empty($progName) ? " for {$progName}" : "";
            return [
                'type' => 'counselor_callback',
                'headline' => "Request a Counselor Callback",
                'program_name' => $progName,
                'description' => "Leave your contact number so our admissions counselor can connect with you regarding admissions{$progSubject}.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if (in_array($triggerType, ['campus_tour', 'tour', 'visit'], true)) {
            return [
                'type' => 'campus_tour',
                'headline' => "Schedule a Guided Campus Tour",
                'program_name' => $progName,
                'description' => "Experience our campus, labs, and academic facilities firsthand with a personalized guided visit.",
                'fields' => ['name', 'email', 'phone']
            ];
        }

        if (in_array($triggerType, ['scholarship_calculator', 'scholarship_eval', 'scholarship'], true)) {
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

    /**
     * Resolve structured program catalog directory for interactive widget UI
     */
    private static function resolveProgramCatalog(PDO $db, int $orgId, string $filter = 'all'): ?array
    {
        $stmtProg = $db->prepare("
            SELECT id, course_name, course_code, program_type, duration, tuition_fee, currency
            FROM programs
            WHERE organization_id = :org_id AND is_admissions_open = 1
            ORDER BY sort_order ASC, course_name ASC
        ");
        $stmtProg->execute([':org_id' => $orgId]);
        $programs = $stmtProg->fetchAll(PDO::FETCH_ASSOC);

        if (empty($programs)) {
            return null;
        }

        $categoriesMap = [
            'undergraduate' => [
                'key'         => 'undergraduate',
                'label'       => "Undergraduate (Bachelor's)",
                'short_label' => 'Undergraduate',
                'programs'    => []
            ],
            'graduate' => [
                'key'         => 'graduate',
                'label'       => "Graduate / Postgraduate (Master's)",
                'short_label' => 'Graduate',
                'programs'    => []
            ],
            'doctoral' => [
                'key'         => 'doctoral',
                'label'       => "Doctoral (Ph.D.)",
                'short_label' => 'Doctoral',
                'programs'    => []
            ],
            'certificates' => [
                'key'         => 'certificates',
                'label'       => "Certificates & Executive",
                'short_label' => 'Certificates',
                'programs'    => []
            ],
            'other' => [
                'key'         => 'other',
                'label'       => "Other Academic Programs",
                'short_label' => 'Other',
                'programs'    => []
            ]
        ];

        foreach ($programs as $p) {
            $type = strtolower(trim($p['program_type'] ?? ''));
            $item = [
                'id'          => (int)$p['id'],
                'course_name' => $p['course_name'],
                'course_code' => $p['course_code'] ?? '',
                'duration'    => !empty($p['duration']) ? trim($p['duration']) : null,
            ];

            if (in_array($type, ['undergraduate', 'bachelor', 'bachelors', 'ug'], true)) {
                $categoriesMap['undergraduate']['programs'][] = $item;
            } elseif (in_array($type, ['postgraduate', 'graduate', 'master', 'masters', 'pg'], true)) {
                $categoriesMap['graduate']['programs'][] = $item;
            } elseif (in_array($type, ['doctoral', 'phd', 'doctorate'], true)) {
                $categoriesMap['doctoral']['programs'][] = $item;
            } elseif (in_array($type, ['certificate', 'diploma', 'executive'], true)) {
                $categoriesMap['certificates']['programs'][] = $item;
            } else {
                $categoriesMap['other']['programs'][] = $item;
            }
        }

        $activeCategories = [];
        foreach ($categoriesMap as $cat) {
            if (!empty($cat['programs'])) {
                $activeCategories[] = $cat;
            }
        }

        if (empty($activeCategories)) {
            return null;
        }

        return [
            'headline'    => 'Academic Programs',
            'filter'      => $filter,
            'total_count' => count($programs),
            'categories'  => $activeCategories
        ];
    }
}
