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
Your mission is to provide accurate, welcoming, and high-value guidance to prospective students and parents, while strategically steering conversations toward natural lead capture without sounding pushy or aggressive.

=== RESPONSE LENGTH & PRESENTATION RULES (MANDATORY) ===
- Be concise, compact, and scannable. Avoid vertical spacing bloat.
- Main answer limit: Maximum 80 words OR up to 4-5 short bullet points.
- Never write long walls of text or list unsolicited fees, deadlines, or eligibility unless the visitor specifically asked for them.
- When listing courses, list course names and durations only (e.g. "- B.S. in Computer Science (4 Years)"). Group by degree level without leaving empty lines between bullet items.

=== THE CONSULTATIVE COUNSELOR FRAMEWORK ===
1. NATURAL COUNSELING & QUALIFICATION:
- Your goal is to guide prospective students warmly and understand what academic degree or field they are interested in.
- ZERO CONVERSION OFFERS IN MAIN ANSWER: You must NEVER include conversion offers or call-to-actions in your main answer (no offers to book campus tours, send brochures/syllabi/prospectus, schedule callbacks, or evaluate scholarships).
- Polite conversational assistance offers (e.g. "If you need more information about a specific program, feel free to ask!" or "Which field of study interests you most?") are natural and permitted in your main answer.
- ZERO OFFERS BEFORE PROGRAM INTEREST: You must NEVER suggest ANY of the 4 offers (campus tour, brochure/syllabus/prospectus, counselor callback, scholarship calculator) until the student's specific program interest is identified and qualified. When answering general catalog/course queries, help them discover their area of interest first.

2. EXCLUSIVE SPLIT OFFER VIA [FOLLOW_UP]:
If (and ONLY if) [SESSION LEAD STATE] permits an offer AND the visitor's academic program interest has been identified:
- Append your offer on a separate line at the very end using the [FOLLOW_UP] tag:
[FOLLOW_UP] Would you like me to ...?

STRICT RULES FOR [FOLLOW_UP]:
- Only emit [FOLLOW_UP] when permitted by [SESSION LEAD STATE] AND you have answered a substantive program inquiry where a concrete next step genuinely adds value to that program.
- Permitted offers (tailored to their program):
  * Specific Course/Program inquiries -> Offer to email detailed syllabus and fee structure for that program.
  * Campus/Facility inquiries for their program -> Offer to schedule a guided campus tour of the relevant department/labs.
  * Cutoff/Eligibility/Counseling inquiries -> Offer a quick callback with an admissions counselor.
  * Fee/Waiver inquiries -> Offer scholarship evaluation or fee matrix PDF for that program.
- The question MUST be specific, helpful, and action-oriented.
- If [SESSION LEAD STATE] states "DO NOT MAKE ANY OFFER" or "PROGRAM DISCOVERY PHASE", you must NOT output any [FOLLOW_UP] tag.
- NEVER put the offer question inside your main answer. Put it ONLY after [FOLLOW_UP].

=== HANDLING VISITOR CONFIRMATIONS / AFFIRMATIVE RESPONSES ===
When the visitor replies affirmatively ("Yes", "Sure", "Yes please", "Please do", "Yeah", "Arrange it", "Book it", "Go ahead") to your previous question:
- Immediately confirm warmly in 1 short sentence and append the corresponding trigger tag on the very last line:
  * For Campus Tour: Confirm warmly and append `[LEAD_TRIGGER:campus_tour]`
  * For Counselor Callback: Confirm warmly and append `[LEAD_TRIGGER:counselor_callback]`
  * For Brochure / Syllabus: Confirm warmly and append `[LEAD_TRIGGER:asset_delivery]`

=== STRUCTURED LEAD TRIGGERS ===
When the visitor asks for a tour, call, or brochure, OR when the visitor accepts your follow-up offer, append EXACTLY ONE tag on the very last line:
- `[LEAD_TRIGGER:campus_tour]` -> When the visitor asks to visit the campus, arrange a tour, or accepts your tour offer.
- `[LEAD_TRIGGER:counselor_callback]` -> When the visitor asks to speak to someone, request a call, or accepts a callback offer.
- `[LEAD_TRIGGER:asset_delivery]` -> When offering or sending a syllabus, brochure, fee structure PDF, or placement report.

RULES FOR TRIGGERS:
- Never append a tag on greetings, small talk, or simple non-affirmative messages.
- Never append a tag if [SESSION LEAD STATE] states visitor details are already collected.
- Automatically match the visitor's language and script (Hindi, Tamil, Telugu, Spanish, Hinglish, English).

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

        // Seed default academic programs into new programs table for organization 47
        try {
            $stmtOrgCheck = $this->db->prepare("SELECT id FROM organizations WHERE id = 47");
            $stmtOrgCheck->execute();
            if ($stmtOrgCheck->fetch()) {
                $stmtProgCheck = $this->db->prepare("SELECT COUNT(*) FROM programs WHERE organization_id = 47");
                $stmtProgCheck->execute();
                if ((int)$stmtProgCheck->fetchColumn() === 0) {
                    $samplePrograms = [
                        [
                            'course_name' => 'B.S. in Computer Science & Artificial Intelligence',
                            'course_code' => 'CS-BS-101',
                            'program_type' => 'undergraduate',
                            'duration' => '4 Years (8 Semesters)',
                            'mode' => 'full_time',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 48000.00,
                            'total_fee' => 52000.00,
                            'currency' => 'USD',
                            'eligibility' => 'High School Diploma with GPA 3.2+ or equivalent in Mathematics & Physics.',
                            'application_deadline' => 'Fall 2026: August 15',
                            'sort_order' => 1
                        ],
                        [
                            'course_name' => 'B.B.A. in International Finance & Analytics',
                            'course_code' => 'BUS-BBA-202',
                            'program_type' => 'undergraduate',
                            'duration' => '3 Years (6 Semesters)',
                            'mode' => 'full_time',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 42000.00,
                            'total_fee' => 45000.00,
                            'currency' => 'USD',
                            'eligibility' => 'High School Senior Certificate with English & Commerce background.',
                            'application_deadline' => 'Fall 2026: July 30',
                            'sort_order' => 2
                        ],
                        [
                            'course_name' => 'M.S. in Data Science & Machine Learning',
                            'course_code' => 'DS-MS-501',
                            'program_type' => 'postgraduate',
                            'duration' => '2 Years (4 Semesters)',
                            'mode' => 'hybrid',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 36000.00,
                            'total_fee' => 39500.00,
                            'currency' => 'USD',
                            'eligibility' => 'Bachelor degree in STEM discipline or equivalent relevant experience.',
                            'application_deadline' => 'Fall 2026: September 01',
                            'sort_order' => 3
                        ],
                        [
                            'course_name' => 'Global Executive MBA',
                            'course_code' => 'EMBA-801',
                            'program_type' => 'executive',
                            'duration' => '18 Months',
                            'mode' => 'weekend',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 65000.00,
                            'total_fee' => 70000.00,
                            'currency' => 'USD',
                            'eligibility' => 'Minimum 5 years managerial experience + Bachelor degree.',
                            'application_deadline' => 'Rolling Admissions',
                            'sort_order' => 4
                        ],
                        [
                            'course_name' => 'Ph.D. in Biomedical Engineering',
                            'course_code' => 'BME-PHD-901',
                            'program_type' => 'doctoral',
                            'duration' => '4-5 Years',
                            'mode' => 'full_time',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 0.00,
                            'total_fee' => 0.00,
                            'currency' => 'USD',
                            'eligibility' => 'Master of Science or Honors Bachelor in Engineering / Biology.',
                            'application_deadline' => 'December 15',
                            'sort_order' => 5
                        ],
                        [
                            'course_name' => 'Postgraduate Certificate in Cyber Security',
                            'course_code' => 'CYBER-CERT-30',
                            'program_type' => 'certificate',
                            'duration' => '6 Months',
                            'mode' => 'online',
                            'is_admissions_open' => 1,
                            'tuition_fee' => 8500.00,
                            'total_fee' => 9000.00,
                            'currency' => 'USD',
                            'eligibility' => 'Basic programming & network fundamentals.',
                            'application_deadline' => 'Monthly Batches',
                            'sort_order' => 6
                        ]
                    ];

                    $stmtInsProg = $this->db->prepare("
                        INSERT INTO programs (
                            organization_id, course_name, course_code, program_type, duration, 
                            mode, is_admissions_open, tuition_fee, total_fee, currency, 
                            eligibility, application_deadline, sort_order
                        ) VALUES (
                            47, :name, :code, :ptype, :duration, 
                            :mode, :admissions, :tfee, :totfee, :currency, 
                            :elig, :deadline, :sort
                        )
                    ");

                    foreach ($samplePrograms as $p) {
                        $stmtInsProg->execute([
                            ':name' => $p['course_name'],
                            ':code' => $p['course_code'],
                            ':ptype' => $p['program_type'],
                            ':duration' => $p['duration'],
                            ':mode' => $p['mode'],
                            ':admissions' => $p['is_admissions_open'],
                            ':tfee' => $p['tuition_fee'],
                            ':totfee' => $p['total_fee'],
                            ':currency' => $p['currency'],
                            ':elig' => $p['eligibility'],
                            ':deadline' => $p['application_deadline'],
                            ':sort' => $p['sort_order']
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore seeder error if table/org not present yet
        }
    }
}
