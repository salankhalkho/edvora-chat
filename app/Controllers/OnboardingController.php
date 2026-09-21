<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use App\Services\ContentCompactor;
use App\Services\DocumentParser;
use PDO;
use Throwable;

class OnboardingController
{
    /**
     * GET /v1/onboarding/status
     */
    public function getStatus(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();

        // 1. Fetch Organization Details
        $stmtOrg = $db->prepare("SELECT * FROM organizations WHERE id = :id");
        $stmtOrg->execute([':id' => $orgId]);
        $org = $stmtOrg->fetch();

        if (!$org) {
            Response::error('Organization not found.', 404);
        }

        // 2. Fetch Programs & Courses
        $stmtProg = $db->prepare("
            SELECT p.*, p.name AS name, p.name AS course_name 
            FROM programs p 
            WHERE p.organization_id = :org_id 
            ORDER BY p.id ASC
        ");
        $stmtProg->execute([':org_id' => $orgId]);
        $programs = $stmtProg->fetchAll();

        // 3. Fetch Primary Chatbot
        $stmtBot = $db->prepare("SELECT * FROM chatbots WHERE organization_id = :org_id AND is_active = 1 LIMIT 1");
        $stmtBot->execute([':org_id' => $orgId]);
        $chatbot = $stmtBot->fetch() ?: null;

        // 4. Fetch Knowledge Sources Count
        $stmtKs = $db->prepare("SELECT COUNT(*) as cnt FROM knowledge_sources WHERE organization_id = :org_id");
        $stmtKs->execute([':org_id' => $orgId]);
        $ksCount = (int)($stmtKs->fetch()['cnt'] ?? 0);

        // 5. Fetch Staff Count
        $stmtStaff = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE organization_id = :org_id");
        $stmtStaff->execute([':org_id' => $orgId]);
        $staffCount = (int)($stmtStaff->fetch()['cnt'] ?? 0);

        // Calculate Readiness & Category Status
        $readiness = $this->calculateReadiness($org, $programs, $ksCount, $chatbot, $staffCount);

        $onboardingData = !empty($org['onboarding_data']) ? json_decode($org['onboarding_data'], true) : [];
        $admissionsConfig = !empty($org['admissions_config']) ? json_decode($org['admissions_config'], true) : [];
        $campusConfig = !empty($org['campus_config']) ? json_decode($org['campus_config'], true) : [];
        $placementsConfig = !empty($org['placements_config']) ? json_decode($org['placements_config'], true) : [];
        $scholarshipConfig = !empty($org['scholarship_config']) ? json_decode($org['scholarship_config'], true) : [];

        Response::success([
            'onboarding_completed' => (int)($org['onboarding_completed'] ?? 0),
            'onboarding_step' => (int)($org['onboarding_step'] ?? 1),
            'is_completed' => (bool)($org['onboarding_completed'] ?? false),
            'current_step' => (int)($org['onboarding_step'] ?? 1),
            'step_progress_pct' => $readiness['step_progress_pct'] ?? 100,
            'readiness_score' => $readiness['score'],
            'readiness_label' => $readiness['label'],
            'readiness_breakdown' => $readiness['breakdown'],
            'organization' => [
                'id' => (int)$org['id'],
                'name' => $org['name'],
                'short_name' => $org['short_name'],
                'slug' => $org['slug'],
                'onboarding_step' => (int)($org['onboarding_step'] ?? 1),
                'onboarding_completed' => (int)($org['onboarding_completed'] ?? 0),
                'institution_type' => $org['institution_type'],
                'institution_category' => $org['institution_category'],
                'logo_url' => $org['logo_url'],
                'primary_color' => $org['primary_color'],
                'address_line' => $org['address_line'],
                'city' => $org['city'],
                'state' => $org['state'],
                'country' => $org['country'] ?? 'India',
                'pincode' => $org['pincode'],
                'founded_year' => $org['founded_year'],
                'academic_year' => $org['academic_year'],
                'website_url' => $org['website_url'],
                'institution_description' => $org['institution_description'],
                'admissions_config' => $admissionsConfig,
                'campus_config' => $campusConfig,
                'placements_config' => $placementsConfig,
                'scholarship_config' => $scholarshipConfig,
                'onboarding_data' => $onboardingData
            ],
            'programs' => $programs,
            'chatbot' => $chatbot ? [
                'id' => (int)$chatbot['id'],
                'name' => $chatbot['name'],
                'bot_token' => $chatbot['bot_token'],
                'welcome_message' => $chatbot['welcome_message'],
                'primary_color' => $chatbot['primary_color'],
                'widget_style' => $chatbot['widget_style'] ?? 'glassmorphism',
                'theme_mode' => $chatbot['theme_mode'] ?? 'light'
            ] : null,
            'stats' => [
                'knowledge_sources_count' => $ksCount,
                'staff_count' => $staffCount,
                'programs_count' => count($programs)
            ]
        ]);
    }

    /**
     * POST /v1/onboarding/step — Save a step's structured data
     */
    public function saveStep(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $data = $request->all();
        $stepNumber = (int)($data['step'] ?? 1);
        $stepData = $data['data'] ?? [];

        $db = Database::getConnection();

        // Fetch current org onboarding_data
        $stmtCur = $db->prepare("SELECT onboarding_data, onboarding_step FROM organizations WHERE id = :id");
        $stmtCur->execute([':id' => $orgId]);
        $orgRow = $stmtCur->fetch();
        $existingDraft = (!empty($orgRow['onboarding_data'])) ? json_decode($orgRow['onboarding_data'], true) : [];
        if (!is_array($existingDraft)) $existingDraft = [];

        // Merge step data
        $existingDraft["step_{$stepNumber}"] = $stepData;
        $nextStep = max((int)($orgRow['onboarding_step'] ?? 1), $stepNumber + 1);

        try {
            $db->beginTransaction();

            // Handle specific step entity synchronizations
            switch ($stepNumber) {
                case 2: // Institution Profile
                    $stmtUpd = $db->prepare("
                        UPDATE organizations 
                        SET name = COALESCE(:name, name),
                            short_name = :short_name,
                            institution_type = :inst_type,
                            institution_category = :inst_cat,
                            address_line = :address,
                            city = :city,
                            state = :state,
                            country = COALESCE(:country, 'India'),
                            pincode = :pincode,
                            founded_year = :founded,
                            academic_year = :acad_year,
                            website_url = :website,
                            logo_url = COALESCE(:logo_url, logo_url),
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtUpd->execute([
                        ':name' => !empty($stepData['name']) ? trim($stepData['name']) : null,
                        ':short_name' => $stepData['short_name'] ?? null,
                        ':inst_type' => $stepData['institution_type'] ?? null,
                        ':inst_cat' => $stepData['institution_category'] ?? null,
                        ':address' => $stepData['address_line'] ?? null,
                        ':city' => $stepData['city'] ?? null,
                        ':state' => $stepData['state'] ?? null,
                        ':country' => $stepData['country'] ?? 'India',
                        ':pincode' => $stepData['pincode'] ?? null,
                        ':founded' => $stepData['founded_year'] ?? null,
                        ':acad_year' => $stepData['academic_year'] ?? null,
                        ':website' => $stepData['website_url'] ?? null,
                        ':logo_url' => $stepData['logo_url'] ?? null,
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 3: // Institution Identity & Persona
                    $stmtUpd = $db->prepare("
                        UPDATE organizations 
                        SET institution_description = :desc,
                            short_name = COALESCE(:preferred_name, short_name),
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtUpd->execute([
                        ':desc' => $stepData['institution_description'] ?? null,
                        ':preferred_name' => $stepData['preferred_name'] ?? null,
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 4: // Programs
                    if (!empty($stepData['programs']) && is_array($stepData['programs'])) {
                        $stmtProg = $db->prepare("
                            INSERT INTO programs (
                                organization_id, name, slug, program_type, duration, mode, is_admissions_open, created_at, updated_at
                            ) VALUES (
                                :oid, :name, :slug, :type, :duration, :mode, :is_open, NOW(), NOW()
                            )
                        ");

                        foreach ($stepData['programs'] as $p) {
                            if (empty($p['name'])) continue;
                            $name = trim($p['name']);
                            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-')) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                            $stmtProg->execute([
                                ':oid' => $orgId,
                                ':name' => $name,
                                ':slug' => $slug,
                                ':type' => $p['program_type'] ?? 'undergraduate',
                                ':duration' => $p['duration'] ?? '2 years',
                                ':mode' => $p['mode'] ?? 'full_time',
                                ':is_open' => isset($p['is_admissions_open']) ? (int)$p['is_admissions_open'] : 1
                            ]);
                        }
                    }

                    $db->prepare("UPDATE organizations SET onboarding_step = :next_step, onboarding_data = :onb_data, updated_at = NOW() WHERE id = :id")
                        ->execute([':next_step' => $nextStep, ':onb_data' => json_encode($existingDraft), ':id' => $orgId]);
                    break;

                case 5: // Admissions Basics
                    $db->prepare("
                        UPDATE organizations 
                        SET admissions_config = :adm_cfg,
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':adm_cfg' => json_encode($stepData),
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 6: // Key Admissions Essentials & Program Specifics
                    if (!empty($stepData['programs']) && is_array($stepData['programs'])) {
                        foreach ($stepData['programs'] as $p) {
                            if (!empty($p['id'])) {
                                $stmtUpdProg = $db->prepare("
                                    UPDATE programs 
                                    SET eligibility = :eligibility,
                                        application_deadline = :deadline,
                                        application_fee = :app_fee,
                                        application_url = :app_url,
                                        updated_at = NOW()
                                    WHERE id = :id AND organization_id = :oid
                                ");
                                $stmtUpdProg->execute([
                                    ':eligibility' => $p['eligibility'] ?? null,
                                    ':deadline' => $p['application_deadline'] ?? null,
                                    ':app_fee' => $p['application_fee'] ?? null,
                                    ':app_url' => $p['application_url'] ?? null,
                                    ':id' => $p['id'],
                                    ':oid' => $orgId
                                ]);
                            }
                        }
                    }

                    $db->prepare("UPDATE organizations SET onboarding_step = :next_step, onboarding_data = :onb_data, updated_at = NOW() WHERE id = :id")
                        ->execute([':next_step' => $nextStep, ':onb_data' => json_encode($existingDraft), ':id' => $orgId]);
                    break;

                case 7: // Fees & Hostel
                    if (!empty($stepData['programs']) && is_array($stepData['programs'])) {
                        foreach ($stepData['programs'] as $p) {
                            if (!empty($p['id'])) {
                                $tuition = isset($p['tuition_fee']) ? (float)$p['tuition_fee'] : null;
                                $reg = isset($p['registration_fee']) ? (float)$p['registration_fee'] : 0;
                                $other = isset($p['other_fees']) ? (float)$p['other_fees'] : 0;
                                $total = $tuition !== null ? ($tuition + $reg + $other) : (isset($p['total_fee']) ? (float)$p['total_fee'] : null);

                                $stmtUpdFee = $db->prepare("
                                    UPDATE programs 
                                    SET tuition_fee = :tuition,
                                        registration_fee = :reg,
                                        other_fees = :other,
                                        total_fee = :total,
                                        updated_at = NOW()
                                    WHERE id = :id AND organization_id = :oid
                                ");
                                $stmtUpdFee->execute([
                                    ':tuition' => $tuition,
                                    ':reg' => $reg,
                                    ':other' => $other,
                                    ':total' => $total,
                                    ':id' => $p['id'],
                                    ':oid' => $orgId
                                ]);

                                // Also sync to course_scholarships table
                                $stmtFindCs = $db->prepare("SELECT id FROM course_scholarships WHERE organization_id = :org_id AND course_name = :cname LIMIT 1");
                                $stmtFindCs->execute([':org_id' => $orgId, ':cname' => $p['name'] ?? '']);
                                $existingCs = $stmtFindCs->fetch();

                                if ($existingCs) {
                                    $db->prepare("UPDATE course_scholarships SET annual_tuition_fee = :fee, updated_at = NOW() WHERE id = :id")
                                        ->execute([':fee' => $tuition, ':id' => $existingCs['id']]);
                                } else if (!empty($p['name'])) {
                                    $defaultSlabs = json_encode([
                                        ['min' => 95, 'max' => 100, 'waiver_pct' => 100, 'label' => '100% Merit Scholarship'],
                                        ['min' => 85, 'max' => 94.9, 'waiver_pct' => 50, 'label' => '50% Dean Merit Waiver']
                                    ]);
                                    $db->prepare("
                                        INSERT INTO course_scholarships (
                                            organization_id, course_name, degree_level, has_scholarship, evaluation_metric, slabs, annual_tuition_fee, is_active
                                        ) VALUES (
                                            :org_id, :cname, :deg, 1, 'percentage_12th', :slabs, :fee, 1
                                        )
                                    ")->execute([
                                        ':org_id' => $orgId,
                                        ':cname' => $p['name'],
                                        ':deg' => $p['program_type'] ?? 'undergraduate',
                                        ':slabs' => $defaultSlabs,
                                        ':fee' => $tuition
                                    ]);
                                }
                            }
                        }
                    }

                    // Save hostel & fee config
                    $db->prepare("
                        UPDATE organizations 
                        SET campus_config = JSON_SET(COALESCE(campus_config, '{}'), '$.hostel_available', :hostel_avail, '$.hostel_fee', :hostel_fee, '$.food_included', :food_inc, '$.fee_academic_year', :acad_year),
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':hostel_avail' => isset($stepData['hostel_available']) ? (bool)$stepData['hostel_available'] : true,
                        ':hostel_fee' => $stepData['hostel_fee'] ?? null,
                        ':food_inc' => isset($stepData['food_included']) ? (bool)$stepData['food_included'] : false,
                        ':acad_year' => $stepData['academic_year'] ?? null,
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 8: // Scholarships
                    $db->prepare("
                        UPDATE organizations 
                        SET scholarship_config = :sch_cfg,
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':sch_cfg' => json_encode($stepData),
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 9: // Campus Life & Facilities
                    $db->prepare("
                        UPDATE organizations 
                        SET campus_config = :cmp_cfg,
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':cmp_cfg' => json_encode($stepData),
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 10: // Placements
                    $db->prepare("
                        UPDATE organizations 
                        SET placements_config = :plc_cfg,
                            onboarding_step = :next_step,
                            onboarding_data = :onb_data,
                            updated_at = NOW()
                        WHERE id = :id
                    ")->execute([
                        ':plc_cfg' => json_encode($stepData),
                        ':next_step' => $nextStep,
                        ':onb_data' => json_encode($existingDraft),
                        ':id' => $orgId
                    ]);
                    break;

                case 12: // AI Assistant Setup & Personality
                    $botName = $stepData['bot_name'] ?? 'Admissions Assistant';
                    $welcomeMsg = $stepData['welcome_message'] ?? null;
                    $primaryColor = $stepData['primary_color'] ?? '#2563EB';

                    $stmtUpdBot = $db->prepare("
                        UPDATE chatbots 
                        SET name = :name,
                            welcome_message = COALESCE(:welcome, welcome_message),
                            primary_color = :color,
                            updated_at = NOW()
                        WHERE organization_id = :org_id AND is_active = 1
                    ");
                    $stmtUpdBot->execute([
                        ':name' => $botName,
                        ':welcome' => $welcomeMsg,
                        ':color' => $primaryColor,
                        ':org_id' => $orgId
                    ]);

                    $db->prepare("UPDATE organizations SET onboarding_step = :next_step, onboarding_data = :onb_data, updated_at = NOW() WHERE id = :id")
                        ->execute([':next_step' => $nextStep, ':onb_data' => json_encode($existingDraft), ':id' => $orgId]);
                    break;

                case 13: // Human Handoff & Primary Contact
                    if (!empty($stepData['contact_email']) && !empty($stepData['contact_name'])) {
                        $contactEmail = strtolower(trim($stepData['contact_email']));
                        $contactName = trim($stepData['contact_name']);
                        $contactPhone = trim($stepData['contact_phone'] ?? '');

                        // Check if user already exists
                        $stmtFindUser = $db->prepare("SELECT id FROM users WHERE email = :email AND organization_id = :org_id");
                        $stmtFindUser->execute([':email' => $contactEmail, ':org_id' => $orgId]);
                        $existingUser = $stmtFindUser->fetch();

                        if (!$existingUser) {
                            $stmtInsUser = $db->prepare("
                                INSERT INTO users (organization_id, name, email, password_hash, role, can_manage_structure, email_verified_at, created_at, updated_at)
                                VALUES (:org_id, :name, :email, :pass, 'staff', 0, NOW(), NOW(), NOW())
                            ");
                            $tempPass = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                            $stmtInsUser->execute([
                                ':org_id' => $orgId,
                                ':name' => $contactName,
                                ':email' => $contactEmail,
                                ':pass' => $tempPass
                            ]);
                        }
                    }

                    $db->prepare("UPDATE organizations SET onboarding_step = :next_step, onboarding_data = :onb_data, updated_at = NOW() WHERE id = :id")
                        ->execute([':next_step' => $nextStep, ':onb_data' => json_encode($existingDraft), ':id' => $orgId]);
                    break;

                default:
                    // Generic step save
                    $db->prepare("UPDATE organizations SET onboarding_step = :next_step, onboarding_data = :onb_data, updated_at = NOW() WHERE id = :id")
                        ->execute([':next_step' => $nextStep, ':onb_data' => json_encode($existingDraft), ':id' => $orgId]);
                    break;
            }

            // Immediately synthesize structured knowledge base so live preview in Step 14 works instantly
            $this->synthesizeKnowledgeSources($db, $orgId);

            $db->commit();

            // Re-fetch updated readiness
            $stmtOrg = $db->prepare("SELECT * FROM organizations WHERE id = :id");
            $stmtOrg->execute([':id' => $orgId]);
            $updatedOrg = $stmtOrg->fetch();

            $stmtProg = $db->prepare("
                SELECT p.*, p.name AS name, p.name AS course_name 
                FROM programs p 
                WHERE p.organization_id = :org_id
            ");
            $stmtProg->execute([':org_id' => $orgId]);
            $updatedPrograms = $stmtProg->fetchAll();

            $stmtKs = $db->prepare("SELECT COUNT(*) as cnt FROM knowledge_sources WHERE organization_id = :org_id");
            $stmtKs->execute([':org_id' => $orgId]);
            $ksCount = (int)($stmtKs->fetch()['cnt'] ?? 0);

            $stmtBot = $db->prepare("SELECT * FROM chatbots WHERE organization_id = :org_id AND is_active = 1 LIMIT 1");
            $stmtBot->execute([':org_id' => $orgId]);
            $bot = $stmtBot->fetch() ?: null;

            $stmtStaff = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE organization_id = :org_id");
            $stmtStaff->execute([':org_id' => $orgId]);
            $staffCount = (int)($stmtStaff->fetch()['cnt'] ?? 0);

            $readiness = $this->calculateReadiness($updatedOrg, $updatedPrograms, $ksCount, $bot, $staffCount);

            AuditLogger::log('onboarding_step_saved', 'organization', $orgId, [
                'step' => $stepNumber,
                'readiness_score' => $readiness['score']
            ]);

            Response::success([
                'step' => $stepNumber,
                'saved' => true,
                'next_step' => $nextStep,
                'readiness_score' => $readiness['score'],
                'readiness_label' => $readiness['label'],
                'readiness_breakdown' => $readiness['breakdown'],
                'programs' => $updatedPrograms
            ], "Step {$stepNumber} saved successfully");

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error("Failed to save step {$stepNumber}: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/onboarding/complete — Finalize onboarding & synthesize initial structured knowledge
     */
    public function complete(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            // 1. Mark onboarding completed
            $stmtUpd = $db->prepare("
                UPDATE organizations 
                SET onboarding_completed = 1,
                    onboarding_step = 16,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([':id' => $orgId]);

            // 2. Synthesize Structured Knowledge Sources
            $this->synthesizeKnowledgeSources($db, $orgId);

            $db->commit();

            AuditLogger::log('onboarding_completed', 'organization', $orgId, []);

            Response::success([
                'onboarding_completed' => 1,
                'redirect' => '/app'
            ], 'Onboarding completed successfully! Your AI admissions assistant is now active and primed.');

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to complete onboarding: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/onboarding/upload-document — Upload prospectus / fee sheet during onboarding
     */
    public function uploadDocument(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        if (!$orgId) {
            Response::error('Tenant context missing.', 403);
            return;
        }

        $file = $_FILES['file'] ?? $request->getFile('file');
        if (empty($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Valid document file required. Please select a file to upload.', 400);
            return;
        }

        $category = $request->get('category', 'brochure');
        $originalFilename = basename($file['name']);
        $title = trim((string)$request->get('title', ''));
        if (empty($title)) {
            $title = pathinfo($originalFilename, PATHINFO_FILENAME);
        }

        $allowedExts = ['pdf', 'docx', 'doc', 'txt', 'pptx', 'xlsx'];
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            Response::error('Unsupported file type. Please upload a PDF, Word, Presentation, or Text document.', 422);
            return;
        }

        // Storage path in storage/uploads/
        $storageDir = dirname(__DIR__, 2) . '/storage/uploads/';
        if (!file_exists($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $savedFilename = 'onb_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $storageDir . $savedFilename;
        $relativePath = 'storage/uploads/' . $savedFilename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to store uploaded file on server.', 500);
            return;
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            // Extract text using DocumentParser
            $rawText = '';
            try {
                $rawText = DocumentParser::parse($destPath, $originalFilename);
            } catch (Throwable $pe) {
                $rawText = "Document: {$title} ({$originalFilename}). Uploaded for institutional admissions and knowledge retrieval.";
            }

            if (empty(trim($rawText))) {
                $rawText = "Document: {$title} ({$originalFilename}). Uploaded for institutional admissions and knowledge retrieval.";
            }

            $compacted = ContentCompactor::process($rawText, $title);
            $processedContent = $compacted['processed_content'] ?? substr(preg_replace('/\s+/', ' ', $rawText), 0, 8000);
            $keywords = $compacted['keywords'] ?? ("admissions, {$category}, " . implode(', ', array_slice(explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $title))), 0, 5)));

            // 1. Create knowledge_sources row
            $stmtKs = $db->prepare("
                INSERT INTO knowledge_sources (
                    organization_id, type, title, keywords, original_file_path, original_file_size, status, created_at, updated_at
                ) VALUES (
                    :org_id, 'document', :title, :kw, :orig_path, :orig_size, 'active', NOW(), NOW()
                )
            ");
            $stmtKs->execute([
                ':org_id' => $orgId,
                ':title' => $title,
                ':kw' => $keywords,
                ':orig_path' => $relativePath,
                ':orig_size' => (int)$file['size']
            ]);
            $ksId = (int)$db->lastInsertId();

            // Save clean text to storage/knowledge/{org_id}/source_{id}.txt
            $saveMeta = \App\Services\KnowledgeFileStorage::saveText($orgId, $ksId, $processedContent);
            $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path, file_size_bytes = :size, token_count = :tokens, checksum_sha256 = :sha
                WHERE id = :id
            ")->execute([
                ':file_path' => $saveMeta['file_path'],
                ':size'      => $saveMeta['file_size_bytes'],
                ':tokens'    => $saveMeta['token_count'],
                ':sha'       => $saveMeta['checksum_sha256'],
                ':id'        => $ksId
            ]);

            // 2. Create lead_assets row so bot can also deliver it as a lead magnet
            $stmtAsset = $db->prepare("
                INSERT INTO lead_assets (
                    organization_id, title, category, description, file_path, file_name, file_size_bytes, mime_type, lead_intent_trigger, is_active, created_at, updated_at
                ) VALUES (
                    :org_id, :title, :cat, :desc, :path, :fname, :fsize, :mime, :trigger, 1, NOW(), NOW()
                )
            ");
            $stmtAsset->execute([
                ':org_id' => $orgId,
                ':title' => $title,
                ':cat' => $category,
                ':desc' => "Official {$title} document for prospective student admissions.",
                ':path' => $relativePath,
                ':fname' => $originalFilename,
                ':fsize' => (int)($file['size'] ?? 0),
                ':mime' => $file['type'] ?? ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream'),
                ':trigger' => "prospectus, brochure, {$category}, download"
            ]);
            $assetId = (int)$db->lastInsertId();

            // Enqueue keyword enrichment job
            try {
                $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('enrich_keywords', :payload, 'pending', NOW(), NOW())")
                   ->execute([':payload' => json_encode(['knowledge_source_id' => $ksId])]);
            } catch (Throwable $je) {
                // Job dispatch is optional
            }

            $db->commit();

            AuditLogger::log('onboarding_document_uploaded', 'knowledge_source', $ksId, [
                'asset_id' => $assetId,
                'title' => $title,
                'file_name' => $originalFilename
            ]);

            Response::success([
                'knowledge_source_id' => $ksId,
                'asset_id' => $assetId,
                'title' => $title,
                'file_name' => $originalFilename
            ], 'Document uploaded and indexed successfully into knowledge base and lead magnet assets.', 201);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to index document: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Synthesize structured knowledge sources from onboarding profile data
     */
    private function synthesizeKnowledgeSources(PDO $db, int $orgId): void
    {
        $stmtOrg = $db->prepare("SELECT * FROM organizations WHERE id = :id");
        $stmtOrg->execute([':id' => $orgId]);
        $org = $stmtOrg->fetch();

        $stmtProg = $db->prepare("
            SELECT p.*, p.name AS name, p.name AS course_name 
            FROM programs p 
            WHERE p.organization_id = :org_id
        ");
        $stmtProg->execute([':org_id' => $orgId]);
        $programs = $stmtProg->fetchAll();

        $admissionsConfig = !empty($org['admissions_config']) ? json_decode($org['admissions_config'], true) : [];
        $campusConfig = !empty($org['campus_config']) ? json_decode($org['campus_config'], true) : [];
        $placementsConfig = !empty($org['placements_config']) ? json_decode($org['placements_config'], true) : [];
        $scholarshipConfig = !empty($org['scholarship_config']) ? json_decode($org['scholarship_config'], true) : [];

        $orgName = $org['name'] ?? 'Our Institution';
        $shortName = $org['short_name'] ?? $orgName;
        $city = $org['city'] ?? '';
        $state = $org['state'] ?? '';
        $acadYear = $org['academic_year'] ?? '2026-27';

        // 1. Synthesize Programs & Admissions Knowledge
        $progLines = [];
        foreach ($programs as $p) {
            $feeStr = $p['total_fee'] ? " | Total Fee: ₹" . number_format($p['total_fee']) : ($p['tuition_fee'] ? " | Tuition Fee: ₹" . number_format($p['tuition_fee']) : "");
            $eligStr = $p['eligibility'] ? " | Eligibility: " . $p['eligibility'] : "";
            $deadlineStr = $p['application_deadline'] ? " | Deadline: " . $p['application_deadline'] : "";
            $progLines[] = "• {$p['name']} ({$p['program_type']}, {$p['duration']}, {$p['mode']}){$feeStr}{$eligStr}{$deadlineStr}";
        }

        $progKnowledgeText = "{$orgName} ({$shortName}) offers the following academic degree programs for Academic Year {$acadYear}:\n" . implode("\n", $progLines);
        if (!empty($org['institution_description'])) {
            $progKnowledgeText = "About {$orgName}: " . $org['institution_description'] . "\n\n" . $progKnowledgeText;
        }

        $this->upsertStructuredSource($db, $orgId, "Academic Programs & Degrees ({$acadYear})", $progKnowledgeText, "programs, courses, degrees, eligibility, duration, mba, btech, bba, pgdm, fees");

        // 2. Synthesize Admissions Process Knowledge
        $admText = "Admissions Process at {$orgName}:\n";
        if (!empty($admissionsConfig['application_method'])) {
            $admText .= "• Application Method: {$admissionsConfig['application_method']}\n";
        }
        if (!empty($admissionsConfig['accepted_exams'])) {
            $exams = is_array($admissionsConfig['accepted_exams']) ? implode(', ', $admissionsConfig['accepted_exams']) : $admissionsConfig['accepted_exams'];
            $admText .= "• Accepted Entrance Exams: {$exams}\n";
        }
        if (isset($admissionsConfig['interviews'])) {
            $admText .= "• Personal Interviews: " . ($admissionsConfig['interviews'] ? 'Yes, required' : 'No') . "\n";
        }
        if (isset($admissionsConfig['rolling_admissions'])) {
            $admText .= "• Rolling Admissions: " . ($admissionsConfig['rolling_admissions'] ? 'Yes, admissions are open on rolling basis' : 'No') . "\n";
        }

        $this->upsertStructuredSource($db, $orgId, "Admissions Process & Criteria", $admText, "admission, process, how to apply, entrance exams, interview, criteria, application");

        // 3. Synthesize Fees & Hostel Knowledge
        $feeText = "Fee Structure & Hostel Accommodation at {$orgName} ({$acadYear}):\n";
        foreach ($programs as $p) {
            if ($p['tuition_fee'] || $p['total_fee']) {
                $feeText .= "• {$p['name']}: Tuition Fee ₹" . number_format((float)($p['tuition_fee'] ?? $p['total_fee'])) . " | Total Fee ₹" . number_format((float)($p['total_fee'] ?? $p['tuition_fee'])) . "\n";
            }
        }
        if (!empty($campusConfig['hostel_available'])) {
            $hostelFee = !empty($campusConfig['hostel_fee']) ? "₹" . number_format((float)$campusConfig['hostel_fee']) : "Available on request";
            $foodStr = !empty($campusConfig['food_included']) ? " (Mess/Food Included)" : " (Food/Mess Separate)";
            $feeText .= "• On-Campus Hostel Accommodation: Available at {$hostelFee}/year{$foodStr}\n";
        }

        $this->upsertStructuredSource($db, $orgId, "Fee Structure & Hostel Facilities", $feeText, "fees, tuition fee, total fee, hostel fee, hostel accommodation, mess, payment");

        // 4. Synthesize Placements & Campus Life Knowledge
        if (!empty($placementsConfig) || !empty($campusConfig)) {
            $plcText = "Campus Infrastructure & Placements at {$orgName}:\n";
            if (!empty($placementsConfig['placement_rate'])) {
                $plcText .= "• Placement Rate: {$placementsConfig['placement_rate']}%\n";
            }
            if (!empty($placementsConfig['avg_package'])) {
                $plcText .= "• Average CTC Package: {$placementsConfig['avg_package']}\n";
            }
            if (!empty($placementsConfig['highest_package'])) {
                $plcText .= "• Highest Package: {$placementsConfig['highest_package']}\n";
            }
            if (!empty($campusConfig['facilities']) && is_array($campusConfig['facilities'])) {
                $plcText .= "• Major Campus Facilities: " . implode(', ', $campusConfig['facilities']) . "\n";
            }

            $this->upsertStructuredSource($db, $orgId, "Placements & Campus Infrastructure", $plcText, "placements, salary, highest package, average package, companies, campus facilities, gym, library");
        }
    }

    private function upsertStructuredSource(PDO $db, int $orgId, string $title, string $content, string $keywords): void
    {
        $stmtCheck = $db->prepare("SELECT id FROM knowledge_sources WHERE organization_id = :org_id AND title = :title LIMIT 1");
        $stmtCheck->execute([':org_id' => $orgId, ':title' => $title]);
        $existing = $stmtCheck->fetch();

        $processed = substr(preg_replace('/\s+/', ' ', $content), 0, 6000);

        if ($existing) {
            $ksId = (int)$existing['id'];
            $saveMeta = \App\Services\KnowledgeFileStorage::saveText($orgId, $ksId, $processed);
            $stmtUpd = $db->prepare("
                UPDATE knowledge_sources 
                SET file_path = :file_path, file_size_bytes = :size, token_count = :tokens, checksum_sha256 = :sha,
                    keywords = :kw, status = 'active', updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':file_path' => $saveMeta['file_path'],
                ':size'      => $saveMeta['file_size_bytes'],
                ':tokens'    => $saveMeta['token_count'],
                ':sha'       => $saveMeta['checksum_sha256'],
                ':kw'        => $keywords,
                ':id'        => $ksId
            ]);
        } else {
            $stmtIns = $db->prepare("
                INSERT INTO knowledge_sources (
                    organization_id, type, title, keywords, status, created_at, updated_at
                ) VALUES (
                    :org_id, 'text_paste', :title, :kw, 'active', NOW(), NOW()
                )
            ");
            $stmtIns->execute([
                ':org_id' => $orgId,
                ':title'  => $title,
                ':kw'     => $keywords
            ]);
            $ksId = (int)$db->lastInsertId();
            $saveMeta = \App\Services\KnowledgeFileStorage::saveText($orgId, $ksId, $processed);
            $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path, file_size_bytes = :size, token_count = :tokens, checksum_sha256 = :sha
                WHERE id = :id
            ")->execute([
                ':file_path' => $saveMeta['file_path'],
                ':size'      => $saveMeta['file_size_bytes'],
                ':tokens'    => $saveMeta['token_count'],
                ':sha'       => $saveMeta['checksum_sha256'],
                ':id'        => $ksId
            ]);
        }
    }

    /**
     * Compute AI Readiness Score (0–100%) and category breakdown
     */
    private function calculateReadiness(array $org, array $programs, int $ksCount, ?array $chatbot, int $staffCount): array
    {
        $breakdown = [];
        $totalWeight = 0;
        $earnedWeight = 0;

        // 1. Institution Profile (Weight: 15)
        $hasProfile = !empty($org['name']) && !empty($org['institution_type']) && !empty($org['city']);
        $breakdown['institution_profile'] = [
            'label' => 'Institution Profile & Location',
            'complete' => $hasProfile,
            'weight' => 15,
            'details' => $hasProfile ? ($org['city'] . ', ' . ($org['state'] ?? 'India')) : 'Missing city or institution type'
        ];
        $totalWeight += 15;
        if ($hasProfile) $earnedWeight += 15;

        // 2. Institution Identity & Context (Weight: 10)
        $hasIdentity = !empty($org['institution_description']) || !empty($org['short_name']);
        $breakdown['institution_identity'] = [
            'label' => 'Institution Identity & Description',
            'complete' => $hasIdentity,
            'weight' => 10,
            'details' => $hasIdentity ? 'AI introduction context configured' : 'Add preferred name and description'
        ];
        $totalWeight += 10;
        if ($hasIdentity) $earnedWeight += 10;

        // 3. Academic Programs (Weight: 20)
        $hasPrograms = count($programs) > 0;
        $breakdown['programs'] = [
            'label' => 'Academic Programs Offered',
            'complete' => $hasPrograms,
            'weight' => 20,
            'details' => $hasPrograms ? count($programs) . ' program(s) configured' : 'Add at least 1 program'
        ];
        $totalWeight += 20;
        if ($hasPrograms) $earnedWeight += 20;

        // 4. Admissions Criteria & Deadlines (Weight: 15)
        $admCfg = !empty($org['admissions_config']) ? json_decode($org['admissions_config'], true) : [];
        $hasAdmissions = !empty($admCfg) && (!empty($admCfg['application_method']) || !empty($admCfg['accepted_exams']));
        $breakdown['admissions_process'] = [
            'label' => 'Admissions Criteria & Deadlines',
            'complete' => $hasAdmissions,
            'weight' => 15,
            'details' => $hasAdmissions ? 'Application process & exam criteria set' : 'Configure exams & application mode'
        ];
        $totalWeight += 15;
        if ($hasAdmissions) $earnedWeight += 15;

        // 5. Fee Structure (Weight: 10)
        $hasFees = false;
        foreach ($programs as $p) {
            if (!empty($p['tuition_fee']) || !empty($p['total_fee'])) {
                $hasFees = true;
                break;
            }
        }
        $breakdown['fee_structure'] = [
            'label' => 'Tuition & Hostel Fees',
            'complete' => $hasFees,
            'weight' => 10,
            'details' => $hasFees ? 'Tuition and fee breakdowns mapped' : 'Specify program tuition or total fee'
        ];
        $totalWeight += 10;
        if ($hasFees) $earnedWeight += 10;

        // 6. Scholarships & Aid (Weight: 10)
        $schCfg = !empty($org['scholarship_config']) ? json_decode($org['scholarship_config'], true) : [];
        $hasSch = !empty($schCfg);
        $breakdown['scholarships'] = [
            'label' => 'Scholarships & Financial Aid',
            'complete' => $hasSch,
            'weight' => 10,
            'details' => $hasSch ? 'Scholarship criteria configured' : 'Add merit discount rules'
        ];
        $totalWeight += 10;
        if ($hasSch) $earnedWeight += 10;

        // 7. Campus Facilities (Weight: 10)
        $cmpCfg = !empty($org['campus_config']) ? json_decode($org['campus_config'], true) : [];
        $hasCmp = !empty($cmpCfg) && (!empty($cmpCfg['campus_name']) || !empty($cmpCfg['facilities']));
        $breakdown['campus_facilities'] = [
            'label' => 'Campus Facilities & Hostel',
            'complete' => $hasCmp,
            'weight' => 10,
            'details' => $hasCmp ? 'Campus facilities and hostel details mapped' : 'Add campus facilities and hostel info'
        ];
        $totalWeight += 10;
        if ($hasCmp) $earnedWeight += 10;

        // 8. Placement Records (Weight: 10)
        $plcCfg = !empty($org['placements_config']) ? json_decode($org['placements_config'], true) : [];
        $hasPlc = !empty($plcCfg) && (!empty($plcCfg['placement_rate']) || !empty($plcCfg['avg_package']));
        $breakdown['placements'] = [
            'label' => 'Placement Records & Packages',
            'complete' => $hasPlc,
            'weight' => 10,
            'details' => $hasPlc ? 'Placement records and recruiters configured' : 'Add average salary and top recruiters'
        ];
        $totalWeight += 10;
        if ($hasPlc) $earnedWeight += 10;

        // 9. Knowledge Sources & Prospectus (Weight: 10)
        $hasKs = $ksCount > 0;
        $breakdown['knowledge_sources'] = [
            'label' => 'Knowledge Base & Uploaded Prospectus',
            'complete' => $hasKs,
            'weight' => 10,
            'details' => $hasKs ? "{$ksCount} document(s) indexed" : 'Upload admission brochure or prospectus'
        ];
        $totalWeight += 10;
        if ($hasKs) $earnedWeight += 10;

        // 10. AI Assistant Persona & Welcome (Weight: 5)
        $hasBot = !empty($chatbot) && (!empty($chatbot['name']) || !empty($chatbot['welcome_message']));
        $breakdown['assistant_persona'] = [
            'label' => 'AI Assistant Persona & Tone',
            'complete' => $hasBot,
            'weight' => 5,
            'details' => $hasBot ? 'Assistant name and greeting customized' : 'Configure conversational greeting'
        ];
        $totalWeight += 5;
        if ($hasBot) $earnedWeight += 5;

        // 11. Counselor Contact & Lead Routing (Weight: 5)
        $counselorCfg = !empty($org['onboarding_data']) ? json_decode($org['onboarding_data'], true) : [];
        $hasCounselor = !empty($counselorCfg['counselor_email']) || !empty($counselorCfg['counselor_phone']) || $staffCount > 1;
        $breakdown['counselor_routing'] = [
            'label' => 'Counselor Routing & Lead Alerts',
            'complete' => $hasCounselor,
            'weight' => 5,
            'details' => $hasCounselor ? 'Admissions counselor routing configured' : 'Specify counselor email or phone'
        ];
        $totalWeight += 5;
        if ($hasCounselor) $earnedWeight += 5;

        $rawScore = $totalWeight > 0 ? (int)round(($earnedWeight / $totalWeight) * 100) : 0;
        $isCompleted = (bool)($org['onboarding_completed'] ?? false);
        $currentStep = (int)($org['onboarding_step'] ?? 1);
        $stepProgressPct = min(100, (int)round((max(1, $currentStep - 1) / 15) * 100));

        // Combined score reflects data depth, capping unfinalized drafts appropriately
        $score = $isCompleted ? $rawScore : min(85, $rawScore);

        $label = 'Needs Setup';
        if ($isCompleted && $score >= 90) $label = 'Exceptional — Ready to Launch';
        else if ($isCompleted) $label = 'Complete — Live & Active';
        else if ($currentStep >= 14) $label = "Verification Ready (Step {$currentStep} of 16)";
        else if ($score >= 70) $label = "In Progress (Step {$currentStep} of 16 • {$stepProgressPct}%)";
        else if ($score >= 40) $label = "In Progress (Step {$currentStep} of 16 • {$stepProgressPct}%)";
        else $label = "Setup Started (Step {$currentStep} of 16)";

        return [
            'score' => $score,
            'label' => $label,
            'current_step' => $currentStep,
            'is_completed' => $isCompleted,
            'step_progress_pct' => $stepProgressPct,
            'breakdown' => $breakdown
        ];
    }
}
