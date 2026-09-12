<?php

namespace App\Database;

use PDO;

class Seeders
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        // 1. Default Super Admin User
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = 'superadmin@edvora.chat'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $passHash = password_hash('SuperAdmin_Secure2026!', PASSWORD_BCRYPT, ['cost' => 12]);
            $this->db->exec("
                INSERT INTO users (organization_id, name, email, password_hash, role, email_verified_at)
                VALUES (NULL, 'Super Admin', 'superadmin@edvora.chat', '{$passHash}', 'superadmin', NOW())
            ");
        }

        // 2. Default Master Prompt
        $masterPrompt = <<<'EOT'
You are the seasoned, consultative AI Admissions Counselor for {{COLLEGE_NAME}}.
Your mission is to provide accurate, welcoming, and high-value guidance to prospective students and parents, while actively fulfilling their requests for campus visits, counselor callbacks, and official documents right inside this chat.

=== CRITICAL CAPABILITIES & ACTION FULFILLMENT ===
1. YOU HAVE ACTIVE INTEGRATIONS: You CAN and MUST arrange campus visits, schedule counselor callbacks, and email official brochures/fee PDFs directly in the chat.
2. NEVER DEFLECT: NEVER tell the visitor "I cannot arrange the visit directly", "Go to our website", or "Contact admissions yourself". Instead, warmly confirm that you are initiating the booking or document delivery for them right now.
3. STRUCTURED LEAD TRIGGERS:
When the visitor wants to visit campus, book a tour, request a call, or receive documents (or when [SESSION LEAD STATE] permits a proactive offer), append EXACTLY ONE trigger tag on the very last line of your response:
- `[LEAD_TRIGGER:campus_tour]` -> When the visitor asks to visit the campus, arrange a tour, or see facilities.
- `[LEAD_TRIGGER:counselor_callback]` -> When the visitor asks to speak to someone, request a call, or needs 1-on-1 counseling.
- `[LEAD_TRIGGER:asset_delivery]` -> When offering or sending a syllabus, brochure, fee structure PDF, or placement report.

=== 3-STEP CONSULTATIVE COUNSELOR FRAMEWORK ===
1. ANSWER FIRST: Answer the question factually, directly, and concisely using the KNOWLEDGE BASE CONTEXT.
2. ENRICH WITH VALUE: Proactively add 1 relevant high-value insight (e.g. merit scholarship slabs up to 40%, notable recruiters/packages, or upcoming application deadlines).
3. BRIDGE TO ACTION (High-Conversion Question Rule):
When proactively offering a campus tour, brochure/syllabus, or counselor callback as a bridge to action:
- NEVER end with a passive declarative statement (e.g. avoid "I can help arrange a tour for you" or "I can email you the details").
- ALWAYS conclude with an active, inviting question that makes it effortless for the visitor to reply with "Yes" or "Sure":
  * For Campus Tours: "Should I arrange a tour for you?" or "Would you like me to arrange a campus tour for you?"
  * For Syllabi / Brochures: "Should I email you the detailed syllabus and fee structure?"
  * For Counselor Consultations: "Should I arrange a quick callback with an admissions advisor for you?"

=== HANDLING VISITOR CONFIRMATIONS / AFFIRMATIVE RESPONSES ===
When the visitor replies affirmatively ("Yes", "Sure", "Yes please", "Please do", "Yeah", "Arrange it", "Book it", "Go ahead") to your question:
- Immediately confirm warmly and append the corresponding trigger tag on the very last line:
  * For Campus Tour: Confirm warmly and append `[LEAD_TRIGGER:campus_tour]`
  * For Counselor Callback: Confirm warmly and append `[LEAD_TRIGGER:counselor_callback]`
  * For Brochure / Syllabus: Confirm warmly and append `[LEAD_TRIGGER:asset_delivery]`

=== EXAMPLES OF HOW TO RESPOND ===
- Visitor: "can i visit your campus" / "please arrange visit"
  Response: "We would love to host you on campus! I can arrange your guided tour covering our academic blocks, advanced research labs, sports complex, and student hostels. Please select your preferred date and slot below so our visit coordinator can confirm your pass:
  [LEAD_TRIGGER:campus_tour]"

- Visitor: "can someone call me about fees?"
  Response: "Certainly! I will have our senior admissions counselor give you a call to discuss the fee breakdown, installment plans, and scholarship options.
  [LEAD_TRIGGER:counselor_callback]"

- Visitor: "send me the fee structure"
  Response: "I will be happy to send our official fee breakdown and scholarship matrix directly to your email.
  [LEAD_TRIGGER:asset_delivery]"

- Visitor: "yes" / "sure please" (after you asked "Should I arrange a tour for you?")
  Response: "I would be delighted to arrange that for you! Please pick your preferred date and time slot below so our visit team can confirm your reservation:
  [LEAD_TRIGGER:campus_tour]"

{{KNOWLEDGE_CONTEXT}}
EOT;

        $stmt = $this->db->prepare("SELECT id FROM platform_config WHERE key_name = 'master_prompt'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            $stmtInsert = $this->db->prepare("INSERT INTO platform_config (key_name, value_text) VALUES ('master_prompt', :val)");
            $stmtInsert->execute([':val' => $masterPrompt]);
        } else {
            $stmtUpdate = $this->db->prepare("UPDATE platform_config SET value_text = :val, updated_at = NOW() WHERE key_name = 'master_prompt'");
            $stmtUpdate->execute([':val' => $masterPrompt]);
        }

        // 3. Default Plans (Starter, Growth, Pro)
        $plans = [
            [
                'name' => 'Starter',
                'description' => 'Perfect for small institutes launching their first AI assistant',
                'price_monthly' => 299900,
                'price_yearly' => 2999000,
                'price_monthly_usd' => 4900,
                'price_yearly_usd' => 49000,
                'is_default' => 1,
                'sort_order' => 1,
                'quotas' => [
                    'max_chatbots' => 1,
                    'max_knowledge_sources' => 20,
                    'max_messages_per_month' => 2000,
                    'max_leads_per_month' => 100,
                    'max_file_upload_mb' => 5,
                    'max_staff_users' => 1,
                    'conversation_history_days' => 30
                ],
                'features' => [
                    'lead_capture' => 1,
                    'custom_branding' => 1,
                    'allowed_domains' => 1,
                    'csv_export' => 1,
                    'analytics_dashboard' => 1,
                    'url_auto_refresh' => 0,
                    'multiple_chatbots' => 0,
                    'whatsapp_integration' => 0,
                    'sla_guarantee' => 0,
                    'dedicated_manager' => 0,
                    'custom_integrations' => 0
                ]
            ],
            [
                'name' => 'Growth',
                'description' => 'Best for growing colleges needing multiple bots & higher limits',
                'price_monthly' => 699900,
                'price_yearly' => 6999000,
                'price_monthly_usd' => 11900,
                'price_yearly_usd' => 119000,
                'is_default' => 0,
                'sort_order' => 2,
                'quotas' => [
                    'max_chatbots' => 3,
                    'max_knowledge_sources' => 100,
                    'max_messages_per_month' => 10000,
                    'max_leads_per_month' => 500,
                    'max_file_upload_mb' => 15,
                    'max_staff_users' => 5,
                    'conversation_history_days' => 90
                ],
                'features' => [
                    'lead_capture' => 1,
                    'custom_branding' => 1,
                    'allowed_domains' => 1,
                    'csv_export' => 1,
                    'analytics_dashboard' => 1,
                    'url_auto_refresh' => 1,
                    'multiple_chatbots' => 1,
                    'whatsapp_integration' => 0,
                    'sla_guarantee' => 0,
                    'dedicated_manager' => 0,
                    'custom_integrations' => 0
                ]
            ],
            [
                'name' => 'Pro',
                'description' => 'For large universities requiring maximum capacity & custom limits',
                'price_monthly' => 1499900,
                'price_yearly' => 14999000,
                'price_monthly_usd' => 24900,
                'price_yearly_usd' => 249000,
                'is_default' => 0,
                'sort_order' => 3,
                'quotas' => [
                    'max_chatbots' => -1,
                    'max_knowledge_sources' => -1,
                    'max_messages_per_month' => 50000,
                    'max_leads_per_month' => -1,
                    'max_file_upload_mb' => 50,
                    'max_staff_users' => -1,
                    'conversation_history_days' => 365
                ],
                'features' => [
                    'lead_capture' => 1,
                    'custom_branding' => 1,
                    'allowed_domains' => 1,
                    'csv_export' => 1,
                    'analytics_dashboard' => 1,
                    'url_auto_refresh' => 1,
                    'multiple_chatbots' => 1,
                    'whatsapp_integration' => 1,
                    'sla_guarantee' => 1,
                    'dedicated_manager' => 1,
                    'custom_integrations' => 1
                ]
            ]
        ];

        foreach ($plans as $pData) {
            $stmt = $this->db->prepare("SELECT id, price_monthly_usd_cents, price_yearly_usd_cents FROM plans WHERE name = :name");
            $stmt->execute([':name' => $pData['name']]);
            $existingPlan = $stmt->fetch();

            if (!$existingPlan) {
                $stmtInsert = $this->db->prepare("
                    INSERT INTO plans (name, description, price_monthly_paise, price_yearly_paise, price_monthly_usd_cents, price_yearly_usd_cents, is_default, sort_order)
                    VALUES (:name, :desc, :pm, :py, :pm_usd, :py_usd, :def, :sort)
                ");
                $stmtInsert->execute([
                    ':name' => $pData['name'],
                    ':desc' => $pData['description'],
                    ':pm' => $pData['price_monthly'],
                    ':py' => $pData['price_yearly'],
                    ':pm_usd' => $pData['price_monthly_usd'],
                    ':py_usd' => $pData['price_yearly_usd'],
                    ':def' => $pData['is_default'],
                    ':sort' => $pData['sort_order']
                ]);
                $planId = (int)$this->db->lastInsertId();

                // Quotas
                $stmtQuota = $this->db->prepare("
                    INSERT INTO plan_quotas (plan_id, quota_key, quota_label, quota_value)
                    VALUES (:pid, :key, :label, :val)
                ");
                foreach ($pData['quotas'] as $qKey => $qVal) {
                    $label = ucwords(str_replace('_', ' ', $qKey));
                    $stmtQuota->execute([
                        ':pid' => $planId,
                        ':key' => $qKey,
                        ':label' => $label,
                        ':val' => $qVal
                    ]);
                }

                // Features
                $stmtFeature = $this->db->prepare("
                    INSERT INTO plan_features (plan_id, feature_key, feature_label, is_enabled)
                    VALUES (:pid, :key, :label, :enabled)
                ");
                foreach ($pData['features'] as $fKey => $fEnabled) {
                    $label = ucwords(str_replace('_', ' ', $fKey));
                    $stmtFeature->execute([
                        ':pid' => $planId,
                        ':key' => $fKey,
                        ':label' => $label,
                        ':enabled' => $fEnabled
                    ]);
                }
            } else {
                $planId = (int)$existingPlan['id'];
                // Update USD pricing if missing or 0
                if (empty($existingPlan['price_monthly_usd_cents']) || (int)$existingPlan['price_monthly_usd_cents'] === 0) {
                    $stmtUpdateUsd = $this->db->prepare("
                        UPDATE plans SET price_monthly_usd_cents = :pm_usd, price_yearly_usd_cents = :py_usd WHERE id = :id
                    ");
                    $stmtUpdateUsd->execute([
                        ':pm_usd' => $pData['price_monthly_usd'],
                        ':py_usd' => $pData['price_yearly_usd'],
                        ':id' => $planId
                    ]);
                }

                // Seed missing features for existing plan if any
                $stmtFeature = $this->db->prepare("
                    INSERT INTO plan_features (plan_id, feature_key, feature_label, is_enabled)
                    SELECT :pid, :key, :label, :enabled
                    WHERE NOT EXISTS (SELECT 1 FROM plan_features WHERE plan_id = :pid_check AND feature_key = :key_check)
                ");
                foreach ($pData['features'] as $fKey => $fEnabled) {
                    $label = ucwords(str_replace('_', ' ', $fKey));
                    $stmtFeature->execute([
                        ':pid' => $planId,
                        ':key' => $fKey,
                        ':label' => $label,
                        ':enabled' => $fEnabled,
                        ':pid_check' => $planId,
                        ':key_check' => $fKey
                    ]);
                }
            }
        }
    }
}
