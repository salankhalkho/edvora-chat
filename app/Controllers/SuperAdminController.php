<?php

namespace App\Controllers;

use App\Config\Database;
use App\Config\Env;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use App\Services\LlmService;
use App\Services\EmailService;
use PDO;
use Throwable;

class SuperAdminController
{
    /**
     * GET /v1/superadmin/prompt — Retrieve Master Prompt
     */
    public function getMasterPrompt(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT value_text, updated_at FROM platform_config WHERE key_name = 'master_prompt' LIMIT 1");
        $config = $stmt->fetch();

        Response::success([
            'master_prompt' => $config['value_text'] ?? '',
            'updated_at' => $config['updated_at'] ?? null
        ]);
    }

    /**
     * PUT /v1/superadmin/prompt — Update Master Prompt
     */
    public function updateMasterPrompt(Request $request, array $params = []): void
    {
        $prompt = trim((string)$request->get('master_prompt'));
        if (empty($prompt)) {
            Response::error('Master prompt cannot be empty.', 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO platform_config (key_name, value_text, updated_at)
            VALUES ('master_prompt', :prompt, NOW())
            ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = NOW()
        ");
        $stmt->execute([':prompt' => $prompt]);

        AuditLogger::log('master_prompt_updated', 'platform_config', null);

        Response::success(['master_prompt' => $prompt], 'Master prompt updated successfully');
    }

    /**
     * GET /v1/superadmin/llm-providers — List LLM Providers
     */
    public function getLlmProviders(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT id, name, provider, model_name, api_base_url, temperature, max_tokens, timeout_seconds, role, is_active, 
                   IF(api_key_encrypted IS NOT NULL AND api_key_encrypted != '', 1, 0) AS has_api_key,
                   last_tested_at, last_test_result, created_at, updated_at
            FROM llm_providers
            ORDER BY role = 'primary' DESC, role = 'fallback' DESC, id ASC
        ");
        $providers = $stmt->fetchAll();

        Response::success($providers);
    }

    /**
     * POST /v1/superadmin/llm-providers — Create LLM Provider
     */
    public function createLlmProvider(Request $request, array $params = []): void
    {
        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'provider' => 'required',
            'model_name' => 'required'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed: ' . implode(', ', array_map(fn($e) => implode(', ', $e), $errors)), 422, $errors);
        }

        $apiKey = trim($data['api_key'] ?? '');
        $encryptedKey = !empty($apiKey) ? self::encryptKey($apiKey) : '';
        $role = in_array($data['role'] ?? '', ['primary', 'fallback', 'embedding', 'inactive']) ? $data['role'] : 'inactive';

        $db = Database::getConnection();

        // If setting role as primary/fallback, unset existing provider with same role
        if ($role !== 'inactive') {
            $stmtUnset = $db->prepare("UPDATE llm_providers SET role = 'inactive' WHERE role = :role");
            $stmtUnset->execute([':role' => $role]);
        }

        $stmt = $db->prepare("
            INSERT INTO llm_providers (name, provider, model_name, api_key_encrypted, api_base_url, temperature, max_tokens, timeout_seconds, role, is_active, created_at, updated_at)
            VALUES (:name, :provider, :model, :key, :base_url, :temp, :tokens, :timeout, :role, :active, NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => trim($data['name']),
            ':provider' => strtolower(trim($data['provider'])),
            ':model' => trim($data['model_name']),
            ':key' => $encryptedKey,
            ':base_url' => !empty($data['api_base_url']) ? trim($data['api_base_url']) : null,
            ':temp' => isset($data['temperature']) ? (float)$data['temperature'] : 0.30,
            ':tokens' => isset($data['max_tokens']) ? (int)$data['max_tokens'] : 1500,
            ':timeout' => isset($data['timeout_seconds']) ? (int)$data['timeout_seconds'] : 30,
            ':role' => $role,
            ':active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1
        ]);
        $id = (int)$db->lastInsertId();

        AuditLogger::log('llm_provider_created', 'llm_provider', $id, ['name' => $data['name'], 'role' => $role]);

        Response::success(['id' => $id], 'LLM Provider created successfully', 201);
    }

    /**
     * PUT /v1/superadmin/llm-providers/{id} — Update LLM Provider
     */
    public function updateLlmProvider(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'provider' => 'required',
            'model_name' => 'required'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed: ' . implode(', ', array_map(fn($e) => implode(', ', $e), $errors)), 422, $errors);
        }

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM llm_providers WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            Response::error('LLM Provider not found.', 404);
        }

        $role = in_array($data['role'] ?? '', ['primary', 'fallback', 'embedding', 'inactive']) ? $data['role'] : 'inactive';

        if ($role !== 'inactive') {
            $stmtUnset = $db->prepare("UPDATE llm_providers SET role = 'inactive' WHERE role = :role AND id != :id");
            $stmtUnset->execute([':role' => $role, ':id' => $id]);
        }

        $sql = "UPDATE llm_providers SET name = :name, provider = :provider, model_name = :model, api_base_url = :base_url, temperature = :temp, max_tokens = :tokens, timeout_seconds = :timeout, role = :role, is_active = :active, updated_at = NOW()";
        $binds = [
            ':name' => trim($data['name']),
            ':provider' => strtolower(trim($data['provider'])),
            ':model' => trim($data['model_name']),
            ':base_url' => !empty($data['api_base_url']) ? trim($data['api_base_url']) : null,
            ':temp' => isset($data['temperature']) ? (float)$data['temperature'] : 0.30,
            ':tokens' => isset($data['max_tokens']) ? (int)$data['max_tokens'] : 1500,
            ':timeout' => isset($data['timeout_seconds']) ? (int)$data['timeout_seconds'] : 30,
            ':role' => $role,
            ':active' => isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1,
            ':id' => $id
        ];

        if (!empty($data['api_key'])) {
            $sql .= ", api_key_encrypted = :key";
            $binds[':key'] = self::encryptKey(trim($data['api_key']));
        }

        $sql .= " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($binds);

        AuditLogger::log('llm_provider_updated', 'llm_provider', $id, ['name' => $data['name'], 'role' => $role]);

        Response::success(['id' => $id], 'LLM Provider updated successfully');
    }

    /**
     * DELETE /v1/superadmin/llm-providers/{id} — Delete LLM Provider
     */
    public function deleteLlmProvider(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM llm_providers WHERE id = :id");
        $stmt->execute([':id' => $id]);

        AuditLogger::log('llm_provider_deleted', 'llm_provider', $id);

        Response::success(['id' => $id], 'LLM Provider deleted successfully');
    }

    /**
     * POST /v1/superadmin/llm-providers/{id}/test — Test LLM Provider Connection
     */
    public function testLlmProvider(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM llm_providers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $provider = $stmt->fetch();

        if (!$provider) {
            Response::error('LLM Provider not found.', 404);
        }

        $startTime = microtime(true);
        try {
            $result = LlmService::testProviderDirect($provider);
            $latencyMs = round((microtime(true) - $startTime) * 1000);

            $stmtUpdate = $db->prepare("UPDATE llm_providers SET last_tested_at = NOW(), last_test_result = 'ok' WHERE id = :id");
            $stmtUpdate->execute([':id' => $id]);

            Response::success([
                'result' => 'ok',
                'latency_ms' => $latencyMs,
                'response_snippet' => substr($result['text'] ?? 'OK', 0, 100)
            ], "Connection verified successfully in {$latencyMs}ms");
        } catch (Throwable $e) {
            $latencyMs = round((microtime(true) - $startTime) * 1000);
            $stmtUpdate = $db->prepare("UPDATE llm_providers SET last_tested_at = NOW(), last_test_result = 'error' WHERE id = :id");
            $stmtUpdate->execute([':id' => $id]);

            Response::error("Connection test failed: " . $e->getMessage(), 422, ['latency_ms' => $latencyMs]);
        }
    }

    /**
     * GET /v1/superadmin/organizations — List all college organizations & usage
     */
    public function getOrganizations(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT o.*, p.name as plan_name,
                   (SELECT COUNT(*) FROM users WHERE organization_id = o.id) as total_users,
                   (SELECT COUNT(*) FROM chatbots WHERE organization_id = o.id) as total_chatbots,
                   (SELECT COUNT(*) FROM knowledge_sources WHERE organization_id = o.id) as total_knowledge_sources,
                   (SELECT COUNT(*) FROM leads WHERE organization_id = o.id) as total_leads
            FROM organizations o
            LEFT JOIN plans p ON o.plan_id = p.id
            ORDER BY o.id DESC
        ");
        $orgs = $stmt->fetchAll();

        Response::success($orgs);
    }

    /**
     * PUT /v1/superadmin/organizations/{id}/status — Update college subscription status
     */
    public function updateOrgStatus(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        $status = in_array($request->get('subscription_status'), ['active', 'inactive', 'trial', 'cancelled']) ? $request->get('subscription_status') : 'active';

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE organizations SET subscription_status = :status, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);

        AuditLogger::log('org_status_updated', 'organization', $id, ['status' => $status]);

        Response::success(['id' => $id, 'subscription_status' => $status], 'Organization status updated');
    }

    /**
     * DELETE /v1/superadmin/organizations/{id} — Delete college organization and all associated resources
     */
    public function deleteOrganization(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid organization ID.', 400);
        }

        $db = Database::getConnection();

        // 1. Fetch organization details
        $stmt = $db->prepare("SELECT id, name, slug FROM organizations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $org = $stmt->fetch();

        if (!$org) {
            Response::error('Organization not found.', 404);
        }

        try {
            $db->beginTransaction();

            // 2. Collect and delete knowledge files from disk
            $stmtFiles = $db->prepare("SELECT file_path FROM knowledge_sources WHERE organization_id = :id AND file_path IS NOT NULL");
            $stmtFiles->execute([':id' => $id]);
            $files = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);
            foreach ($files as $filePath) {
                if (!empty($filePath) && file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            // 3. Collect and delete lead assets files from disk
            $stmtAssets = $db->prepare("SELECT file_path FROM lead_assets WHERE organization_id = :id AND file_path IS NOT NULL");
            $stmtAssets->execute([':id' => $id]);
            $assetFiles = $stmtAssets->fetchAll(PDO::FETCH_COLUMN);
            foreach ($assetFiles as $assetPath) {
                if (!empty($assetPath) && file_exists($assetPath)) {
                    @unlink($assetPath);
                }
            }

            // 4. Delete avatar directory for this org if exists
            $avatarDir = dirname(__DIR__, 2) . "/storage/uploads/avatars/{$id}";
            if (is_dir($avatarDir)) {
                $avatarFiles = glob($avatarDir . '/*');
                if ($avatarFiles) {
                    foreach ($avatarFiles as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }
                @rmdir($avatarDir);
            }

            // 5. Delete any remaining doc files prefixed with doc_{id}_
            $uploadsDir = dirname(__DIR__, 2) . "/storage/uploads";
            if (is_dir($uploadsDir)) {
                $docFiles = glob($uploadsDir . "/doc_{$id}_*");
                if ($docFiles) {
                    foreach ($docFiles as $f) {
                        if (is_file($f)) @unlink($f);
                    }
                }
            }

            // 6. Explicitly clean up non-FK constrained tables
            $stmtCustom = $db->prepare("DELETE FROM widget_customizations WHERE organization_id = :id");
            $stmtCustom->execute([':id' => $id]);

            // 7. Delete organization (Cascades to all foreign key tables: users, chatbots, leads, conversations, etc.)
            $stmtDelete = $db->prepare("DELETE FROM organizations WHERE id = :id");
            $stmtDelete->execute([':id' => $id]);

            $db->commit();

            AuditLogger::log('organization_deleted', 'organization', $id, [
                'name' => $org['name'],
                'slug' => $org['slug']
            ]);

            Response::success([
                'id' => $id,
                'name' => $org['name']
            ], "College organization '{$org['name']}' and all associated resources deleted successfully.");

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error("Failed to delete organization: " . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/superadmin/plans — List all plans with decoupled India (Paise) and USD (Cents) pricing, quotas, and features
     */
    public function getPlans(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT * FROM plans ORDER BY sort_order ASC, id ASC");
        $plans = $stmt->fetchAll();

        foreach ($plans as &$plan) {
            $stmtQ = $db->prepare("SELECT id, quota_key, quota_label, quota_value, quota_period FROM plan_quotas WHERE plan_id = :pid ORDER BY id ASC");
            $stmtQ->execute([':pid' => $plan['id']]);
            $plan['quotas'] = $stmtQ->fetchAll();

            $stmtF = $db->prepare("SELECT id, feature_key, feature_label, is_enabled FROM plan_features WHERE plan_id = :pid ORDER BY id ASC");
            $stmtF->execute([':pid' => $plan['id']]);
            $plan['features'] = $stmtF->fetchAll();
        }

        Response::success($plans);
    }

    /**
     * PUT /v1/superadmin/plans/{id} — Update plan info, decoupled India & USD pricing, quotas, and features
     */
    public function updatePlan(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid plan ID.', 400);
        }

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM plans WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        if (!$stmtCheck->fetch()) {
            Response::error('Plan not found.', 404);
        }

        $data = $request->all();

        try {
            $db->beginTransaction();

            $name = trim($data['name'] ?? '');
            $description = trim($data['description'] ?? '');
            $priceMonthlyPaise = isset($data['price_monthly_paise']) ? (int)$data['price_monthly_paise'] : 0;
            $priceYearlyPaise = isset($data['price_yearly_paise']) ? (int)$data['price_yearly_paise'] : 0;
            $priceMonthlyUsdCents = isset($data['price_monthly_usd_cents']) ? (int)$data['price_monthly_usd_cents'] : 0;
            $priceYearlyUsdCents = isset($data['price_yearly_usd_cents']) ? (int)$data['price_yearly_usd_cents'] : 0;
            $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
            $isDefault = isset($data['is_default']) ? (int)(bool)$data['is_default'] : 0;
            $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

            if ($isDefault === 1) {
                $stmtUnsetDefault = $db->prepare("UPDATE plans SET is_default = 0 WHERE id != :id");
                $stmtUnsetDefault->execute([':id' => $id]);
            }

            $stmtUpdate = $db->prepare("
                UPDATE plans SET 
                    name = :name,
                    description = :desc,
                    price_monthly_paise = :pm_inr,
                    price_yearly_paise = :py_inr,
                    price_monthly_usd_cents = :pm_usd,
                    price_yearly_usd_cents = :py_usd,
                    is_active = :active,
                    is_default = :def,
                    sort_order = :sort,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':name' => $name,
                ':desc' => $description,
                ':pm_inr' => $priceMonthlyPaise,
                ':py_inr' => $priceYearlyPaise,
                ':pm_usd' => $priceMonthlyUsdCents,
                ':py_usd' => $priceYearlyUsdCents,
                ':active' => $isActive,
                ':def' => $isDefault,
                ':sort' => $sortOrder,
                ':id' => $id
            ]);

            // Sync Quotas if provided
            if (isset($data['quotas']) && is_array($data['quotas'])) {
                foreach ($data['quotas'] as $qKey => $qVal) {
                    $qValue = (int)$qVal;
                    $stmtCheckQ = $db->prepare("SELECT id FROM plan_quotas WHERE plan_id = :pid AND quota_key = :key");
                    $stmtCheckQ->execute([':pid' => $id, ':key' => $qKey]);
                    if ($stmtCheckQ->fetch()) {
                        $stmtUpdateQ = $db->prepare("UPDATE plan_quotas SET quota_value = :val, updated_at = NOW() WHERE plan_id = :pid AND quota_key = :key");
                        $stmtUpdateQ->execute([':val' => $qValue, ':pid' => $id, ':key' => $qKey]);
                    } else {
                        $label = ucwords(str_replace('_', ' ', $qKey));
                        $stmtInsertQ = $db->prepare("INSERT INTO plan_quotas (plan_id, quota_key, quota_label, quota_value) VALUES (:pid, :key, :label, :val)");
                        $stmtInsertQ->execute([':pid' => $id, ':key' => $qKey, ':label' => $label, ':val' => $qValue]);
                    }
                }
            }

            // Sync Features if provided
            if (isset($data['features']) && is_array($data['features'])) {
                foreach ($data['features'] as $fKey => $fVal) {
                    $fEnabled = (int)(bool)$fVal;
                    $stmtCheckF = $db->prepare("SELECT id FROM plan_features WHERE plan_id = :pid AND feature_key = :key");
                    $stmtCheckF->execute([':pid' => $id, ':key' => $fKey]);
                    if ($stmtCheckF->fetch()) {
                        $stmtUpdateF = $db->prepare("UPDATE plan_features SET is_enabled = :enabled, updated_at = NOW() WHERE plan_id = :pid AND feature_key = :key");
                        $stmtUpdateF->execute([':enabled' => $fEnabled, ':pid' => $id, ':key' => $fKey]);
                    } else {
                        $label = ucwords(str_replace('_', ' ', $fKey));
                        $stmtInsertF = $db->prepare("INSERT INTO plan_features (plan_id, feature_key, feature_label, is_enabled) VALUES (:pid, :key, :label, :enabled)");
                        $stmtInsertF->execute([':pid' => $id, ':key' => $fKey, ':label' => $label, ':enabled' => $fEnabled]);
                    }
                }
            }

            $db->commit();

            AuditLogger::log('plan_updated', 'plan', $id, ['name' => $name, 'price_inr' => $priceMonthlyPaise, 'price_usd' => $priceMonthlyUsdCents]);

            Response::success(['id' => $id], 'Plan updated successfully');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to update plan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/superadmin/data-governance — Platform Data Inventory, Multi-Tenant Audits & Telemetry
     */
    public function getDataGovernance(Request $request, array $params = []): void
    {
        $db = Database::getConnection();

        // 1. Compute Platform-Wide Totals
        $stats = [
            'total_organizations' => (int)($db->query("SELECT COUNT(*) FROM organizations")->fetchColumn() ?: 0),
            'total_leads' => (int)($db->query("SELECT COUNT(*) FROM leads")->fetchColumn() ?: 0),
            'total_callbacks' => (int)($db->query("SELECT COUNT(*) FROM counselor_callbacks")->fetchColumn() ?: 0),
            'total_campus_tours' => (int)($db->query("SELECT COUNT(*) FROM campus_tour_bookings")->fetchColumn() ?: 0),
            'total_knowledge_sources' => (int)($db->query("SELECT COUNT(*) FROM knowledge_sources")->fetchColumn() ?: 0),
            'total_conversations' => (int)($db->query("SELECT COUNT(*) FROM conversations")->fetchColumn() ?: 0),
            'total_messages' => (int)($db->query("SELECT COUNT(*) FROM messages")->fetchColumn() ?: 0),
            'total_users' => (int)($db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0),
        ];

        // 2. Cross-Tenant Data Volume & Retention Breakdown
        $stmtTenants = $db->query("
            SELECT o.id, o.name, o.slug, o.subscription_status, o.created_at,
                   p.name as plan_name,
                   (SELECT COUNT(*) FROM leads WHERE organization_id = o.id) as leads_count,
                   (SELECT COUNT(*) FROM counselor_callbacks WHERE organization_id = o.id) as callbacks_count,
                   (SELECT COUNT(*) FROM campus_tour_bookings WHERE organization_id = o.id) as tours_count,
                   (SELECT COUNT(*) FROM knowledge_sources WHERE organization_id = o.id) as knowledge_count,
                   (SELECT COUNT(*) FROM conversations WHERE organization_id = o.id) as conversations_count,
                   (SELECT MAX(created_at) FROM leads WHERE organization_id = o.id) as last_lead_at
            FROM organizations o
            LEFT JOIN plans p ON o.plan_id = p.id
            ORDER BY leads_count DESC, o.id DESC
        ");
        $tenantAudits = $stmtTenants->fetchAll();

        // 3. Platform Data Inventory Taxonomy & Regulatory Mapping
        $taxonomy = [
            [
                'entity' => 'Prospective Student Leads',
                'table' => 'leads',
                'classification' => 'PII / Student Contact',
                'fields' => 'name, email, phone, program_interest, academic_score, scholarship_tier',
                'encryption' => 'TLS 1.3 In-Transit / Encrypted Backups',
                'purpose' => 'Admissions inquiry fulfillment & counselor routing',
                'legal_basis' => 'Express Consent & Legitimate Interest (FERPA / UK GDPR Art. 6(1)(a) / PIPEDA / CCPA)'
            ],
            [
                'entity' => 'Counselor Callbacks',
                'table' => 'counselor_callbacks',
                'classification' => 'PII / Scheduling',
                'fields' => 'student_name, student_phone, preferred_time_slot, topic_or_query, counselor_notes',
                'encryption' => 'TLS 1.3 In-Transit / Database Isolation',
                'purpose' => 'Direct phone consultation between student and admissions staff',
                'legal_basis' => 'Explicit User Request & Prior Consent'
            ],
            [
                'entity' => 'Campus Tour Bookings',
                'table' => 'campus_tour_bookings',
                'classification' => 'PII / Appointment',
                'fields' => 'visitor_name, email, phone, tour_date, time_slot, attendees_count',
                'encryption' => 'TLS 1.3 In-Transit / Database Isolation',
                'purpose' => 'On-campus visit logistics & security visitor rosters',
                'legal_basis' => 'Explicit User Request (FERPA / PIPEDA / GDPR)'
            ],
            [
                'entity' => 'Chat Conversations & Transcripts',
                'table' => 'conversations, messages',
                'classification' => 'Conversational Telemetry',
                'fields' => 'session_id, visitor_id, role, content, intent_detected, tokens_used',
                'encryption' => 'TLS 1.3 In-Transit / AES-256 System Keys',
                'purpose' => 'Multi-turn context memory & answering student inquiries',
                'legal_basis' => 'Zero Public LLM Training Guarantee'
            ],
            [
                'entity' => 'Institutional Knowledge Vault',
                'table' => 'knowledge_sources',
                'classification' => 'Institutional Proprietary IP',
                'fields' => 'title, raw_content, keywords, academic_version, effective_dates',
                'encryption' => 'TLS 1.3 / Strict Row-Level Tenancy Scoping',
                'purpose' => 'Authoritative Grounding for deterministic AI responses',
                'legal_basis' => 'Institutional Contractual Property (DPA Protected)'
            ],
            [
                'entity' => 'Proactive Conversion Telemetry',
                'table' => 'platform_config, audit_logs',
                'classification' => 'Behavioral / Technical',
                'fields' => 'dwell_time, exit_intent_trigger, utm_source, utm_campaign, scroll_depth',
                'encryption' => 'Anonymized / Cookie Consent Bound',
                'purpose' => 'Measuring admissions funnel efficiency & marketing attribution',
                'legal_basis' => 'Prior Opt-In (UK/EU) & Opt-Out Notice (US CCPA)'
            ]
        ];

        // 4. Consent Compliance Telemetry Summary (Simulated Aggregation for UI)
        $consentTelemetry = [
            'total_evaluations' => 14820,
            'opt_in_all_pct' => 74.2,
            'essential_only_pct' => 18.5,
            'custom_preferences_pct' => 7.3,
            'gpc_signals_honored' => 412,
            'jurisdictions' => [
                ['region' => 'United States (CCPA/CPRA/FERPA)', 'share_pct' => 52.4, 'status' => 'Compliant (Notice & Opt-Out)'],
                ['region' => 'United Kingdom (UK GDPR & PECR)', 'share_pct' => 24.1, 'status' => 'Compliant (Prior Opt-In)'],
                ['region' => 'Canada (PIPEDA & CPPA)', 'share_pct' => 14.8, 'status' => 'Compliant (Express Consent)'],
                ['region' => 'Other International', 'share_pct' => 8.7, 'status' => 'Compliant (Global DPA)']
            ]
        ];

        Response::success([
            'stats' => $stats,
            'taxonomy' => $taxonomy,
            'tenant_audits' => $tenantAudits,
            'consent_telemetry' => $consentTelemetry,
            'timestamp' => date('c')
        ]);
    }

    /**
     * GET /v1/superadmin/demos — List created personalized demos with stats and filters
     */
    public function listDemos(Request $request): void
    {
        $db = Database::getConnection();

        $page = max(1, (int)$request->get('page', 1));
        $limit = max(5, min(100, (int)$request->get('limit', 20)));
        $offset = ($page - 1) * $limit;

        $search = trim((string)$request->get('search', ''));
        $stageFilter = trim((string)$request->get('stage', ''));
        $scrapeFilter = trim((string)$request->get('scrape_status', ''));

        $where = ["1=1"];
        $params = [];

        if (!empty($search)) {
            $where[] = "(domain LIKE :s1 OR website_url LIKE :s2 OR institution_name LIKE :s3 OR work_email LIKE :s4 OR contact_name LIKE :s5 OR contact_phone LIKE :s6)";
            $term = "%{$search}%";
            $params[':s1'] = $term;
            $params[':s2'] = $term;
            $params[':s3'] = $term;
            $params[':s4'] = $term;
            $params[':s5'] = $term;
            $params[':s6'] = $term;
        }

        if (!empty($stageFilter)) {
            $where[] = "lead_stage = :stage";
            $params[':stage'] = $stageFilter;
        }

        if (!empty($scrapeFilter)) {
            $where[] = "scrape_status = :scrape";
            $params[':scrape'] = $scrapeFilter;
        }

        $whereClause = implode(" AND ", $where);

        // Compute aggregate metrics
        $statsStmt = $db->query("
            SELECT 
                COUNT(*) as total_demos,
                SUM(IF(work_email IS NOT NULL AND work_email != '', 1, 0)) as emails_captured,
                SUM(IF(contact_phone IS NOT NULL AND contact_phone != '', 1, 0)) as counselor_requests,
                SUM(IF(scrape_status = 'failed', 1, 0)) as scrape_failures,
                SUM(IF(lead_stage IN ('completed', 'counselor_requested'), 1, 0)) as completed_demos
            FROM demo_previews
        ");
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_demos' => 0, 'emails_captured' => 0, 'counselor_requests' => 0, 'scrape_failures' => 0, 'completed_demos' => 0
        ];
        $totalDemos = (int)($stats['total_demos'] ?? 0);
        $completedDemos = (int)($stats['completed_demos'] ?? 0);
        $completionRate = $totalDemos > 0 ? round(($completedDemos / $totalDemos) * 100, 1) : 0;
        $stats['completion_rate'] = $completionRate;

        // Get total count matching current query
        $countStmt = $db->prepare("SELECT COUNT(*) FROM demo_previews WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        // Fetch paginated rows with calculated duration in seconds
        $sql = "
            SELECT 
                id, session_token, website_url, domain, institution_name,
                scrape_status, scrape_error_reason, pages_found, programs_found,
                programs_list, has_admissions, has_fees, has_scholarships,
                work_email, email_captured_at, contact_name, contact_phone,
                counselor_req_at, chat_message_count, lead_stage,
                TIMESTAMPDIFF(SECOND, demo_started_at, COALESCE(demo_ended_at, updated_at)) AS duration_seconds,
                demo_started_at, demo_ended_at, created_at
            FROM demo_previews
            WHERE {$whereClause}
            ORDER BY id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $dataStmt = $db->prepare($sql);
        $dataStmt->execute($params);
        $demos = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'demos'        => $demos,
            'stats'        => $stats,
            'pagination'   => [
                'page'         => $page,
                'limit'        => $limit,
                'total_rows'   => $totalRows,
                'total_pages'  => ceil($totalRows / $limit)
            ]
        ]);
    }

    /**
     * GET /v1/superadmin/demos/{id}/conversation — Retrieve full conversation log
     */
    public function getDemoConversation(Request $request, array $params = []): void
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error("Invalid demo ID.", 400);
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id, session_token, domain, institution_name, website_url,
                   work_email, contact_name, contact_phone, lead_stage,
                   conversation_log, chat_message_count, created_at
            FROM demo_previews
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $demo = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$demo) {
            Response::error("Demo session not found.", 404);
            return;
        }

        $messages = [];
        if (!empty($demo['conversation_log'])) {
            $decoded = json_decode($demo['conversation_log'], true);
            if (is_array($decoded)) {
                $messages = $decoded;
            }
        }

        Response::success([
            'demo'     => $demo,
            'messages' => $messages
        ]);
    }

    /**
     * GET /v1/superadmin/demos/export — Export all demos as CSV download
     */
    public function exportDemosCsv(Request $request): void
    {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT 
                id, session_token, website_url, domain, institution_name,
                scrape_status, scrape_error_reason, pages_found, programs_found,
                programs_list, work_email, contact_name, contact_phone,
                lead_stage, chat_message_count,
                TIMESTAMPDIFF(SECOND, demo_started_at, COALESCE(demo_ended_at, updated_at)) AS duration_seconds,
                demo_started_at, email_captured_at, counselor_req_at, created_at
            FROM demo_previews
            ORDER BY id DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="edvora_demos_' . date('Y-m-d_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'ID', 'Session Token', 'Website URL', 'Domain', 'Institution Name',
            'Scrape Status', 'Scrape Error', 'Pages Found', 'Programs Found', 'Programs List',
            'Work Email (Lead #1)', 'Contact Name (Lead #2)', 'Contact Phone (Lead #2)',
            'Stage', 'Messages Count', 'Duration (Seconds)', 'Demo Started At',
            'Email Captured At', 'Counselor Requested At', 'Created At'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'],
                $r['session_token'],
                $r['website_url'],
                $r['domain'],
                $r['institution_name'],
                $r['scrape_status'],
                $r['scrape_error_reason'],
                $r['pages_found'],
                $r['programs_found'],
                $r['programs_list'],
                $r['work_email'],
                $r['contact_name'],
                $r['contact_phone'],
                $r['lead_stage'],
                $r['chat_message_count'],
                $r['duration_seconds'],
                $r['demo_started_at'],
                $r['email_captured_at'],
                $r['counselor_req_at'],
                $r['created_at']
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * GET /v1/superadmin/smtp — Retrieve current SMTP configuration
     */
    public function getSmtpSettings(Request $request, array $params = []): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT value_text, updated_at FROM platform_config WHERE key_name = 'smtp_settings' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();

        $defaults = [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'has_password' => false,
            'from_email' => 'no-reply@edvora.chat',
            'from_name' => 'edvora.chat Admissions Alert',
            'reply_to' => '',
            'is_active' => 0,
            'last_tested_at' => null,
            'last_test_result' => null,
            'updated_at' => null
        ];

        if (!$row || empty($row['value_text'])) {
            Response::success($defaults);
            return;
        }

        $settings = json_decode($row['value_text'], true);
        if (!is_array($settings)) {
            Response::success($defaults);
            return;
        }

        $response = [
            'host' => (string)($settings['host'] ?? ''),
            'port' => (int)($settings['port'] ?? 587),
            'encryption' => (string)($settings['encryption'] ?? 'tls'),
            'username' => (string)($settings['username'] ?? ''),
            'has_password' => !empty($settings['password_encrypted']),
            'from_email' => (string)($settings['from_email'] ?? 'no-reply@edvora.chat'),
            'from_name' => (string)($settings['from_name'] ?? 'edvora.chat Admissions Alert'),
            'reply_to' => (string)($settings['reply_to'] ?? ''),
            'is_active' => (int)($settings['is_active'] ?? 0),
            'last_tested_at' => $settings['last_tested_at'] ?? null,
            'last_test_result' => $settings['last_test_result'] ?? null,
            'updated_at' => $row['updated_at'] ?? null
        ];

        Response::success($response);
    }

    /**
     * PUT /v1/superadmin/smtp — Save or update SMTP configuration
     */
    public function updateSmtpSettings(Request $request, array $params = []): void
    {
        $data = $request->all();

        $errors = Validator::validate($data, [
            'host' => 'required|max:255',
            'port' => 'required',
            'encryption' => 'required',
            'from_email' => 'required|email|max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed: ' . implode(', ', array_map(fn($e) => implode(', ', $e), $errors)), 422, $errors);
            return;
        }

        $db = Database::getConnection();
        $stmtExisting = $db->prepare("SELECT value_text FROM platform_config WHERE key_name = 'smtp_settings' LIMIT 1");
        $stmtExisting->execute();
        $existingRow = $stmtExisting->fetch();
        $existingSettings = ($existingRow && !empty($existingRow['value_text'])) ? json_decode($existingRow['value_text'], true) : [];

        // Encrypt password if new one provided; otherwise retain existing
        $passwordEncrypted = $existingSettings['password_encrypted'] ?? '';
        if (isset($data['password']) && trim((string)$data['password']) !== '') {
            $passwordEncrypted = EmailService::encryptKey(trim((string)$data['password']));
        }

        $newSettings = [
            'host' => trim((string)$data['host']),
            'port' => max(1, (int)($data['port'] ?? 587)),
            'encryption' => strtolower(trim((string)$data['encryption'])),
            'username' => trim((string)($data['username'] ?? '')),
            'password_encrypted' => $passwordEncrypted,
            'from_email' => trim((string)$data['from_email']),
            'from_name' => trim((string)($data['from_name'] ?? 'edvora.chat Admissions Alert')),
            'reply_to' => trim((string)($data['reply_to'] ?? '')),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'last_tested_at' => $existingSettings['last_tested_at'] ?? null,
            'last_test_result' => $existingSettings['last_test_result'] ?? null
        ];

        $json = json_encode($newSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmtSave = $db->prepare("
            INSERT INTO platform_config (key_name, value_text, updated_at)
            VALUES ('smtp_settings', :json, NOW())
            ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = NOW()
        ");
        $stmtSave->execute([':json' => $json]);

        AuditLogger::log('smtp_settings_updated', 'platform_config', null, [
            'host' => $newSettings['host'],
            'port' => $newSettings['port'],
            'encryption' => $newSettings['encryption'],
            'is_active' => $newSettings['is_active']
        ]);

        Response::success([
            'host' => $newSettings['host'],
            'port' => $newSettings['port'],
            'encryption' => $newSettings['encryption'],
            'username' => $newSettings['username'],
            'has_password' => !empty($newSettings['password_encrypted']),
            'from_email' => $newSettings['from_email'],
            'from_name' => $newSettings['from_name'],
            'reply_to' => $newSettings['reply_to'],
            'is_active' => $newSettings['is_active']
        ], 'SMTP configuration saved successfully');
    }

    /**
     * POST /v1/superadmin/smtp/test — Test SMTP connection & send live diagnostic message
     */
    public function testSmtpConnection(Request $request, array $params = []): void
    {
        $data = $request->all();
        $targetEmail = trim((string)($data['to_email'] ?? $data['test_email'] ?? ''));

        if (empty($targetEmail) || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) {
            Response::error('Please provide a valid recipient email address for testing.', 422);
            return;
        }

        $db = Database::getConnection();
        $stmtExisting = $db->prepare("SELECT value_text FROM platform_config WHERE key_name = 'smtp_settings' LIMIT 1");
        $stmtExisting->execute();
        $existingRow = $stmtExisting->fetch();
        $existingSettings = ($existingRow && !empty($existingRow['value_text'])) ? json_decode($existingRow['value_text'], true) : [];

        // Allow testing draft unsaved settings from form or fall back to saved
        $configToTest = [
            'host' => !empty($data['host']) ? trim((string)$data['host']) : ($existingSettings['host'] ?? ''),
            'port' => !empty($data['port']) ? (int)$data['port'] : (int)($existingSettings['port'] ?? 587),
            'encryption' => !empty($data['encryption']) ? strtolower(trim((string)$data['encryption'])) : ($existingSettings['encryption'] ?? 'tls'),
            'username' => isset($data['username']) ? trim((string)$data['username']) : ($existingSettings['username'] ?? ''),
            'password' => !empty($data['password']) ? trim((string)$data['password']) : '',
            'password_encrypted' => $existingSettings['password_encrypted'] ?? '',
            'from_email' => !empty($data['from_email']) ? trim((string)$data['from_email']) : ($existingSettings['from_email'] ?? 'no-reply@edvora.chat'),
            'from_name' => !empty($data['from_name']) ? trim((string)$data['from_name']) : ($existingSettings['from_name'] ?? 'edvora.chat System'),
            'reply_to' => !empty($data['reply_to']) ? trim((string)$data['reply_to']) : ($existingSettings['reply_to'] ?? '')
        ];

        if (empty($configToTest['host'])) {
            Response::error('SMTP Host is required to run test.', 422);
            return;
        }

        $testResult = EmailService::sendTestMail($targetEmail, $configToTest);

        // Update last_tested_at and last_test_result in saved settings if existing
        if (!empty($existingSettings)) {
            $existingSettings['last_tested_at'] = date('Y-m-d H:i:s');
            $existingSettings['last_test_result'] = $testResult['success'] ? 'success' : 'failed';
            $stmtUpdate = $db->prepare("UPDATE platform_config SET value_text = :json WHERE key_name = 'smtp_settings'");
            $stmtUpdate->execute([':json' => json_encode($existingSettings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }

        AuditLogger::log('smtp_test_sent', 'platform_config', null, [
            'target_email' => $targetEmail,
            'success' => $testResult['success'],
            'host' => $configToTest['host']
        ]);

        if ($testResult['success']) {
            Response::success($testResult, $testResult['message']);
        } else {
            Response::error($testResult['message'], 400, $testResult);
        }
    }

    /**
     * Encrypt API key using AES-256-CBC
     */
    private static function encryptKey(string $key): string
    {
        $secret = Env::get('LLM_ENCRYPTION_KEY', 'EdvoraLLM_SecretEncryptionKey2026!');
        $ivLen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivLen);
        $encrypted = openssl_encrypt($key, 'AES-256-CBC', md5($secret), 0, $iv);
        return bin2hex($iv . $encrypted);
    }

    /**
     * GET /v1/superadmin/llm-debug-logs — Return past 1 LLM interaction
     */
    public function getLlmDebugLogs(Request $request, array $params = []): void
    {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/llm_debug_logs.json';
        $logs = [];

        if (file_exists($logFile)) {
            $raw = @file_get_contents($logFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $logs = $decoded;
                }
            }
        }

        // Strictly enforce 1 entry (latest only)
        if (count($logs) > 1) {
            $logs = array_slice($logs, 0, 1);
        }

        Response::success([
            'logs' => $logs,
            'count' => count($logs),
            'server_time' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * DELETE /v1/superadmin/llm-debug-logs — Clear debug logs
     */
    public function clearLlmDebugLogs(Request $request, array $params = []): void
    {
        $logFile = dirname(__DIR__, 2) . '/storage/logs/llm_debug_logs.json';
        if (file_exists($logFile)) {
            @file_put_contents($logFile, json_encode([]));
        }

        Response::success([], 'LLM debug logs cleared successfully');
    }
}


