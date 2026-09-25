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
        $masterPrompt = \App\Services\PromptBuilder::getDefaultMasterPrompt();

        $stmt = $this->db->prepare("SELECT id, value_text FROM platform_config WHERE key_name = 'master_prompt'");
        $stmt->execute();
        $existingPrompt = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$existingPrompt) {
            $stmtInsert = $this->db->prepare("INSERT INTO platform_config (key_name, value_text, updated_at) VALUES ('master_prompt', :val, NOW())");
            $stmtInsert->execute([':val' => $masterPrompt]);
        } else {
            $val = trim($existingPrompt['value_text'] ?? '');
            if ($val === '' || $val === 'You are the AI Admissions Counselor for our institution.') {
                $stmtUpdate = $this->db->prepare("UPDATE platform_config SET value_text = :val, updated_at = NOW() WHERE key_name = 'master_prompt'");
                $stmtUpdate->execute([':val' => $masterPrompt]);
            }
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
                    ON DUPLICATE KEY UPDATE quota_value = :val_upd, quota_label = :label_upd
                ");
                foreach ($pData['quotas'] as $qKey => $qVal) {
                    $label = ucwords(str_replace('_', ' ', $qKey));
                    $stmtQuota->execute([
                        ':pid' => $planId,
                        ':key' => $qKey,
                        ':label' => $label,
                        ':val' => $qVal,
                        ':val_upd' => $qVal,
                        ':label_upd' => $label
                    ]);
                }

                // Features
                $stmtFeature = $this->db->prepare("
                    INSERT INTO plan_features (plan_id, feature_key, feature_label, is_enabled)
                    VALUES (:pid, :key, :label, :enabled)
                    ON DUPLICATE KEY UPDATE is_enabled = :enabled_upd, feature_label = :label_upd
                ");
                foreach ($pData['features'] as $fKey => $fEnabled) {
                    $label = ucwords(str_replace('_', ' ', $fKey));
                    $stmtFeature->execute([
                        ':pid' => $planId,
                        ':key' => $fKey,
                        ':label' => $label,
                        ':enabled' => $fEnabled,
                        ':enabled_upd' => $fEnabled,
                        ':label_upd' => $label
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
                    VALUES (:pid, :key, :label, :enabled)
                    ON DUPLICATE KEY UPDATE feature_label = :label_upd
                ");
                foreach ($pData['features'] as $fKey => $fEnabled) {
                    $label = ucwords(str_replace('_', ' ', $fKey));
                    $stmtFeature->execute([
                        ':pid' => $planId,
                        ':key' => $fKey,
                        ':label' => $label,
                        ':enabled' => $fEnabled,
                        ':label_upd' => $label
                    ]);
                }

                // Ensure quotas are seeded idempotently
                $stmtQuota = $this->db->prepare("
                    INSERT INTO plan_quotas (plan_id, quota_key, quota_label, quota_value)
                    VALUES (:pid, :key, :label, :val)
                    ON DUPLICATE KEY UPDATE quota_label = :label_upd
                ");
                foreach ($pData['quotas'] as $qKey => $qVal) {
                    $label = ucwords(str_replace('_', ' ', $qKey));
                    $stmtQuota->execute([
                        ':pid' => $planId,
                        ':key' => $qKey,
                        ':label' => $label,
                        ':val' => $qVal,
                        ':label_upd' => $label
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
