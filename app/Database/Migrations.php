<?php

namespace App\Database;

use PDO;
use Throwable;

class Migrations
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        $queries = [
            // 1. ORGANIZATIONS
            "CREATE TABLE IF NOT EXISTS organizations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                logo_url VARCHAR(500) NULL,
                primary_color VARCHAR(30) DEFAULT '#2563EB',
                plan_id INT NULL,
                subscription_status ENUM('active', 'inactive', 'trial', 'cancelled') DEFAULT 'active',
                razorpay_subscription_id VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 2. USERS
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('superadmin', 'owner', 'admin', 'staff') DEFAULT 'admin',
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 3. CHATBOTS
            "CREATE TABLE IF NOT EXISTS chatbots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                name VARCHAR(255) NOT NULL DEFAULT 'Admissions Assistant',
                bot_avatar_url VARCHAR(500) NULL,
                welcome_message TEXT NULL,
                primary_color VARCHAR(30) DEFAULT '#2563EB',
                bot_token VARCHAR(64) NOT NULL UNIQUE,
                allowed_domains JSON NULL,
                is_active TINYINT(1) DEFAULT 1,
                system_prompt_override TEXT NULL,
                lead_capture_enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 4. KNOWLEDGE SOURCES
            "CREATE TABLE IF NOT EXISTS knowledge_sources (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                chatbot_id INT NULL,
                type ENUM('document', 'url', 'text_paste') NOT NULL,
                title VARCHAR(255) NOT NULL,
                source_url VARCHAR(500) NULL,
                raw_content LONGTEXT NULL COMMENT 'Complete, unedited extracted text (from PDF, URL, or paste) and will not be used for context building.',
                processed_content LONGTEXT NULL COMMENT 'Compacted, maximum-information, minimum-verbosity version specifically engineered for LLM prompt context. This will be used for LLM context building.',
                keywords TEXT NULL,
                file_path VARCHAR(500) NULL,
                content_hash VARCHAR(64) NULL,
                previous_version_id INT NULL,
                replaced_by_id INT NULL,
                lead_magnet TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if document is a downloadable lead magnet asset',
                status ENUM('processing', 'active', 'failed', 'archived') DEFAULT 'processing',
                last_fetched_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 5. CONVERSATIONS
            "CREATE TABLE IF NOT EXISTS conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                chatbot_id INT NOT NULL,
                visitor_id VARCHAR(64) NOT NULL,
                visitor_name VARCHAR(255) NULL,
                visitor_email VARCHAR(255) NULL,
                visitor_phone VARCHAR(50) NULL,
                page_url VARCHAR(500) NULL,
                page_title VARCHAR(255) NULL,
                utm_source VARCHAR(100) NULL,
                utm_medium VARCHAR(100) NULL,
                utm_campaign VARCHAR(100) NULL,
                lead_name_collected TINYINT(1) DEFAULT 0,
                lead_email_collected TINYINT(1) DEFAULT 0,
                lead_phone_collected TINYINT(1) DEFAULT 0,
                lead_program_interest VARCHAR(255) NULL,
                lead_capture_trigger VARCHAR(100) NULL,
                lead_captured_at TIMESTAMP NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (chatbot_id) REFERENCES chatbots(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 6. MESSAGES
            "CREATE TABLE IF NOT EXISTS messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                organization_id INT NOT NULL,
                role ENUM('user', 'assistant', 'system') NOT NULL,
                content TEXT NOT NULL,
                knowledge_sources_used JSON NULL,
                tokens_used INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 7. LEADS
            "CREATE TABLE IF NOT EXISTS leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                chatbot_id INT NOT NULL,
                conversation_id INT NULL,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NULL,
                phone VARCHAR(50) NULL,
                program_interest VARCHAR(255) NULL,
                notes TEXT NULL,
                status ENUM('new', 'contacted', 'converted') DEFAULT 'new',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 8. JOBS
            "CREATE TABLE IF NOT EXISTS jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(100) NOT NULL,
                payload JSON NOT NULL,
                status ENUM('pending', 'running', 'done', 'failed') DEFAULT 'pending',
                attempts INT DEFAULT 0,
                error_message TEXT NULL,
                run_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 9. LLM PROVIDERS
            "CREATE TABLE IF NOT EXISTS llm_providers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                provider ENUM('openai', 'gemini', 'groq', 'anthropic') NOT NULL,
                model_name VARCHAR(255) NOT NULL,
                api_key_encrypted TEXT NOT NULL,
                api_base_url VARCHAR(500) NULL,
                temperature DECIMAL(3,2) DEFAULT 0.30,
                max_tokens INT DEFAULT 1500,
                timeout_seconds INT DEFAULT 30,
                role ENUM('primary', 'fallback', 'embedding', 'inactive') DEFAULT 'inactive',
                is_active TINYINT(1) DEFAULT 1,
                last_tested_at TIMESTAMP NULL,
                last_test_result ENUM('ok', 'error') NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 10. PLATFORM CONFIG
            "CREATE TABLE IF NOT EXISTS platform_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(100) NOT NULL UNIQUE,
                value_text LONGTEXT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 11. PLANS
            "CREATE TABLE IF NOT EXISTS plans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                price_monthly_paise INT NOT NULL DEFAULT 0,
                price_yearly_paise INT NOT NULL DEFAULT 0,
                price_monthly_usd_cents INT NOT NULL DEFAULT 0,
                price_yearly_usd_cents INT NOT NULL DEFAULT 0,
                razorpay_plan_id_monthly VARCHAR(255) NULL,
                razorpay_plan_id_yearly VARCHAR(255) NULL,
                is_active TINYINT(1) DEFAULT 1,
                is_default TINYINT(1) DEFAULT 0,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 12. PLAN QUOTAS
            "CREATE TABLE IF NOT EXISTS plan_quotas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plan_id INT NOT NULL,
                quota_key VARCHAR(100) NOT NULL,
                quota_label VARCHAR(255) NOT NULL,
                quota_value INT NOT NULL DEFAULT -1,
                quota_period ENUM('monthly', 'total', 'per_chatbot') DEFAULT 'monthly',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 13. PLAN FEATURES
            "CREATE TABLE IF NOT EXISTS plan_features (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plan_id INT NOT NULL,
                feature_key VARCHAR(100) NOT NULL,
                feature_label VARCHAR(255) NOT NULL,
                is_enabled TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 14. SUBSCRIPTIONS
            "CREATE TABLE IF NOT EXISTS subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                plan_id INT NOT NULL,
                billing_cycle ENUM('monthly', 'yearly') DEFAULT 'monthly',
                razorpay_subscription_id VARCHAR(255) NULL,
                status ENUM('active', 'cancelled', 'halted', 'expired') DEFAULT 'active',
                current_period_start TIMESTAMP NULL,
                current_period_end TIMESTAMP NULL,
                cancelled_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 15. USAGE LOGS
            "CREATE TABLE IF NOT EXISTS usage_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                period VARCHAR(7) NOT NULL,
                messages_count INT DEFAULT 0,
                tokens_used BIGINT DEFAULT 0,
                leads_captured INT DEFAULT 0,
                knowledge_sources_count INT DEFAULT 0,
                chatbots_count INT DEFAULT 0,
                url_refreshes INT DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY org_period (organization_id, period),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            // 16. AUDIT LOGS
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NULL,
                user_id INT NULL,
                action VARCHAR(100) NOT NULL,
                resource_type VARCHAR(100) NOT NULL,
                resource_id INT NULL,
                metadata JSON NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];

        foreach ($queries as $sql) {
            $this->db->exec($sql);
        }

        // FULLTEXT index creation on knowledge_sources (title, keywords, processed_content)
        try {
            $checkIdx = $this->db->query("SHOW INDEX FROM knowledge_sources WHERE Key_name = 'ft_knowledge_content'");
            if (!$checkIdx->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources ADD FULLTEXT INDEX ft_knowledge_content (title, keywords, processed_content);");
            }
        } catch (Throwable $e) {
            // Index may already exist
        }

        // Add semantic_keywords column for LLM-generated phrase tags (idempotent)
        try {
            $checkCol = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'semantic_keywords'");
            if (!$checkCol->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources ADD COLUMN semantic_keywords TEXT NULL COMMENT 'LLM-generated 2-5 word strategic phrase tags for intent-based retrieval' AFTER keywords;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Dedicated FULLTEXT index on semantic_keywords for intent-phrase retrieval (idempotent)
        try {
            $checkIdx2 = $this->db->query("SHOW INDEX FROM knowledge_sources WHERE Key_name = 'ft_semantic_keywords'");
            if (!$checkIdx2->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources ADD FULLTEXT INDEX ft_semantic_keywords (semantic_keywords);");
            }
        } catch (Throwable $e) {
            // Index may already exist
        }

        // Add is_test column to conversations table if missing
        try {
            $checkConvTest = $this->db->query("SHOW COLUMNS FROM conversations LIKE 'is_test'");
            if (!$checkConvTest->fetch()) {
                $this->db->exec("ALTER TABLE conversations ADD COLUMN is_test TINYINT(1) DEFAULT 0 AFTER lead_capture_trigger, ADD INDEX idx_conversations_test (organization_id, is_test);");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        try {
            $checkLeadUser = $this->db->query("SHOW COLUMNS FROM leads LIKE 'assigned_user_id'");
            if (!$checkLeadUser->fetch()) {
                $this->db->exec("ALTER TABLE leads ADD COLUMN assigned_user_id INT NULL AFTER conversation_id, ADD CONSTRAINT fk_leads_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Add can_manage_structure column to users table if missing
        try {
            $checkColStruct = $this->db->query("SHOW COLUMNS FROM users LIKE 'can_manage_structure'");
            if (!$checkColStruct->fetch()) {
                $this->db->exec("ALTER TABLE users ADD COLUMN can_manage_structure TINYINT(1) DEFAULT 1 AFTER role;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Add Chatbot Widget Design Customization Columns if missing
        try {
            $checkWidgetStyle = $this->db->query("SHOW COLUMNS FROM chatbots LIKE 'widget_style'");
            if (!$checkWidgetStyle->fetch()) {
                $this->db->exec("ALTER TABLE chatbots 
                    ADD COLUMN widget_style VARCHAR(50) DEFAULT 'glassmorphism' AFTER lead_capture_enabled,
                    ADD COLUMN theme_mode VARCHAR(20) DEFAULT 'dark' AFTER widget_style,
                    ADD COLUMN secondary_color VARCHAR(50) DEFAULT '#38BDF8' AFTER theme_mode,
                    ADD COLUMN header_subtitle VARCHAR(255) DEFAULT 'Online • Replies instantly' AFTER secondary_color,
                    ADD COLUMN launcher_icon VARCHAR(50) DEFAULT 'chat' AFTER header_subtitle,
                    ADD COLUMN launcher_text VARCHAR(50) DEFAULT 'Ask AI' AFTER launcher_icon,
                    ADD COLUMN border_radius VARCHAR(20) DEFAULT 'curved' AFTER launcher_text,
                    ADD COLUMN avatar_icon VARCHAR(50) DEFAULT '🤖' AFTER border_radius,
                    ADD COLUMN quick_chips TEXT NULL AFTER avatar_icon;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Create widget_customizations table (fine-grained per-bot widget design)
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS widget_customizations (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                chatbot_id      INT NOT NULL,
                organization_id INT NOT NULL,
                config          JSON NOT NULL,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_chatbot (chatbot_id),
                INDEX idx_org (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Add supported_languages column to organizations table if missing
        try {
            $checkLang = $this->db->query("SHOW COLUMNS FROM organizations LIKE 'supported_languages'");
            if (!$checkLang->fetch()) {
                $this->db->exec("ALTER TABLE organizations ADD COLUMN supported_languages JSON NULL AFTER primary_color;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Add scholarship_config column to organizations table if missing
        try {
            $checkOrgScholarship = $this->db->query("SHOW COLUMNS FROM organizations LIKE 'scholarship_config'");
            if (!$checkOrgScholarship->fetch()) {
                $this->db->exec("ALTER TABLE organizations ADD COLUMN scholarship_config JSON NULL COMMENT 'Global scholarship rules, toggles and booster percentages' AFTER supported_languages;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Create course_scholarships table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS course_scholarships (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                course_name VARCHAR(255) NOT NULL,
                course_code VARCHAR(50) NULL,
                degree_level ENUM('undergraduate', 'postgraduate', 'diploma', 'doctorate', 'certificate') DEFAULT 'undergraduate',
                has_scholarship TINYINT(1) DEFAULT 1,
                no_scholarship_reason VARCHAR(255) NULL,
                evaluation_metric ENUM('percentage_12th', 'graduation_cgpa', 'entrance_exam', 'merit_rank') DEFAULT 'percentage_12th',
                exam_name VARCHAR(100) NULL,
                slabs JSON NOT NULL COMMENT '[{\"min\":95,\"max\":100,\"waiver_pct\":100,\"label\":\"100% Full Waiver\"}]',
                annual_tuition_fee DECIMAL(12,2) NULL,
                currency VARCHAR(10) DEFAULT 'INR',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                INDEX idx_org (organization_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Enrich leads table with scholarship evaluation details if missing
        try {
            $checkLeadType = $this->db->query("SHOW COLUMNS FROM leads LIKE 'lead_type'");
            if (!$checkLeadType->fetch()) {
                $this->db->exec("ALTER TABLE leads 
                    ADD COLUMN lead_type VARCHAR(50) DEFAULT 'general' AFTER chatbot_id,
                    ADD COLUMN academic_score VARCHAR(100) NULL AFTER program_interest,
                    ADD COLUMN scholarship_tier VARCHAR(255) NULL AFTER academic_score,
                    ADD COLUMN estimated_waiver_amount DECIMAL(12,2) NULL AFTER scholarship_tier,
                    ADD COLUMN evaluation_payload JSON NULL AFTER estimated_waiver_amount;");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Create counselor_callbacks table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS counselor_callbacks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                chatbot_id INT NOT NULL,
                conversation_id INT NULL,
                assigned_user_id INT NULL,
                student_name VARCHAR(255) NOT NULL,
                student_phone VARCHAR(50) NOT NULL,
                student_email VARCHAR(255) NULL,
                preferred_time_slot VARCHAR(100) DEFAULT 'Immediate (ASAP)',
                topic_or_query TEXT NULL,
                status ENUM('pending', 'scheduled', 'in_progress', 'completed', 'no_response', 'cancelled') DEFAULT 'pending',
                counselor_notes TEXT NULL,
                call_attempts INT DEFAULT 0,
                scheduled_at TIMESTAMP NULL,
                completed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (chatbot_id) REFERENCES chatbots(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org_status (organization_id, status),
                INDEX idx_org_assigned (organization_id, assigned_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create lead_assets table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS lead_assets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                category ENUM('brochure', 'fee_structure', 'scholarship_guide', 'placement_report', 'curriculum', 'hostel_guide', 'exam_cutoff', 'international_guide', 'other') DEFAULT 'brochure',
                description TEXT NULL,
                file_path VARCHAR(500) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size_bytes BIGINT NOT NULL DEFAULT 0,
                mime_type VARCHAR(100) DEFAULT 'application/pdf',
                lead_intent_trigger VARCHAR(255) NULL COMMENT 'Suggested conversational intent/keyword trigger for AI bot',
                is_active TINYINT(1) DEFAULT 1,
                downloads_count INT DEFAULT 0,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org (organization_id),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create campus_tour_bookings table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campus_tour_bookings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                chatbot_id INT NOT NULL,
                conversation_id INT NULL,
                assigned_user_id INT NULL,
                student_name VARCHAR(255) NOT NULL,
                student_email VARCHAR(255) NOT NULL,
                student_phone VARCHAR(50) NOT NULL,
                preferred_date DATE NULL,
                preferred_time VARCHAR(50) DEFAULT 'Morning',
                program_interest VARCHAR(255) NULL,
                group_size TINYINT DEFAULT 1,
                notes TEXT NULL,
                status ENUM('pending', 'confirmed', 'completed', 'cancelled', 'no_show') DEFAULT 'pending',
                confirmed_date DATE NULL,
                counselor_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (chatbot_id) REFERENCES chatbots(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org_status (organization_id, status),
                INDEX idx_org_assigned (organization_id, assigned_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Add lead_forms_shown to conversations if missing
        try {
            $checkShown = $this->db->query("SHOW COLUMNS FROM conversations LIKE 'lead_forms_shown'");
            if (!$checkShown->fetch()) {
                $this->db->exec("ALTER TABLE conversations ADD COLUMN lead_forms_shown JSON DEFAULT NULL AFTER lead_capture_trigger;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Add Onboarding Columns to organizations table if missing
        try {
            $checkOnb = $this->db->query("SHOW COLUMNS FROM organizations LIKE 'onboarding_completed'");
            if (!$checkOnb->fetch()) {
                $this->db->exec("ALTER TABLE organizations 
                    ADD COLUMN onboarding_completed TINYINT(1) DEFAULT 0 AFTER subscription_status,
                    ADD COLUMN onboarding_step INT DEFAULT 1 AFTER onboarding_completed,
                    ADD COLUMN onboarding_data JSON NULL AFTER onboarding_step,
                    ADD COLUMN short_name VARCHAR(100) NULL AFTER name,
                    ADD COLUMN institution_type VARCHAR(100) NULL AFTER short_name,
                    ADD COLUMN institution_category VARCHAR(100) NULL AFTER institution_type,
                    ADD COLUMN address_line VARCHAR(255) NULL AFTER logo_url,
                    ADD COLUMN city VARCHAR(100) NULL AFTER address_line,
                    ADD COLUMN state VARCHAR(100) NULL AFTER city,
                    ADD COLUMN country VARCHAR(100) DEFAULT 'India' AFTER state,
                    ADD COLUMN pincode VARCHAR(20) NULL AFTER country,
                    ADD COLUMN founded_year VARCHAR(10) NULL AFTER pincode,
                    ADD COLUMN academic_year VARCHAR(20) NULL AFTER founded_year,
                    ADD COLUMN website_url VARCHAR(500) NULL AFTER academic_year,
                    ADD COLUMN institution_description TEXT NULL AFTER website_url,
                    ADD COLUMN admissions_config JSON NULL AFTER institution_description,
                    ADD COLUMN campus_config JSON NULL AFTER admissions_config,
                    ADD COLUMN placements_config JSON NULL AFTER campus_config;");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Create organization_programs table
        // organization_programs is deprecated and dropped in favor of unified department_courses
        try {
            $this->db->exec("DROP TABLE IF EXISTS organization_programs;");
        } catch (Throwable $e) {
            // Ignored
        }

        // Add Document Validity & Content Health Columns to knowledge_sources table
        try {
            $checkValidity = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'expires_on'");
            if (!$checkValidity->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources 
                    ADD COLUMN category VARCHAR(100) DEFAULT 'Admissions' AFTER title,
                    ADD COLUMN academic_version VARCHAR(50) NULL AFTER category,
                    ADD COLUMN effective_from DATE NULL AFTER academic_version,
                    ADD COLUMN expires_on DATE NULL AFTER effective_from,
                    ADD COLUMN last_reviewed_at DATE NULL AFTER expires_on,
                    ADD COLUMN review_frequency_days INT DEFAULT 180 AFTER last_reviewed_at,
                    ADD COLUMN previous_version_id INT NULL AFTER content_hash,
                    ADD COLUMN replaced_by_id INT NULL AFTER previous_version_id,
                    MODIFY COLUMN status ENUM('processing', 'active', 'expiring_soon', 'expired', 'archived', 'failed') DEFAULT 'processing';");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Add indexes for knowledge validity
        try {
            $this->db->exec("ALTER TABLE knowledge_sources 
                ADD INDEX idx_ks_org_validity (organization_id, status, expires_on),
                ADD INDEX idx_ks_category (organization_id, category);");
        } catch (Throwable $e) {
            // Indexes may already exist
        }

        // Conversion Engine: Enrich leads table
        try {
            $checkPipeline = $this->db->query("SHOW COLUMNS FROM leads LIKE 'pipeline_stage'");
            if (!$checkPipeline->fetch()) {
                $this->db->exec("ALTER TABLE leads 
                    ADD COLUMN pipeline_stage ENUM('new', 'qualified', 'contacted', 'application', 'campus_visit', 'decision', 'enrolled', 'lost') DEFAULT 'new' AFTER status,
                    ADD COLUMN conversion_score INT DEFAULT 50 AFTER pipeline_stage,
                    ADD COLUMN conversion_score_rationale VARCHAR(255) NULL AFTER conversion_score,
                    ADD COLUMN next_best_action JSON NULL AFTER conversion_score_rationale,
                    ADD COLUMN intent_signals JSON NULL AFTER next_best_action,
                    ADD COLUMN acquisition_source VARCHAR(100) DEFAULT 'Direct / Website' AFTER intent_signals,
                    ADD COLUMN campaign_id VARCHAR(100) NULL AFTER acquisition_source,
                    ADD COLUMN assigned_counselor_id INT NULL AFTER campaign_id,
                    ADD COLUMN last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER updated_at;");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        try {
            $this->db->exec("ALTER TABLE leads 
                ADD INDEX idx_leads_org_stage (organization_id, pipeline_stage),
                ADD INDEX idx_leads_org_score (organization_id, conversion_score);");
        } catch (Throwable $e) {
            // Indexes may already exist
        }

        // Conversion Engine: Create lead_journey_events table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS lead_journey_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                lead_id INT NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                event_title VARCHAR(255) NOT NULL,
                event_description TEXT NULL,
                event_metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
                INDEX idx_lead_events (lead_id, created_at),
                INDEX idx_org_events (organization_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Conversion Engine: Create conversion_follow_ups table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS conversion_follow_ups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                lead_id INT NOT NULL,
                assigned_user_id INT NULL,
                action_type ENUM('call', 'whatsapp', 'campus_tour', 'scholarship_followup', 'asset_followup', 'application_help', 'custom') NOT NULL DEFAULT 'call',
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                due_at TIMESTAMP NULL,
                status ENUM('pending', 'completed', 'dismissed') DEFAULT 'pending',
                priority ENUM('urgent', 'high', 'normal', 'low') DEFAULT 'normal',
                completed_at TIMESTAMP NULL,
                completed_by INT NULL,
                counselor_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
                FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org_status_due (organization_id, status, due_at),
                INDEX idx_lead_followups (lead_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Multi-Currency Pricing: Add USD cents columns to plans table
        try {
            $checkUsdMonthly = $this->db->query("SHOW COLUMNS FROM plans LIKE 'price_monthly_usd_cents'");
            if (!$checkUsdMonthly->fetch()) {
                $this->db->exec("ALTER TABLE plans 
                    ADD COLUMN price_monthly_usd_cents INT NOT NULL DEFAULT 0 AFTER price_yearly_paise,
                    ADD COLUMN price_yearly_usd_cents INT NOT NULL DEFAULT 0 AFTER price_monthly_usd_cents;");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Demo Previews Engine: Create demo_previews table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS demo_previews (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_token VARCHAR(64) NOT NULL UNIQUE,
                website_url VARCHAR(500) NOT NULL,
                domain VARCHAR(255) NOT NULL,
                institution_name VARCHAR(255) NULL,
                scrape_status ENUM('success', 'partial', 'failed') DEFAULT 'success',
                scrape_error_reason VARCHAR(500) NULL,
                pages_found INT DEFAULT 0,
                programs_found INT DEFAULT 0,
                programs_list TEXT NULL,
                has_admissions TINYINT(1) DEFAULT 0,
                has_fees TINYINT(1) DEFAULT 0,
                has_scholarships TINYINT(1) DEFAULT 0,
                scraped_context MEDIUMTEXT NULL,
                context_reused_from INT NULL,
                work_email VARCHAR(255) NULL,
                email_captured_at TIMESTAMP NULL,
                contact_name VARCHAR(255) NULL,
                contact_phone VARCHAR(50) NULL,
                counselor_req_at TIMESTAMP NULL,
                conversation_log JSON NULL,
                chat_message_count INT DEFAULT 0,
                lead_stage ENUM('url_entered', 'analyzing', 'scrape_failed', 'analyzed', 'email_captured', 'chatted', 'counselor_requested', 'completed') DEFAULT 'url_entered',
                demo_started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                demo_ended_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_domain (domain),
                INDEX idx_lead_stage (lead_stage),
                INDEX idx_scrape_status (scrape_status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create campuses table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campuses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                short_name VARCHAR(100) NULL,
                is_primary TINYINT(1) DEFAULT 0,
                campus_area VARCHAR(100) NULL COMMENT 'e.g. 150 Acres or 50 Hectares',
                virtual_tour_url VARCHAR(500) NULL,
                has_hostel TINYINT(1) DEFAULT 0 COMMENT 'Hostel facilities available',
                contact_email VARCHAR(255) NULL,
                contact_phone VARCHAR(50) NULL,
                address_line VARCHAR(255) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(100) NULL,
                country VARCHAR(100) DEFAULT 'India',
                pincode VARCHAR(20) NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_org (organization_id),
                INDEX idx_status (status),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Clean deprecation and permanent removal of departments architecture
        try {
            // Drop foreign key constraints referencing department_courses
            try { $this->db->exec("ALTER TABLE campus_courses DROP FOREIGN KEY campus_courses_ibfk_3;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE campus_courses DROP FOREIGN KEY fk_campus_courses_program;"); } catch (Throwable $e) {}

            // Drop foreign key constraints & columns referencing departments
            try { $this->db->exec("ALTER TABLE conversations DROP FOREIGN KEY fk_conversations_dept;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE conversations DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE leads DROP FOREIGN KEY fk_leads_dept;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE leads DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE campus_tour_bookings DROP FOREIGN KEY campus_tour_bookings_ibfk_3;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE campus_tour_bookings DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE campus_tour_slots DROP FOREIGN KEY fk_slot_dept;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE campus_tour_slots DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE counselor_callbacks DROP FOREIGN KEY counselor_callbacks_ibfk_3;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE counselor_callbacks DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE lead_assets DROP FOREIGN KEY lead_assets_ibfk_2;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE lead_assets DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE course_scholarships DROP FOREIGN KEY course_scholarships_ibfk_2;"); } catch (Throwable $e) {}
            try { $this->db->exec("ALTER TABLE course_scholarships DROP COLUMN department_id;"); } catch (Throwable $e) {}

            try { $this->db->exec("ALTER TABLE widget_customizations DROP COLUMN department_id;"); } catch (Throwable $e) {}

            // Drop the 5 department tables
            $this->db->exec("DROP TABLE IF EXISTS department_staff;");
            $this->db->exec("DROP TABLE IF EXISTS department_knowledge;");
            $this->db->exec("DROP TABLE IF EXISTS department_faqs;");
            $this->db->exec("DROP TABLE IF EXISTS department_courses;");
            $this->db->exec("DROP TABLE IF EXISTS departments;");
        } catch (Throwable $e) {
            // Ignored
        }

        // Create campus_courses junction table linked directly to programs
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campus_courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                campus_id INT NOT NULL,
                course_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_campus_course (campus_id, course_id),
                INDEX idx_org (organization_id),
                INDEX idx_campus (campus_id),
                INDEX idx_course (course_id),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            // Clean invalid course_id references before adding foreign key to programs
            try {
                $this->db->exec("DELETE FROM campus_courses WHERE course_id NOT IN (SELECT id FROM programs);");
                $this->db->exec("ALTER TABLE campus_courses ADD CONSTRAINT fk_campus_courses_program FOREIGN KEY (course_id) REFERENCES programs(id) ON DELETE CASCADE;");
            } catch (Throwable $e) {
                // Constraint may already exist
            }
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create standalone programs table (identical to department_courses except without department_id)
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                course_name VARCHAR(255) NOT NULL,
                course_code VARCHAR(50) NULL,
                program_type ENUM('undergraduate', 'postgraduate', 'doctoral', 'executive', 'certificate', 'other') DEFAULT 'undergraduate',
                duration VARCHAR(50) NULL,
                mode ENUM('full_time', 'part_time', 'online', 'hybrid', 'weekend') DEFAULT 'full_time',
                is_admissions_open TINYINT(1) DEFAULT 1,
                tuition_fee DECIMAL(12,2) NULL,
                registration_fee DECIMAL(12,2) NULL,
                other_fees DECIMAL(12,2) NULL,
                total_fee DECIMAL(12,2) NULL,
                currency VARCHAR(10) DEFAULT 'INR',
                eligibility TEXT NULL,
                application_deadline VARCHAR(100) NULL,
                application_fee VARCHAR(50) NULL,
                application_url VARCHAR(500) NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_org (organization_id),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create organization_operating_hours table (institution-level live desk hours & away automation)
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS organization_operating_hours (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL UNIQUE,
                timezone VARCHAR(50) DEFAULT 'America/New_York',
                working_hours JSON NULL,
                auto_away_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_org (organization_id),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Create organization_escalation_rules table (institution-level lead escalation & assignment SLA)
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS organization_escalation_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL UNIQUE,
                lead_assignment_logic VARCHAR(50) DEFAULT 'round_robin',
                sla_target_minutes INT DEFAULT 8,
                escalate_email TINYINT(1) DEFAULT 1,
                escalate_whatsapp TINYINT(1) DEFAULT 1,
                priority_channel VARCHAR(50) DEFAULT 'whatsapp_email',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_org (organization_id),
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Add program_id to knowledge_sources table for Academic Programs filtering & scoping
        try {
            $checkProgramCol = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'program_id'");
            if (!$checkProgramCol->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources 
                    ADD COLUMN program_id INT NULL AFTER chatbot_id,
                    ADD INDEX idx_ks_program (program_id),
                    ADD CONSTRAINT fk_ks_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Task 1: Add program_id to counselor_callbacks
        try {
            $checkCbProgram = $this->db->query("SHOW COLUMNS FROM counselor_callbacks LIKE 'program_id'");
            if (!$checkCbProgram->fetch()) {
                $this->db->exec("ALTER TABLE counselor_callbacks 
                    ADD COLUMN program_id INT NULL AFTER conversation_id,
                    ADD INDEX idx_cb_program (program_id),
                    ADD CONSTRAINT fk_cb_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Task 2: Add program_id to campus_tour_bookings
        try {
            $checkTourProgram = $this->db->query("SHOW COLUMNS FROM campus_tour_bookings LIKE 'program_id'");
            if (!$checkTourProgram->fetch()) {
                $this->db->exec("ALTER TABLE campus_tour_bookings 
                    ADD COLUMN program_id INT NULL AFTER conversation_id,
                    ADD INDEX idx_tour_program (program_id),
                    ADD CONSTRAINT fk_tour_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Task 3: Add program_id to leads
        try {
            $checkLeadProgram = $this->db->query("SHOW COLUMNS FROM leads LIKE 'program_id'");
            if (!$checkLeadProgram->fetch()) {
                $this->db->exec("ALTER TABLE leads 
                    ADD COLUMN program_id INT NULL AFTER conversation_id,
                    ADD INDEX idx_leads_program (program_id),
                    ADD CONSTRAINT fk_leads_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Task 4: Add program_id to lead_assets
        try {
            $checkAssetProgram = $this->db->query("SHOW COLUMNS FROM lead_assets LIKE 'program_id'");
            if (!$checkAssetProgram->fetch()) {
                $this->db->exec("ALTER TABLE lead_assets 
                    ADD COLUMN program_id INT NULL AFTER organization_id,
                    ADD INDEX idx_assets_program (program_id),
                    ADD CONSTRAINT fk_assets_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Add lead_magnet column to knowledge_sources table
        try {
            $checkLmCol = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'lead_magnet'");
            if (!$checkLmCol->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources 
                    ADD COLUMN lead_magnet TINYINT(1) DEFAULT 0 COMMENT 'Flag indicating if document is a downloadable lead magnet asset' AFTER replaced_by_id,
                    ADD INDEX idx_ks_lead_magnet (organization_id, lead_magnet);");
            }
        } catch (Throwable $e) {
            // Column/index may already exist
        }

        // Program Staff table for mapping staff to programs
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS program_staff (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                program_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('lead', 'agent') DEFAULT 'agent',
                is_on_duty TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY prog_user (program_id, user_id),
                INDEX idx_org_prog (organization_id, program_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Campus Tour Slots table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campus_tour_slots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                campus_id INT NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT 'Guided Campus Visit',
                tour_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                max_capacity INT DEFAULT 15,
                booked_count INT DEFAULT 0,
                counselor_user_id INT NULL,
                status ENUM('active', 'cancelled', 'completed') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
                FOREIGN KEY (counselor_user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_org_date (organization_id, tour_date),
                INDEX idx_org_campus (organization_id, campus_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Campus Tour Settings table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campus_tour_settings (
                organization_id INT PRIMARY KEY,
                routing_policy ENUM('direct_assigned', 'round_robin') DEFAULT 'direct_assigned',
                advance_hours INT DEFAULT 12,
                auto_followup_enabled TINYINT(1) DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Campus Tour Slots: is_general column
        try {
            $checkIsGeneral = $this->db->query("SHOW COLUMNS FROM campus_tour_slots LIKE 'is_general'");
            if (!$checkIsGeneral->fetch()) {
                $this->db->exec("ALTER TABLE campus_tour_slots 
                    ADD COLUMN is_general TINYINT(1) NOT NULL DEFAULT 1 AFTER title,
                    ADD INDEX idx_slot_general (organization_id, is_general);");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Campus Tour Slot Programs Junction Table
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS campus_tour_slot_programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                slot_id INT NOT NULL,
                program_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (slot_id) REFERENCES campus_tour_slots(id) ON DELETE CASCADE,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_slot_prog (slot_id, program_id),
                INDEX idx_org_prog (organization_id, program_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Campus Tour Bookings: slot_id column
        try {
            $checkBookingSlot = $this->db->query("SHOW COLUMNS FROM campus_tour_bookings LIKE 'slot_id'");
            if (!$checkBookingSlot->fetch()) {
                $this->db->exec("ALTER TABLE campus_tour_bookings 
                    ADD COLUMN slot_id INT NULL AFTER conversation_id,
                    ADD INDEX idx_booking_slot (slot_id),
                    ADD CONSTRAINT fk_booking_slot FOREIGN KEY (slot_id) REFERENCES campus_tour_slots(id) ON DELETE SET NULL;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Conversations: last_offer_turn and total_offers_count for Anti-Fatigue Offer Cadence
        try {
            $checkOfferTurn = $this->db->query("SHOW COLUMNS FROM conversations LIKE 'last_offer_turn'");
            if (!$checkOfferTurn->fetch()) {
                $this->db->exec("ALTER TABLE conversations 
                    ADD COLUMN last_offer_turn INT DEFAULT 0 AFTER lead_capture_trigger,
                    ADD COLUMN total_offers_count INT DEFAULT 0 AFTER last_offer_turn;");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Early Lead Ingestion: leads.name default, conversations.program_id, and indexes
        try {
            // Ensure leads.name can accept early-stage leads without immediate name capture
            $this->db->exec("ALTER TABLE leads MODIFY COLUMN name VARCHAR(255) NOT NULL DEFAULT 'Prospective Student';");
        } catch (Throwable $e) {
            // Modify column may already be applied
        }

        try {
            $checkConvProg = $this->db->query("SHOW COLUMNS FROM conversations LIKE 'program_id'");
            if (!$checkConvProg->fetch()) {
                $this->db->exec("ALTER TABLE conversations 
                    ADD COLUMN program_id INT NULL AFTER lead_program_interest,
                    ADD INDEX idx_conv_program (program_id);");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        try {
            $this->db->exec("ALTER TABLE leads ADD INDEX idx_leads_conversation_id (conversation_id);");
        } catch (Throwable $e) {
            // Index may already exist
        }

        try {
            $this->db->exec("ALTER TABLE lead_assets COMMENT = 'DEPRECATED: DO NOT USE. Table is preserved to avoid application crash.'");
        } catch (Throwable $e) {
            // Table comment update
        }

        try {
            $this->db->exec("ALTER TABLE course_scholarships COMMENT = 'DEPRECATED: DO NOT USE. Table is preserved to avoid application crash.'");
        } catch (Throwable $e) {
            // Table comment update
        }

        // Scholarship Rules Table mapped to programs
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS scholarship_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                program_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                code VARCHAR(50) NULL,
                description TEXT NULL,
                evaluation_metric ENUM('percentage_12th', 'graduation_cgpa', 'entrance_exam', 'merit_rank', 'general_merit') DEFAULT 'percentage_12th',
                exam_name VARCHAR(100) NULL,
                discount_type ENUM('percentage', 'fixed_amount') DEFAULT 'percentage',
                discount_value DECIMAL(12,2) DEFAULT 0.00,
                slabs JSON NULL COMMENT '[{\"min\":90,\"max\":100,\"waiver_pct\":50,\"label\":\"90%+ Waiver\"}]',
                eligibility_criteria TEXT NULL,
                terms_conditions TEXT NULL,
                max_recipients INT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
                INDEX idx_org_prog (organization_id, program_id),
                INDEX idx_active (organization_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Drop deprecated ft_semantic_keywords index and semantic_keywords column (idempotent)
        try {
            $checkIdx2 = $this->db->query("SHOW INDEX FROM knowledge_sources WHERE Key_name = 'ft_semantic_keywords'");
            if ($checkIdx2 && $checkIdx2->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources DROP INDEX ft_semantic_keywords;");
            }
        } catch (Throwable $e) {
            // Index may already be dropped
        }

        try {
            $checkCol = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'semantic_keywords'");
            if ($checkCol && $checkCol->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources DROP COLUMN semantic_keywords;");
            }
        } catch (Throwable $e) {
            // Column may already be dropped
        }

        // Knowledge Items table for chunked topic-level content & embeddings
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS knowledge_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                organization_id INT NOT NULL,
                source_id INT NOT NULL,
                program_id INT NULL,
                topic VARCHAR(255) NULL,
                content TEXT NOT NULL,
                page INT NULL,
                embedding JSON NULL COMMENT 'Vector embedding values array',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
                FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE,
                FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
                INDEX idx_org_source (organization_id, source_id),
                INDEX idx_org_program (organization_id, program_id),
                FULLTEXT INDEX ft_item_topic_content (topic, content)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        } catch (Throwable $e) {
            // Table may already exist
        }

        // Alter llm_providers role column to add 'embedding'
        try {
            $this->db->exec("ALTER TABLE llm_providers MODIFY COLUMN role ENUM('primary', 'fallback', 'embedding', 'inactive') DEFAULT 'inactive'");
        } catch (Throwable $e) {
            // Column modification may already exist or fail gracefully
        }

        // Add program_id column to knowledge_sources (FK to programs, nullable)
        try {
            $checkProgCol = $this->db->query("SHOW COLUMNS FROM knowledge_sources LIKE 'program_id'");
            if (!$checkProgCol->fetch()) {
                $this->db->exec("ALTER TABLE knowledge_sources ADD COLUMN program_id INT NULL AFTER chatbot_id, ADD INDEX idx_ks_program_id (program_id);");
            }
        } catch (Throwable $e) {
            // Column may already exist
        }

        // Extend knowledge_sources.type ENUM to include 'program_txt'
        try {
            $this->db->exec("ALTER TABLE knowledge_sources MODIFY COLUMN type ENUM('document','url','text_paste','program_txt') NOT NULL;");
        } catch (Throwable $e) {
            // Enum may already include program_txt
        }

        // Clean up duplicate plan_quotas, keeping the highest/latest ID for each (plan_id, quota_key)
        try {
            $this->db->exec("
                DELETE pq1 FROM plan_quotas pq1
                INNER JOIN plan_quotas pq2 
                WHERE pq1.plan_id = pq2.plan_id 
                  AND pq1.quota_key = pq2.quota_key 
                  AND pq1.id < pq2.id;
            ");
        } catch (Throwable $e) {
            // Cleanup error handled gracefully
        }

        // Add UNIQUE constraint on plan_quotas (plan_id, quota_key) if not exists
        try {
            $checkIndex = $this->db->query("SHOW INDEX FROM plan_quotas WHERE Key_name = 'uq_plan_quota'");
            if (!$checkIndex->fetch()) {
                $this->db->exec("ALTER TABLE plan_quotas ADD UNIQUE KEY uq_plan_quota (plan_id, quota_key);");
            }
        } catch (Throwable $e) {
            // Index may already exist
        }

        // Clean up duplicate plan_features, keeping the highest/latest ID for each (plan_id, feature_key)
        try {
            $this->db->exec("
                DELETE pf1 FROM plan_features pf1
                INNER JOIN plan_features pf2 
                WHERE pf1.plan_id = pf2.plan_id 
                  AND pf1.feature_key = pf2.feature_key 
                  AND pf1.id < pf2.id;
            ");
        } catch (Throwable $e) {
            // Cleanup error handled gracefully
        }

        // Add UNIQUE constraint on plan_features (plan_id, feature_key) if not exists
        try {
            $checkFeatIndex = $this->db->query("SHOW INDEX FROM plan_features WHERE Key_name = 'uq_plan_feature'");
            if (!$checkFeatIndex->fetch()) {
                $this->db->exec("ALTER TABLE plan_features ADD UNIQUE KEY uq_plan_feature (plan_id, feature_key);");
            }
        } catch (Throwable $e) {
            // Index may already exist
        }

        // Add badge and cta columns to plans table if missing
        try {
            $checkBadge = $this->db->query("SHOW COLUMNS FROM plans LIKE 'badge_text'");
            if (!$checkBadge->fetch()) {
                $this->db->exec("ALTER TABLE plans 
                    ADD COLUMN badge_text VARCHAR(50) NULL AFTER description,
                    ADD COLUMN cta_text VARCHAR(100) NULL AFTER badge_text,
                    ADD COLUMN cta_link VARCHAR(255) NULL AFTER cta_text;");
            }
        } catch (Throwable $e) {
            // Columns may already exist
        }

        // Ensure all required comparison matrix feature flags exist in plan_features
        try {
            $standardFeatures = [
                // Category 2: Conversational Admissions Engine
                'campus_tour' => 'Campus Tour Booking',
                'counselor_callback' => 'Counselor 1-on-1 Callback',
                'asset_delivery' => 'Brochure & Fee Delivery',
                'intent_scoring' => 'Automated Intent Scoring',
                // Category 3: Platform & Customization
                'multilingual' => 'Multilingual Counseling',
                'mobile_sdk' => 'Mobile Webview & SDK',
                // Category 4: Analytics & Compliance
                'knowledge_gap_detection' => 'Knowledge Gap Detection',
                'institutional_privacy' => 'Institutional Privacy & Encryption',
                // Category 5: Enterprise & Support
                'support_channel' => 'Support Channel Tier'
            ];

            // Default values for plans: Starter (1), Growth (2), Pro (3)
            $planDefaults = [
                1 => [
                    'campus_tour' => 1, 'counselor_callback' => 1, 'asset_delivery' => 1, 'intent_scoring' => 1,
                    'multilingual' => 1, 'mobile_sdk' => 1,
                    'knowledge_gap_detection' => 1, 'institutional_privacy' => 1,
                    'support_channel' => 0 // Email
                ],
                2 => [
                    'campus_tour' => 1, 'counselor_callback' => 1, 'asset_delivery' => 1, 'intent_scoring' => 1,
                    'multilingual' => 1, 'mobile_sdk' => 1,
                    'knowledge_gap_detection' => 1, 'institutional_privacy' => 1,
                    'support_channel' => 1 // Priority Email + Chat
                ],
                3 => [
                    'campus_tour' => 1, 'counselor_callback' => 1, 'asset_delivery' => 1, 'intent_scoring' => 1,
                    'multilingual' => 1, 'mobile_sdk' => 1,
                    'knowledge_gap_detection' => 1, 'institutional_privacy' => 1,
                    'support_channel' => 2 // Dedicated Mgr
                ]
            ];

            $stmtInsertFeat = $this->db->prepare("
                INSERT INTO plan_features (plan_id, feature_key, feature_label, is_enabled)
                VALUES (:pid, :fkey, :label, :enabled)
                ON DUPLICATE KEY UPDATE feature_label = :label_upd
            ");

            foreach ($planDefaults as $pId => $features) {
                // Check if plan exists
                $stmtPCheck = $this->db->prepare("SELECT id FROM plans WHERE id = :id");
                $stmtPCheck->execute([':id' => $pId]);
                if ($stmtPCheck->fetch()) {
                    foreach ($features as $fKey => $fVal) {
                        $label = $standardFeatures[$fKey] ?? ucwords(str_replace('_', ' ', $fKey));
                        $stmtInsertFeat->execute([
                            ':pid' => $pId,
                            ':fkey' => $fKey,
                            ':label' => $label,
                            ':enabled' => $fVal,
                            ':label_upd' => $label
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            // Error handled gracefully
        }
    }
}



