<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class InformationalPromptBuilder
{
    /**
     * Build the system prompt for 'informational' chat_mode (Edvora.chat SaaS Product Assistant).
     * Automatically injects real-time Plans, Pricing, Quotas, and Features from the `plans` table,
     * plus relevant knowledge base chunks from the system organization.
     */
    public static function build(
        PDO     $db,
        int     $organizationId,
        array   $contextSources,
        ?string $botOverride = null
    ): string {
        $basePrompt = self::getBaseSystemPrompt($db);
        if (!empty($botOverride) && trim($botOverride) !== '') {
            $basePrompt .= "\n\nADDITIONAL CUSTOM INSTRUCTIONS:\n" . trim($botOverride);
        }

        // 1. Live Plans & Pricing Context from `plans`, `plan_quotas`, and `plan_features` tables
        $plansBlock = self::buildLivePlansContext($db);

        // 2. Retrieved Knowledge Context from Edvora's crawled pages & documents
        $knowledgeBlock = self::buildKnowledgeContext($contextSources);

        $prompt = $basePrompt
            . "\n\n" . $plansBlock
            . "\n\n" . $knowledgeBlock
            . "\n\n" . self::getOutputInstructions();

        return $prompt;
    }

    /**
     * Load custom informational prompt from platform_config if set, otherwise default.
     */
    public static function getBaseSystemPrompt(PDO $db): string
    {
        try {
            $stmt = $db->prepare("SELECT value_text FROM platform_config WHERE key_name = 'system_bot_prompt' LIMIT 1");
            $stmt->execute();
            $custom = $stmt->fetchColumn();
            if (!empty($custom) && trim((string)$custom) !== '') {
                return trim((string)$custom);
            }
        } catch (Throwable $e) {}

        return self::getDefaultPrompt();
    }

    public static function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are the official AI Product Specialist for Edvora.chat — the purpose-built Multi-Tenant AI Admissions Chatbot & Conversion Platform designed specifically for Colleges, Universities, and Higher Education Institutions.

YOUR CORE MISSION:
1. Answer visitor questions clearly, accurately, and concisely about the Edvora.chat SaaS platform, its capabilities, architecture, setup process, security, and subscription plans.
2. Persuasively highlight how Edvora.chat helps higher-education institutions increase student enrollment, automate 24/7 admissions counseling, capture high-intent student leads, schedule campus tours, evaluate scholarships in real-time, and track full-funnel marketing attribution.
3. Differentiate subscription plans accurately based on their exact monthly/annual pricing, quotas, and enabled features provided in the LIVE PLANS & PRICING section below.
4. Help prospective college administrators, marketing directors, and admissions teams understand which plan best fits their institution's size and goals.

STRICT GUARDRAILS:
- You are an informational product advisor for Edvora.chat SaaS. You are NOT a university admissions bot asking students for their GPA or degree interest.
- NEVER ask visitors for their personal contact details (name, email, phone number) and NEVER attempt to trigger lead capture forms, campus tour bookings, or brochure downloads.
- Quote exact prices, quotas, and features ONLY from the LIVE PLANS & PRICING table and KNOWLEDGE BASE CONTEXT below. Do not invent features or discounts that are not listed.
- Provide all pricing strictly and exclusively in US Dollars (USD / $). NEVER quote or mention Indian Rupee (INR / ₹) prices under any circumstances. Mention both monthly and yearly billing options (highlighting annual savings where applicable).
- Keep responses warm, consultative, well-structured, and easy to scan (2 to 5 sentences or clean bullet points when comparing plans or features).
PROMPT;
    }

    /**
     * Dynamically queries the `plans`, `plan_quotas`, and `plan_features` tables so any update
     * in SuperAdmin Plan Manager is immediately reflected in the chatbot's answers.
     */
    public static function buildLivePlansContext(PDO $db): string
    {
        try {
            $stmt = $db->query("
                SELECT id, name, description, badge_text,
                       price_monthly_usd_cents, price_yearly_usd_cents,
                       is_default, sort_order
                FROM plans
                WHERE is_active = 1
                ORDER BY sort_order ASC, id ASC
            ");
            $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($plans)) {
                return "=== LIVE PLANS & PRICING ===\nNo active plans found in database.";
            }

            $lines = ["=== AUTHORITATIVE LIVE PLANS & PRICING (AUTO-SYNCED FROM DATABASE) ==="];
            $lines[] = "Always use these exact figures when quoting prices or comparing plans. All prices are in USD ($):\n";

            foreach ($plans as $plan) {
                $pId = (int)$plan['id'];
                $name = $plan['name'];
                $badge = !empty($plan['badge_text']) ? " ({$plan['badge_text']})" : '';
                $desc = !empty($plan['description']) ? trim($plan['description']) : '';

                $usdMonthly = number_format(((int)$plan['price_monthly_usd_cents']) / 100, 0);
                $usdYearly  = number_format(((int)$plan['price_yearly_usd_cents']) / 100, 0);

                $lines[] = "PLAN: {$name}{$badge}";
                if ($desc !== '') {
                    $lines[] = "  Summary: {$desc}";
                }
                $lines[] = "  Pricing (USD): \${$usdMonthly}/month (or \${$usdYearly}/year billed annually)";

                // Fetch Quotas
                $stmtQ = $db->prepare("SELECT quota_key, quota_label, quota_value, quota_period FROM plan_quotas WHERE plan_id = :pid ORDER BY id ASC");
                $stmtQ->execute([':pid' => $pId]);
                $quotas = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($quotas)) {
                    $qParts = [];
                    foreach ($quotas as $q) {
                        $val = (int)$q['quota_value'];
                        $valStr = ($val === -1) ? 'Unlimited' : number_format($val);
                        if (str_contains($q['quota_key'], '_mb')) {
                            $valStr .= ' MB';
                        }
                        $period = !empty($q['quota_period']) ? " ({$q['quota_period']})" : '';
                        $qParts[] = "{$q['quota_label']}: {$valStr}{$period}";
                    }
                    $lines[] = "  Quotas & Limits: " . implode(' | ', $qParts);
                }

                // Fetch Features
                $stmtF = $db->prepare("SELECT feature_label, is_enabled FROM plan_features WHERE plan_id = :pid ORDER BY id ASC");
                $stmtF->execute([':pid' => $pId]);
                $features = $stmtF->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($features)) {
                    $enabled = [];
                    $disabled = [];
                    foreach ($features as $f) {
                        if ((int)$f['is_enabled'] === 1) {
                            $enabled[] = $f['feature_label'];
                        } else {
                            $disabled[] = $f['feature_label'];
                        }
                    }
                    if (!empty($enabled)) {
                        $lines[] = "  Included Features: " . implode(', ', $enabled);
                    }
                    if (!empty($disabled)) {
                        $lines[] = "  Not Included in {$name}: " . implode(', ', $disabled);
                    }
                }
                $lines[] = "";
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            error_log('[InformationalPromptBuilder] Error building live plans context: ' . $e->getMessage());
            return "=== LIVE PLANS & PRICING ===\n(Unable to load live plan details at this moment.)";
        }
    }

    private static function buildKnowledgeContext(array $contextSources): string
    {
        if (empty($contextSources)) {
            return "=== EDVORA.CHAT PRODUCT KNOWLEDGE BASE ===\nUse your general instructions and the live plans data above to assist the visitor.";
        }

        $blocks = ["=== EDVORA.CHAT PRODUCT KNOWLEDGE BASE (RETRIEVED CONTEXT) ==="];
        foreach ($contextSources as $idx => $src) {
            $num = $idx + 1;
            $title = $src['title'] ?? "Source #{$num}";
            $content = trim($src['processed_content'] ?? '');
            if ($content !== '') {
                $blocks[] = "--- [Source {$num}: {$title}] ---\n{$content}";
            }
        }

        return implode("\n\n", $blocks);
    }

    private static function getOutputInstructions(): string
    {
        return <<<'OUT'
=== RESPONSE FORMAT INSTRUCTIONS ===
Respond with a valid JSON object matching this exact structure:
{
  "response": "Your clear, helpful, well-formatted answer to the visitor.",
  "sentiment": "positive" | "neutral" | "negative",
  "emotion": "curious" | "interested" | "neutral" | "confused" | "frustrated",
  "frustration": 0.0,
  "conversation_trend": "improving" | "stable" | "declining",
  "intent_label": "pricing_inquiry" | "plan_comparison" | "feature_inquiry" | "technical_question" | "onboarding_setup" | "security_compliance" | "general_inquiry",
  "conversation_stage": "discovery" | "consideration" | "decision"
}
OUT;
    }

    /**
     * JSON Schema for OpenAI structured output in informational mode
     */
    public static function getResponseSchema(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name'   => 'informational_response',
                'strict' => true,
                'schema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'required'             => [
                        'response', 'sentiment', 'emotion', 'frustration',
                        'conversation_trend', 'intent_label', 'conversation_stage'
                    ],
                    'properties' => [
                        'response'           => ['type' => 'string'],
                        'sentiment'          => ['type' => 'string'],
                        'emotion'            => ['type' => 'string'],
                        'frustration'        => ['type' => 'number'],
                        'conversation_trend' => ['type' => 'string'],
                        'intent_label'       => ['type' => 'string'],
                        'conversation_stage' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }
}
