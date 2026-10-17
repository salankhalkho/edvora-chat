<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Services\ContentCompactor;
use App\Services\DocumentParser;
use App\Services\InformationalPromptBuilder;
use App\Services\KnowledgeFileStorage;
use App\Services\UrlScraper;
use PDO;
use Throwable;

class SystemBotController
{
    /**
     * Resolve or initialize the internal Edvora System Organization & Chatbot (`is_system = 1` / `chat_mode = 'informational'`).
     * Strictly scoped to the system organization so customer tenants are never touched.
     */
    public static function resolveSystemBot(PDO $db): array
    {
        $stmtOrg = $db->query("SELECT * FROM organizations WHERE is_system = 1 LIMIT 1");
        $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);

        if (!$org) {
            $stmtInsOrg = $db->prepare("
                INSERT INTO organizations (name, slug, primary_color, subscription_status, is_system, website_url, created_at, updated_at)
                VALUES ('Edvora.chat (System)', 'edvora-system', '#4F46E5', 'active', 1, 'https://edvora.chat', NOW(), NOW())
            ");
            $stmtInsOrg->execute();
            $sysOrgId = (int)$db->lastInsertId();
            $db->exec("UPDATE organizations SET institute_id = MD5(CAST(id AS CHAR)) WHERE id = {$sysOrgId}");

            $stmtOrg = $db->prepare("SELECT * FROM organizations WHERE id = :id");
            $stmtOrg->execute([':id' => $sysOrgId]);
            $org = $stmtOrg->fetch(PDO::FETCH_ASSOC);
        }

        $orgId = (int)$org['id'];

        $stmtBot = $db->prepare("SELECT * FROM chatbots WHERE organization_id = :oid LIMIT 1");
        $stmtBot->execute([':oid' => $orgId]);
        $bot = $stmtBot->fetch(PDO::FETCH_ASSOC);

        if (!$bot) {
            $botToken = 'edvora_saas_' . substr(md5('edvora_system_bot_' . $orgId), 0, 20);
            $welcomeMsg = "Hi there! 👋 Welcome to Edvora.chat. Ask me anything about our AI Admissions Chatbot platform, features, integrations, or pricing plans!";
            $stmtInsBot = $db->prepare("
                INSERT INTO chatbots (organization_id, name, welcome_message, primary_color, bot_token, allowed_domains, is_active, chat_mode, lead_capture_enabled, quick_chips, created_at, updated_at)
                VALUES (:oid, 'Edvora Product Assistant', :welcome, '#4F46E5', :token, :domains, 1, 'informational', 0, '[]', NOW(), NOW())
            ");
            $stmtInsBot->execute([
                ':oid' => $orgId,
                ':welcome' => $welcomeMsg,
                ':token' => $botToken,
                ':domains' => json_encode(['edvora.chat', '*.edvora.chat', 'localhost', '127.0.0.1'])
            ]);
            $botId = (int)$db->lastInsertId();

            $stmtBot = $db->prepare("SELECT * FROM chatbots WHERE id = :bid");
            $stmtBot->execute([':bid' => $botId]);
            $bot = $stmtBot->fetch(PDO::FETCH_ASSOC);
        }

        return [
            'organization' => $org,
            'bot'          => $bot,
            'org_id'       => $orgId,
            'bot_id'       => (int)$bot['id'],
        ];
    }

    /**
     * GET /v1/superadmin/system-bot/config
     * Returns system bot metadata, embed code, widget customization, live plans preview, and system prompt.
     */
    public function getConfig(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $orgId = $sys['org_id'];
        $botId = $sys['bot_id'];
        $bot = $sys['bot'];

        $customization = WidgetCustomizationController::cascadeLookup($db, $botId, $orgId);
        $customization['show_action_brochure'] = false;
        $customization['show_action_callback'] = false;
        $customization['show_action_tour'] = false;
        $customization['quick_chips'] = '';

        $basePrompt = InformationalPromptBuilder::getBaseSystemPrompt($db);
        $defaultPrompt = InformationalPromptBuilder::getDefaultPrompt();
        $livePlansPreview = InformationalPromptBuilder::buildLivePlansContext($db);

        // Knowledge count & chunks count
        $stmtKCount = $db->prepare("SELECT COUNT(*) FROM knowledge_sources WHERE organization_id = :oid");
        $stmtKCount->execute([':oid' => $orgId]);
        $sourcesCount = (int)$stmtKCount->fetchColumn();

        $stmtCCount = $db->prepare("SELECT COUNT(*) FROM knowledge_items WHERE organization_id = :oid");
        $stmtCCount->execute([':oid' => $orgId]);
        $chunksCount = (int)$stmtCCount->fetchColumn();

        Response::success([
            'org_id'             => $orgId,
            'bot_id'             => $botId,
            'bot_token'          => $bot['bot_token'],
            'bot_name'           => $customization['header_bot_name'] ?: $bot['name'],
            'welcome_message'    => $customization['welcome_message'] ?: $bot['welcome_message'],
            'is_active'          => (bool)$bot['is_active'],
            'chat_mode'          => $bot['chat_mode'] ?? 'informational',
            'customization'      => $customization,
            'system_prompt'      => $basePrompt,
            'default_prompt'     => $defaultPrompt,
            'live_plans_context' => $livePlansPreview,
            'knowledge_stats'    => [
                'sources_count' => $sourcesCount,
                'chunks_count'  => $chunksCount,
            ],
        ]);
    }

    /**
     * PUT /v1/superadmin/system-bot/customization
     * Saves widget customization (colors, avatar, welcome message, bot name, subtitle, theme, etc.) for the system bot.
     */
    public function updateCustomization(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $orgId = $sys['org_id'];
        $botId = $sys['bot_id'];

        $GLOBALS['organization_id'] = $orgId;
        $data = $request->all();
        $config = $data['config'] ?? $data;

        if (!is_array($config)) {
            Response::error('config must be an object.', 422);
        }

        // Force starter chips and admissions actions off for the informational system bot
        $config['quick_chips'] = '';
        $config['show_action_brochure'] = false;
        $config['show_action_callback'] = false;
        $config['show_action_tour'] = false;

        // Also update chatbots row primary_color, name, welcome_message if provided
        $botName = !empty($config['header_bot_name']) ? trim(strip_tags((string)$config['header_bot_name'])) : 'Edvora Product Assistant';
        $welcomeMsg = !empty($config['welcome_message']) ? trim(strip_tags((string)$config['welcome_message'])) : $sys['bot']['welcome_message'];
        $primaryColor = !empty($config['header_bg']) ? trim((string)$config['header_bg']) : ($sys['bot']['primary_color'] ?: '#4F46E5');
        $avatarUrl = !empty($config['avatar_url']) ? trim((string)$config['avatar_url']) : null;

        $db->prepare("
            UPDATE chatbots
            SET name = :name,
                welcome_message = :welcome,
                primary_color = :color,
                bot_avatar_url = :avatar,
                quick_chips = '[]',
                lead_capture_enabled = 0,
                chat_mode = 'informational',
                updated_at = NOW()
            WHERE id = :bid AND organization_id = :oid
        ")->execute([
            ':name'    => $botName,
            ':welcome' => $welcomeMsg,
            ':color'   => $primaryColor,
            ':avatar'  => $avatarUrl,
            ':bid'     => $botId,
            ':oid'     => $orgId,
        ]);

        $db->prepare("UPDATE organizations SET primary_color = :color, updated_at = NOW() WHERE id = :oid")
           ->execute([':color' => $primaryColor, ':oid' => $orgId]);

        // Delegate to WidgetCustomizationController::upsert with organization_id set
        $reqClone = new Request();
        // Directly upsert into widget_customizations using WidgetCustomizationController cascade
        $existingCfg = WidgetCustomizationController::cascadeLookup($db, $botId, $orgId);
        $mergedCfg = array_merge($existingCfg, $config);
        $mergedCfg['quick_chips'] = '';
        $mergedCfg['show_action_brochure'] = false;
        $mergedCfg['show_action_callback'] = false;
        $mergedCfg['show_action_tour'] = false;

        $configJson = json_encode($mergedCfg, JSON_UNESCAPED_UNICODE);

        $checkStmt = $db->prepare("SELECT id FROM widget_customizations WHERE chatbot_id = :bot AND organization_id = :org ORDER BY id DESC LIMIT 1");
        $checkStmt->execute([':bot' => $botId, ':org' => $orgId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $db->prepare("UPDATE widget_customizations SET config = :cfg, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':cfg' => $configJson, ':id' => $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO widget_customizations (chatbot_id, organization_id, config) VALUES (:bot, :org, :cfg)");
            $stmt->execute([':bot' => $botId, ':org' => $orgId, ':cfg' => $configJson]);
        }

        AuditLogger::log('system_bot_customization_updated', 'chatbot', $botId);

        Response::success([
            'saved'  => true,
            'config' => $mergedCfg,
        ], 'Edvora website chatbot customization saved.');
    }

    /**
     * POST /v1/superadmin/system-bot/avatar
     * Uploads avatar or header logo for the system bot.
     */
    public function uploadAvatar(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new WidgetCustomizationController();
        $ctrl->uploadImage($request);
    }

    /**
     * PUT /v1/superadmin/system-bot/prompt
     * Saves custom system prompt instructions for the informational bot.
     */
    public function updatePrompt(Request $request): void
    {
        $db = Database::getConnection();
        $prompt = trim((string)($request->get('system_prompt') ?? ''));

        if ($prompt === '') {
            $prompt = InformationalPromptBuilder::getDefaultPrompt();
        }

        $stmt = $db->prepare("
            INSERT INTO platform_config (key_name, value_text, updated_at)
            VALUES ('system_bot_prompt', :val, NOW())
            ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = NOW()
        ");
        $stmt->execute([':val' => $prompt]);

        AuditLogger::log('system_bot_prompt_updated', 'platform_config', null);

        Response::success(['system_prompt' => $prompt], 'System bot instructions updated.');
    }

    /**
     * GET /v1/superadmin/system-bot/knowledge
     * Lists all knowledge sources for the system bot.
     */
    public function listKnowledge(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->index($request);
    }

    /**
     * GET /v1/superadmin/system-bot/knowledge/{id}
     * View single knowledge source with full raw text content.
     */
    public function showKnowledge(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->show($request, $params);
    }

    /**
     * POST /v1/superadmin/system-bot/knowledge/paste
     * Add pasted text knowledge source to system bot.
     */
    public function pasteKnowledge(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->paste($request);
    }

    /**
     * POST /v1/superadmin/system-bot/knowledge/url
     * Scrape a URL and add to system bot knowledge base.
     */
    public function addUrlKnowledge(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->addUrl($request);
    }

    /**
     * POST /v1/superadmin/system-bot/knowledge/upload
     * Upload PDF, DOCX, or TXT to system bot knowledge base.
     */
    public function uploadKnowledge(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->upload($request);
    }

    /**
     * PUT /v1/superadmin/system-bot/knowledge/{id}
     * Edit title, category, status, or raw_content of a system bot knowledge source.
     */
    public function updateKnowledge(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->update($request, $params);
    }

    /**
     * DELETE /v1/superadmin/system-bot/knowledge/{id}
     * Delete a knowledge source and its vector chunks from the system bot.
     */
    public function deleteKnowledge(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];
        $GLOBALS['auth_user'] = ['role' => 'superadmin', 'can_manage_structure' => true];

        $ctrl = new KnowledgeController();
        $ctrl->delete($request, $params);
    }

    /**
     * POST /v1/superadmin/system-bot/knowledge/{id}/refresh
     * Re-scrape a URL knowledge source.
     */
    public function refreshKnowledge(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $GLOBALS['organization_id'] = $sys['org_id'];

        $ctrl = new KnowledgeController();
        $ctrl->refresh($request, $params);
    }

    /**
     * POST /v1/superadmin/system-bot/crawl-website
     * Crawls all core Edvora.chat marketing & documentation pages and queues them for vector embedding.
     */
    public function crawlWebsite(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $orgId = $sys['org_id'];
        $botId = $sys['bot_id'];

        $pagesToCrawl = [
            ['url' => 'https://edvora.chat/', 'title' => 'Edvora.chat — Platform Overview & Core Capabilities', 'category' => 'Platform Overview'],
            ['url' => 'https://edvora.chat/pricing', 'title' => 'Edvora.chat — Subscription Plans, Pricing & FAQ', 'category' => 'Plans & Pricing'],
            ['url' => 'https://edvora.chat/dashboard', 'title' => 'Edvora.chat — Admissions Command Center & Dashboard', 'category' => 'Product Features'],
            ['url' => 'https://edvora.chat/knowledge-hub', 'title' => 'Edvora.chat — Central Knowledge Hub & Document Governance', 'category' => 'Product Features'],
            ['url' => 'https://edvora.chat/attribution-platform', 'title' => 'Edvora.chat — Admissions Channel Attribution Platform', 'category' => 'Product Features'],
            ['url' => 'https://edvora.chat/full-funnel-tracking', 'title' => 'Edvora.chat — Full-Funnel Student Journey & Dwell Tracking', 'category' => 'Product Features'],
            ['url' => 'https://edvora.chat/mobile-integration', 'title' => 'Edvora.chat — Mobile App & WebView SDK Integration Guide', 'category' => 'Technical & Integration'],
            ['url' => 'https://edvora.chat/contact', 'title' => 'Edvora.chat — Contact, Support & Institutional Onboarding', 'category' => 'Support & Contact'],
            ['url' => 'https://edvora.chat/privacy', 'title' => 'Edvora.chat — Privacy Policy, FERPA, GDPR & Data Security', 'category' => 'Security & Compliance'],
            ['url' => 'https://edvora.chat/terms', 'title' => 'Edvora.chat — Terms of Service & SLA', 'category' => 'Security & Compliance'],
            ['url' => 'https://edvora.chat/refund-policy', 'title' => 'Edvora.chat — Cancellation & Refund Policy', 'category' => 'Billing & Policies'],
        ];

        $results = [];
        foreach ($pagesToCrawl as $p) {
            try {
                $scraped = UrlScraper::scrape($p['url']);
                $compacted = ContentCompactor::process($scraped['raw_content'], $p['title']);

                // Check if URL already exists for system org
                $stmtChk = $db->prepare("SELECT id FROM knowledge_sources WHERE organization_id = :oid AND source_url = :url LIMIT 1");
                $stmtChk->execute([':oid' => $orgId, ':url' => $p['url']]);
                $existingId = (int)$stmtChk->fetchColumn();

                if ($existingId > 0) {
                    $saveMeta = KnowledgeFileStorage::saveText($orgId, $existingId, $compacted['processed_content']);
                    $db->prepare("
                        UPDATE knowledge_sources
                        SET title = :title,
                            category = :cat,
                            file_path = :fp,
                            file_size_bytes = :sz,
                            token_count = :tk,
                            checksum_sha256 = :sha,
                            content_hash = :hash,
                            status = 'pending',
                            last_fetched_at = NOW(),
                            last_reviewed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':title' => $p['title'],
                        ':cat'   => $p['category'],
                        ':fp'    => $saveMeta['file_path'],
                        ':sz'    => $saveMeta['file_size_bytes'],
                        ':tk'    => $saveMeta['token_count'],
                        ':sha'   => $saveMeta['checksum_sha256'],
                        ':hash'  => $scraped['content_hash'],
                        ':id'    => $existingId,
                    ]);
                    $sourceId = $existingId;
                    $action = 'updated';
                } else {
                    $stmtIns = $db->prepare("
                        INSERT INTO knowledge_sources (
                            organization_id, chatbot_id, type, title, category,
                            effective_from, last_reviewed_at, review_frequency_days,
                            source_url, content_hash, status, last_fetched_at, created_at, updated_at
                        ) VALUES (
                            :oid, :bid, 'url', :title, :cat,
                            CURDATE(), CURDATE(), 180,
                            :url, :hash, 'pending', NOW(), NOW(), NOW()
                        )
                    ");
                    $stmtIns->execute([
                        ':oid'   => $orgId,
                        ':bid'   => $botId,
                        ':title' => $p['title'],
                        ':cat'   => $p['category'],
                        ':url'   => $p['url'],
                        ':hash'  => $scraped['content_hash'],
                    ]);
                    $sourceId = (int)$db->lastInsertId();
                    $saveMeta = KnowledgeFileStorage::saveText($orgId, $sourceId, $compacted['processed_content']);
                    $db->prepare("
                        UPDATE knowledge_sources
                        SET file_path = :fp, file_size_bytes = :sz, token_count = :tk, checksum_sha256 = :sha
                        WHERE id = :id
                    ")->execute([
                        ':fp'  => $saveMeta['file_path'],
                        ':sz'  => $saveMeta['file_size_bytes'],
                        ':tk'  => $saveMeta['token_count'],
                        ':sha' => $saveMeta['checksum_sha256'],
                        ':id'  => $sourceId,
                    ]);
                    $action = 'created';
                }

                // Queue chunk_and_embed job
                $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())")
                   ->execute([':p' => json_encode(['source_id' => $sourceId, 'organization_id' => $orgId, 'program_id' => null])]);

                $results[] = [
                    'url'    => $p['url'],
                    'title'  => $p['title'],
                    'id'     => $sourceId,
                    'action' => $action,
                    'tokens' => $saveMeta['token_count'],
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'url'    => $p['url'],
                    'title'  => $p['title'],
                    'action' => 'failed',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        Response::success([
            'crawled_count' => count($results),
            'results'       => $results,
        ], 'Edvora.chat website pages crawled and queued for vector embedding.');
    }

    /**
     * GET /v1/superadmin/system-bot/telemetry
     * Returns KPI telemetry, paginated conversations, session journeys, and token usage for the system bot.
     */
    public function getTelemetry(Request $request): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $orgId = $sys['org_id'];
        $botId = $sys['bot_id'];

        $days = (int)($request->get('days') ?? 30);
        if (!in_array($days, [7, 30, 60, 90, 365])) {
            $days = 30;
        }
        $search = trim((string)($request->get('search') ?? ''));

        // 1. Conversation & Message KPIs
        $stmtKpi = $db->prepare("
            SELECT
                COUNT(DISTINCT c.id) AS total_conversations,
                (SELECT COUNT(*) FROM messages m WHERE m.organization_id = :oid1 AND m.created_at >= DATE_SUB(NOW(), INTERVAL :d1 DAY)) AS total_messages,
                (SELECT COUNT(*) FROM messages m WHERE m.organization_id = :oid2 AND m.role = 'user' AND m.created_at >= DATE_SUB(NOW(), INTERVAL :d2 DAY)) AS user_questions,
                (SELECT COALESCE(SUM(m.tokens_used), 0) FROM messages m WHERE m.organization_id = :oid3 AND m.created_at >= DATE_SUB(NOW(), INTERVAL :d3 DAY)) AS total_tokens
            FROM conversations c
            WHERE c.organization_id = :oid4
              AND c.started_at >= DATE_SUB(NOW(), INTERVAL :d4 DAY)
        ");
        $stmtKpi->execute([
            ':oid1' => $orgId, ':d1' => $days,
            ':oid2' => $orgId, ':d2' => $days,
            ':oid3' => $orgId, ':d3' => $days,
            ':oid4' => $orgId, ':d4' => $days,
        ]);
        $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

        // 2. Visitor Session & Dwell KPIs
        $stmtSess = $db->prepare("
            SELECT
                COUNT(*) AS total_sessions,
                COALESCE(AVG(total_dwell_seconds), 0) AS avg_dwell_seconds,
                COALESCE(AVG(total_pages), 0) AS avg_pages,
                COALESCE(SUM(CASE WHEN conversion_status IN ('chat_engaged', 'lead_converted') THEN 1 ELSE 0 END), 0) AS chat_engaged_sessions
            FROM visitor_sessions
            WHERE organization_id = :oid
              AND started_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
        ");
        $stmtSess->execute([':oid' => $orgId, ':d' => $days]);
        $sessKpi = $stmtSess->fetch(PDO::FETCH_ASSOC) ?: [];

        // 3. LLM Spend for System Org
        $stmtSpend = $db->prepare("
            SELECT
                COALESCE(SUM(estimated_cost_usd), 0) AS total_cost_usd,
                COALESCE(AVG(latency_ms), 0) AS avg_latency_ms
            FROM llm_usage_logs
            WHERE organization_id = :oid
              AND created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
        ");
        $stmtSpend->execute([':oid' => $orgId, ':d' => $days]);
        $spendKpi = $stmtSpend->fetch(PDO::FETCH_ASSOC) ?: [];

        // 4. Intent distribution across assistant replies
        $stmtIntents = $db->prepare("
            SELECT COALESCE(intent_label, 'general_inquiry') AS intent_label, COUNT(*) AS count
            FROM messages
            WHERE organization_id = :oid
              AND role = 'assistant'
              AND created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
            GROUP BY COALESCE(intent_label, 'general_inquiry')
            ORDER BY count DESC
        ");
        $stmtIntents->execute([':oid' => $orgId, ':d' => $days]);
        $intents = $stmtIntents->fetchAll(PDO::FETCH_ASSOC);

        // 5. Conversations list with message count and preview
        $whereConv = ["c.organization_id = :oid", "c.started_at >= DATE_SUB(NOW(), INTERVAL :d DAY)"];
        $convParams = [':oid' => $orgId, ':d' => $days];

        if ($search !== '') {
            $whereConv[] = "(c.visitor_id LIKE :s OR c.page_url LIKE :s OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.content LIKE :s))";
            $convParams[':s'] = "%{$search}%";
        }
        $whereSql = implode(' AND ', $whereConv);

        $stmtConvs = $db->prepare("
            SELECT
                c.id,
                c.visitor_id,
                c.session_id,
                c.is_test,
                c.page_url,
                c.page_title,
                c.utm_source,
                c.utm_medium,
                c.utm_campaign,
                c.latest_sentiment,
                c.latest_conversation_stage,
                c.started_at,
                c.last_message_at,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS message_count,
                (SELECT COALESCE(SUM(m.tokens_used), 0) FROM messages m WHERE m.conversation_id = c.id) AS tokens_used,
                (SELECT m.content FROM messages m WHERE m.conversation_id = c.id AND m.role = 'user' ORDER BY m.id ASC LIMIT 1) AS first_user_message,
                (SELECT m.content FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message
            FROM conversations c
            WHERE {$whereSql}
            ORDER BY c.last_message_at DESC, c.id DESC
            LIMIT 100
        ");
        $stmtConvs->execute($convParams);
        $conversations = $stmtConvs->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'org_id'    => $orgId,
            'bot_id'    => $botId,
            'bot_token' => $sys['bot']['bot_token'],
            'days'      => $days,
            'summary'   => [
                'total_conversations'   => (int)($kpi['total_conversations'] ?? 0),
                'total_messages'        => (int)($kpi['total_messages'] ?? 0),
                'user_questions'        => (int)($kpi['user_questions'] ?? 0),
                'total_tokens'          => (int)($kpi['total_tokens'] ?? 0),
                'total_sessions'        => (int)($sessKpi['total_sessions'] ?? 0),
                'avg_dwell_seconds'     => (int)round((float)($sessKpi['avg_dwell_seconds'] ?? 0)),
                'avg_pages_per_session' => round((float)($sessKpi['avg_pages'] ?? 0), 1),
                'chat_engaged_sessions' => (int)($sessKpi['chat_engaged_sessions'] ?? 0),
                'total_cost_usd'        => round((float)($spendKpi['total_cost_usd'] ?? 0), 4),
                'avg_latency_ms'        => (int)round((float)($spendKpi['avg_latency_ms'] ?? 0)),
            ],
            'intent_breakdown' => $intents,
            'conversations'    => $conversations,
        ]);
    }

    /**
     * GET /v1/superadmin/system-bot/conversations/{id}
     * Returns full message transcript + visitor session steps for a specific conversation.
     */
    public function getConversationTranscript(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $sys = self::resolveSystemBot($db);
        $orgId = $sys['org_id'];
        $convId = (int)($params['id'] ?? 0);

        if ($convId <= 0) {
            Response::error('Invalid conversation ID.', 400);
        }

        $stmtConv = $db->prepare("
            SELECT * FROM conversations
            WHERE id = :cid AND organization_id = :oid
            LIMIT 1
        ");
        $stmtConv->execute([':cid' => $convId, ':oid' => $orgId]);
        $conv = $stmtConv->fetch(PDO::FETCH_ASSOC);

        if (!$conv) {
            Response::error('Conversation not found.', 404);
        }

        $stmtMsgs = $db->prepare("
            SELECT id, role, content, knowledge_sources_used, tokens_used,
                   sentiment, emotion, frustration, conversation_trend,
                   intent_label, conversation_stage, is_fallback, source, created_at
            FROM messages
            WHERE conversation_id = :cid AND organization_id = :oid
            ORDER BY id ASC
        ");
        $stmtMsgs->execute([':cid' => $convId, ':oid' => $orgId]);
        $messages = $stmtMsgs->fetchAll(PDO::FETCH_ASSOC);

        // Resolve knowledge source titles if used
        $sourceTitleCache = [];
        foreach ($messages as &$m) {
            $usedIds = !empty($m['knowledge_sources_used']) ? (json_decode($m['knowledge_sources_used'], true) ?: []) : [];
            $resolvedSources = [];
            foreach ($usedIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                if (!isset($sourceTitleCache[$sid])) {
                    $st = $db->prepare("SELECT title FROM knowledge_sources WHERE id = :id AND organization_id = :oid");
                    $st->execute([':id' => $sid, ':oid' => $orgId]);
                    $sourceTitleCache[$sid] = $st->fetchColumn() ?: "Source #{$sid}";
                }
                $resolvedSources[] = ['id' => $sid, 'title' => $sourceTitleCache[$sid]];
            }
            $m['sources_resolved'] = $resolvedSources;
        }
        unset($m);

        // Fetch visitor session steps if session_id is linked
        $sessionSteps = [];
        if (!empty($conv['session_id'])) {
            $stmtSteps = $db->prepare("
                SELECT url, page_title, time_spent_seconds, view_order, created_at
                FROM visitor_page_views
                WHERE session_id = :sid AND organization_id = :oid
                ORDER BY view_order ASC, id ASC
            ");
            $stmtSteps->execute([':sid' => $conv['session_id'], ':oid' => $orgId]);
            $sessionSteps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);
        }

        Response::success([
            'conversation'  => $conv,
            'messages'      => $messages,
            'session_steps' => $sessionSteps,
        ]);
    }
}
