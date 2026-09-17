<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;
use Throwable;

class ScholarshipController
{
    private function getOrgId(Request $request): ?int
    {
        $orgId = $GLOBALS['organization_id'] ?? $GLOBALS['auth_user']['organization_id'] ?? $request->get('organization_id');
        return $orgId ? (int)$orgId : null;
    }

    /**
     * GET /v1/scholarships/config — Get organization & departments scholarship configuration
     */
    public function getConfig(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            // Fetch Org Config
            $stmtOrg = $db->prepare("SELECT id, name, scholarship_config FROM organizations WHERE id = ?");
            $stmtOrg->execute([$orgId]);
            $org = $stmtOrg->fetch();

            $defaultOrgConfig = [
                'enabled' => true,
                'sports_pct' => 5,
                'girl_child_pct' => 5,
                'defense_pct' => 5,
                'early_bird_pct' => 5,
                'allow_stacked_boosters' => true,
                'max_total_waiver_pct' => 100,
                'show_currency_savings' => true,
                'headline' => 'Scholarship & Fee Waiver Eligibility Check',
                'subheadline' => 'Discover how much tuition discount you qualify for in 30 seconds.'
            ];

            $orgConfig = $org && !empty($org['scholarship_config'])
                ? array_merge($defaultOrgConfig, json_decode($org['scholarship_config'], true) ?: [])
                : $defaultOrgConfig;

            $depts = [];

            // Fetch all course scholarships
            $stmtCourses = $db->prepare("
                SELECT cs.*
                FROM course_scholarships cs
                WHERE cs.organization_id = ?
                ORDER BY cs.degree_level ASC, cs.course_name ASC
            ");
            $stmtCourses->execute([$orgId]);
            $courses = $stmtCourses->fetchAll();

            foreach ($courses as &$c) {
                $c['slabs'] = !empty($c['slabs']) ? json_decode($c['slabs'], true) : [];
            }

            Response::success([
                'org_config' => $orgConfig,
                'departments' => $depts,
                'courses' => $courses
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to load scholarship configuration: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /v1/scholarships/config — Update organization or department scholarship settings
     */
    public function updateConfig(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $data = $request->all();
        $db = Database::getConnection();

        try {
            // Update Org global config
            $orgConfig = $data['config'] ?? $data;
            $stmt = $db->prepare("UPDATE organizations SET scholarship_config = ? WHERE id = ?");
            $stmt->execute([json_encode($orgConfig), $orgId]);

            AuditLogger::log('organization_scholarship_updated', 'organization', $orgId, $orgConfig);
            Response::success(['config' => $orgConfig], 'Organization scholarship settings updated.');
        } catch (Throwable $e) {
            Response::error('Failed to update scholarship settings: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/scholarships/courses — Create or update course scholarship rule
     */
    public function saveCourse(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $data = $request->all();
        $courseId = isset($data['id']) && (int)$data['id'] > 0 ? (int)$data['id'] : null;

        $errors = Validator::validate($data, [
            'course_name' => 'required|max:255'
        ]);
        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
            return;
        }

        $courseName = trim($data['course_name']);
        $courseCode = trim($data['course_code'] ?? '');
        $degreeLevel = in_array($data['degree_level'] ?? '', ['undergraduate', 'postgraduate', 'diploma', 'doctorate', 'certificate']) ? $data['degree_level'] : 'undergraduate';
        $hasScholarship = isset($data['has_scholarship']) ? (int)(bool)$data['has_scholarship'] : 1;
        $noScholarshipReason = trim($data['no_scholarship_reason'] ?? '');
        $evaluationMetric = in_array($data['evaluation_metric'] ?? '', ['percentage_12th', 'graduation_cgpa', 'entrance_exam', 'merit_rank']) ? $data['evaluation_metric'] : 'percentage_12th';
        $examName = trim($data['exam_name'] ?? '');
        $annualTuitionFee = !empty($data['annual_tuition_fee']) ? (float)$data['annual_tuition_fee'] : null;
        $currency = trim($data['currency'] ?? 'INR');
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $slabs = is_array($data['slabs'] ?? null) ? $data['slabs'] : [];

        // Validate and sort slabs
        $cleanSlabs = [];
        foreach ($slabs as $s) {
            $min = isset($s['min']) ? (float)$s['min'] : (isset($s['min_score']) ? (float)$s['min_score'] : null);
            $max = isset($s['max']) ? (float)$s['max'] : (isset($s['max_score']) ? (float)$s['max_score'] : 100.0);
            $waiver = isset($s['waiver_pct']) ? (float)$s['waiver_pct'] : (isset($s['waiver_percentage']) ? (float)$s['waiver_percentage'] : (isset($s['waiver']) ? (float)$s['waiver'] : null));
            $label = trim($s['label'] ?? $s['tier_label'] ?? ($waiver !== null ? "{$waiver}% Merit Waiver" : ''));
            if ($min !== null && $waiver !== null) {
                $cleanSlabs[] = [
                    'min' => $min,
                    'max' => $max,
                    'waiver_pct' => $waiver,
                    'label' => $label
                ];
            }
        }

        $db = Database::getConnection();

        try {
            if ($courseId) {
                // Update
                $stmt = $db->prepare("
                    UPDATE course_scholarships
                    SET course_name = :name,
                        course_code = :code,
                        degree_level = :deg,
                        has_scholarship = :has_sch,
                        no_scholarship_reason = :no_reason,
                        evaluation_metric = :metric,
                        exam_name = :exam,
                        slabs = :slabs,
                        annual_tuition_fee = :fee,
                        currency = :curr,
                        is_active = :active,
                        updated_at = NOW()
                    WHERE id = :id AND organization_id = :org_id
                ");
                $stmt->execute([
                    ':name' => $courseName,
                    ':code' => $courseCode,
                    ':deg' => $degreeLevel,
                    ':has_sch' => $hasScholarship,
                    ':no_reason' => $noScholarshipReason ?: null,
                    ':metric' => $evaluationMetric,
                    ':exam' => $examName ?: null,
                    ':slabs' => json_encode($cleanSlabs),
                    ':fee' => $annualTuitionFee,
                    ':curr' => $currency,
                    ':active' => $isActive,
                    ':id' => $courseId,
                    ':org_id' => $orgId
                ]);

                AuditLogger::log('course_scholarship_updated', 'course_scholarships', $courseId, ['course_name' => $courseName]);
                Response::success(['id' => $courseId], 'Course scholarship updated successfully.');
            } else {
                // Insert
                $stmt = $db->prepare("
                    INSERT INTO course_scholarships
                    (organization_id, course_name, course_code, degree_level, has_scholarship, no_scholarship_reason, evaluation_metric, exam_name, slabs, annual_tuition_fee, currency, is_active, created_at, updated_at)
                    VALUES
                    (:org_id, :name, :code, :deg, :has_sch, :no_reason, :metric, :exam, :slabs, :fee, :curr, :active, NOW(), NOW())
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':name' => $courseName,
                    ':code' => $courseCode,
                    ':deg' => $degreeLevel,
                    ':has_sch' => $hasScholarship,
                    ':no_reason' => $noScholarshipReason ?: null,
                    ':metric' => $evaluationMetric,
                    ':exam' => $examName ?: null,
                    ':slabs' => json_encode($cleanSlabs),
                    ':fee' => $annualTuitionFee,
                    ':curr' => $currency,
                    ':active' => $isActive
                ]);
                $newId = (int)$db->lastInsertId();

                AuditLogger::log('course_scholarship_created', 'course_scholarships', $newId, ['course_name' => $courseName]);
                Response::success(['id' => $newId], 'Course scholarship created successfully.', 201);
            }
        } catch (Throwable $e) {
            Response::error('Failed to save course scholarship: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /v1/scholarships/courses/{id} — Delete course scholarship
     */
    public function deleteCourse(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            Response::error('Course ID is required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM course_scholarships WHERE id = ? AND organization_id = ?");
            $stmt->execute([$id, $orgId]);

            AuditLogger::log('course_scholarship_deleted', 'course_scholarships', $id);
            Response::success(null, 'Course scholarship deleted successfully.');
        } catch (Throwable $e) {
            Response::error('Failed to delete course scholarship: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/scholarship-rules — List all scholarship rules with mapped program details
     */
    public function indexRules(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT sr.*,
                       p.course_name as program_name,
                       p.course_code as program_code,
                       p.program_type,
                       p.tuition_fee as program_tuition_fee,
                       p.currency as program_currency
                FROM scholarship_rules sr
                LEFT JOIN programs p ON sr.program_id = p.id AND p.organization_id = sr.organization_id
                WHERE sr.organization_id = ?
                ORDER BY sr.is_active DESC, p.course_name ASC, sr.title ASC
            ");
            $stmt->execute([$orgId]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rules as &$rule) {
                $rule['slabs'] = !empty($rule['slabs']) ? json_decode($rule['slabs'], true) : [];
            }

            Response::success($rules);
        } catch (Throwable $e) {
            Response::error('Failed to fetch scholarship rules: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/scholarship-rules/{id} — Get a single scholarship rule by ID
     */
    public function showRule(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            Response::error('Scholarship rule ID is required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT sr.*,
                       p.course_name as program_name,
                       p.course_code as program_code,
                       p.program_type,
                       p.tuition_fee as program_tuition_fee,
                       p.currency as program_currency
                FROM scholarship_rules sr
                LEFT JOIN programs p ON sr.program_id = p.id AND p.organization_id = sr.organization_id
                WHERE sr.id = ? AND sr.organization_id = ?
            ");
            $stmt->execute([$id, $orgId]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rule) {
                Response::error('Scholarship rule not found', 404);
                return;
            }

            $rule['slabs'] = !empty($rule['slabs']) ? json_decode($rule['slabs'], true) : [];
            Response::success($rule);
        } catch (Throwable $e) {
            Response::error('Failed to fetch scholarship rule: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/scholarship-rules — Create a new scholarship rule mapped to a program
     */
    public function storeRule(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $data = $request->all();
        $title = trim($data['title'] ?? '');
        $programId = (int)($data['program_id'] ?? 0);

        if (empty($title)) {
            Response::error('Scholarship title is required', 422);
            return;
        }

        if (!$programId) {
            Response::error('Program mapping is mandatory (program_id is required)', 422);
            return;
        }

        try {
            $db = Database::getConnection();

            // Verify program belongs to organization
            $stmtProg = $db->prepare("SELECT id, course_name FROM programs WHERE id = ? AND organization_id = ?");
            $stmtProg->execute([$programId, $orgId]);
            $prog = $stmtProg->fetch(PDO::FETCH_ASSOC);
            if (!$prog) {
                Response::error('Selected program was not found in your organization', 404);
                return;
            }

            $code = trim($data['code'] ?? '') ?: null;
            $description = trim($data['description'] ?? '') ?: null;
            $evaluationMetric = trim($data['evaluation_metric'] ?? 'percentage_12th');
            $allowedMetrics = ['percentage_12th', 'graduation_cgpa', 'entrance_exam', 'merit_rank', 'general_merit'];
            if (!in_array($evaluationMetric, $allowedMetrics)) {
                $evaluationMetric = 'percentage_12th';
            }

            $examName = trim($data['exam_name'] ?? '') ?: null;
            $discountType = ($data['discount_type'] ?? 'percentage') === 'fixed_amount' ? 'fixed_amount' : 'percentage';
            $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : 0.00;

            $slabs = is_array($data['slabs'] ?? null) ? $data['slabs'] : [];
            $cleanSlabs = [];
            foreach ($slabs as $s) {
                if (!is_array($s)) continue;
                $min = isset($s['min']) ? (float)$s['min'] : 0;
                $max = isset($s['max']) ? (float)$s['max'] : 100;
                $waiver = isset($s['waiver_pct']) ? (float)$s['waiver_pct'] : (isset($s['waiver_percentage']) ? (float)$s['waiver_percentage'] : (float)($s['waiver'] ?? 0));
                $label = trim($s['label'] ?? "{$waiver}% Waiver");
                $cleanSlabs[] = [
                    'min' => $min,
                    'max' => $max,
                    'waiver_pct' => $waiver,
                    'label' => $label
                ];
            }

            $eligibilityCriteria = trim($data['eligibility_criteria'] ?? '') ?: null;
            $termsConditions = trim($data['terms_conditions'] ?? '') ?: null;
            $maxRecipients = !empty($data['max_recipients']) ? (int)$data['max_recipients'] : null;
            $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

            $stmt = $db->prepare("
                INSERT INTO scholarship_rules
                (organization_id, program_id, title, code, description, evaluation_metric, exam_name, discount_type, discount_value, slabs, eligibility_criteria, terms_conditions, max_recipients, is_active, created_at, updated_at)
                VALUES
                (:org_id, :prog_id, :title, :code, :desc, :metric, :exam, :disc_type, :disc_val, :slabs, :elig, :terms, :max_rec, :active, NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':prog_id' => $programId,
                ':title' => $title,
                ':code' => $code,
                ':desc' => $description,
                ':metric' => $evaluationMetric,
                ':exam' => $examName,
                ':disc_type' => $discountType,
                ':disc_val' => $discountValue,
                ':slabs' => json_encode($cleanSlabs),
                ':elig' => $eligibilityCriteria,
                ':terms' => $termsConditions,
                ':max_rec' => $maxRecipients,
                ':active' => $isActive
            ]);

            $newId = (int)$db->lastInsertId();
            AuditLogger::log('scholarship_rule_created', 'scholarship_rules', $newId, ['title' => $title, 'program_id' => $programId]);

            Response::success(['id' => $newId], 'Scholarship rule created successfully.', 201);
        } catch (Throwable $e) {
            Response::error('Failed to create scholarship rule: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /v1/scholarship-rules/{id} — Update an existing scholarship rule
     */
    public function updateRule(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            Response::error('Scholarship rule ID is required', 400);
            return;
        }

        $data = $request->all();
        $title = trim($data['title'] ?? '');
        $programId = (int)($data['program_id'] ?? 0);

        if (empty($title)) {
            Response::error('Scholarship title is required', 422);
            return;
        }

        if (!$programId) {
            Response::error('Program mapping is mandatory (program_id is required)', 422);
            return;
        }

        try {
            $db = Database::getConnection();

            // Verify rule exists
            $stmtCheck = $db->prepare("SELECT id FROM scholarship_rules WHERE id = ? AND organization_id = ?");
            $stmtCheck->execute([$id, $orgId]);
            if (!$stmtCheck->fetch()) {
                Response::error('Scholarship rule not found', 404);
                return;
            }

            // Verify program belongs to organization
            $stmtProg = $db->prepare("SELECT id, course_name FROM programs WHERE id = ? AND organization_id = ?");
            $stmtProg->execute([$programId, $orgId]);
            if (!$stmtProg->fetch()) {
                Response::error('Selected program was not found in your organization', 404);
                return;
            }

            $code = trim($data['code'] ?? '') ?: null;
            $description = trim($data['description'] ?? '') ?: null;
            $evaluationMetric = trim($data['evaluation_metric'] ?? 'percentage_12th');
            $allowedMetrics = ['percentage_12th', 'graduation_cgpa', 'entrance_exam', 'merit_rank', 'general_merit'];
            if (!in_array($evaluationMetric, $allowedMetrics)) {
                $evaluationMetric = 'percentage_12th';
            }

            $examName = trim($data['exam_name'] ?? '') ?: null;
            $discountType = ($data['discount_type'] ?? 'percentage') === 'fixed_amount' ? 'fixed_amount' : 'percentage';
            $discountValue = isset($data['discount_value']) ? (float)$data['discount_value'] : 0.00;

            $slabs = is_array($data['slabs'] ?? null) ? $data['slabs'] : [];
            $cleanSlabs = [];
            foreach ($slabs as $s) {
                if (!is_array($s)) continue;
                $min = isset($s['min']) ? (float)$s['min'] : 0;
                $max = isset($s['max']) ? (float)$s['max'] : 100;
                $waiver = isset($s['waiver_pct']) ? (float)$s['waiver_pct'] : (isset($s['waiver_percentage']) ? (float)$s['waiver_percentage'] : (float)($s['waiver'] ?? 0));
                $label = trim($s['label'] ?? "{$waiver}% Waiver");
                $cleanSlabs[] = [
                    'min' => $min,
                    'max' => $max,
                    'waiver_pct' => $waiver,
                    'label' => $label
                ];
            }

            $eligibilityCriteria = trim($data['eligibility_criteria'] ?? '') ?: null;
            $termsConditions = trim($data['terms_conditions'] ?? '') ?: null;
            $maxRecipients = !empty($data['max_recipients']) ? (int)$data['max_recipients'] : null;
            $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

            $stmt = $db->prepare("
                UPDATE scholarship_rules
                SET program_id = :prog_id,
                    title = :title,
                    code = :code,
                    description = :desc,
                    evaluation_metric = :metric,
                    exam_name = :exam,
                    discount_type = :disc_type,
                    discount_value = :disc_val,
                    slabs = :slabs,
                    eligibility_criteria = :elig,
                    terms_conditions = :terms,
                    max_recipients = :max_rec,
                    is_active = :active,
                    updated_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([
                ':prog_id' => $programId,
                ':title' => $title,
                ':code' => $code,
                ':desc' => $description,
                ':metric' => $evaluationMetric,
                ':exam' => $examName,
                ':disc_type' => $discountType,
                ':disc_val' => $discountValue,
                ':slabs' => json_encode($cleanSlabs),
                ':elig' => $eligibilityCriteria,
                ':terms' => $termsConditions,
                ':max_rec' => $maxRecipients,
                ':active' => $isActive,
                ':id' => $id,
                ':org_id' => $orgId
            ]);

            AuditLogger::log('scholarship_rule_updated', 'scholarship_rules', $id, ['title' => $title, 'program_id' => $programId]);
            Response::success(['id' => $id], 'Scholarship rule updated successfully.');
        } catch (Throwable $e) {
            Response::error('Failed to update scholarship rule: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /v1/scholarship-rules/{id} — Delete scholarship rule
     */
    public function deleteRule(Request $request, array $params = []): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            Response::error('Scholarship rule ID is required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM scholarship_rules WHERE id = ? AND organization_id = ?");
            $stmt->execute([$id, $orgId]);

            AuditLogger::log('scholarship_rule_deleted', 'scholarship_rules', $id);
            Response::success(null, 'Scholarship rule deleted successfully.');
        } catch (Throwable $e) {
            Response::error('Failed to delete scholarship rule: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /v1/widget/scholarship/courses/{bot_token} — Public widget endpoint to fetch active course list
     */
    public function publicCourses(Request $request, array $params = []): void
    {
        $botToken = trim($params['bot_token'] ?? '');
        $deptId = $request->get('dept_id') ? (int)$request->get('dept_id') : null;

        if (empty($botToken)) {
            Response::error('Bot token is required', 400);
            return;
        }

        $db = Database::getConnection();
        $stmtBot = $db->prepare("SELECT id, organization_id, is_active FROM chatbots WHERE bot_token = :token AND is_active = 1");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Chatbot not found or inactive', 404);
            return;
        }

        $orgId = (int)$bot['organization_id'];

        // Check Org global scholarship setting
        $stmtOrg = $db->prepare("SELECT scholarship_config FROM organizations WHERE id = ?");
        $stmtOrg->execute([$orgId]);
        $orgRow = $stmtOrg->fetch();
        $orgConfig = $orgRow && !empty($orgRow['scholarship_config']) ? json_decode($orgRow['scholarship_config'], true) : ['enabled' => true];

        if (isset($orgConfig['enabled']) && !$orgConfig['enabled']) {
            Response::success([
                'scholarships_enabled' => false,
                'courses' => [],
                'boosters' => []
            ]);
            return;
        }

        // Query active courses from programs and map scholarship rule info
        $sql = "
            SELECT 
                p.id,
                p.course_name,
                p.course_code,
                p.program_type as degree_level,
                CASE WHEN sr.id IS NOT NULL THEN 1 ELSE 0 END as has_scholarship,
                NULL as no_scholarship_reason,
                COALESCE(sr.evaluation_metric, 'percentage_12th') as evaluation_metric,
                sr.exam_name,
                p.tuition_fee as annual_tuition_fee,
                p.currency
            FROM programs p
            LEFT JOIN scholarship_rules sr ON sr.program_id = p.id AND sr.organization_id = p.organization_id AND sr.is_active = 1
            WHERE p.organization_id = :org_id AND p.is_admissions_open = 1
            ORDER BY p.program_type ASC, p.course_name ASC
        ";
        $stmtCourses = $db->prepare($sql);
        $stmtCourses->execute([':org_id' => $orgId]);
        $courses = $stmtCourses->fetchAll();

        // Fallback to legacy course_scholarships if programs table has no records for this org
        if (empty($courses)) {
            $sqlLegacy = "
                SELECT cs.id, cs.course_name, cs.course_code, cs.degree_level, cs.has_scholarship,
                       cs.no_scholarship_reason, cs.evaluation_metric, cs.exam_name, cs.annual_tuition_fee, cs.currency
                FROM course_scholarships cs
                WHERE cs.organization_id = :org_id AND cs.is_active = 1
                ORDER BY cs.degree_level ASC, cs.course_name ASC
            ";
            $stmtLegacy = $db->prepare($sqlLegacy);
            $stmtLegacy->execute([':org_id' => $orgId]);
            $courses = $stmtLegacy->fetchAll();
        }

        // Boosters config
        $boosters = [
            ['id' => 'sports', 'label' => '🏅 Sports / State / National Quota', 'pct' => (float)($orgConfig['sports_pct'] ?? 5)],
            ['id' => 'girl_child', 'label' => '👧 Girl Child / Single Girl Quota', 'pct' => (float)($orgConfig['girl_child_pct'] ?? 5)],
            ['id' => 'defense', 'label' => '🎖️ Defense / Armed Forces Ward', 'pct' => (float)($orgConfig['defense_pct'] ?? 5)],
            ['id' => 'early_bird', 'label' => '⚡ Early Bird Merit Applicant', 'pct' => (float)($orgConfig['early_bird_pct'] ?? 5)]
        ];

        Response::success([
            'scholarships_enabled' => true,
            'courses' => $courses,
            'boosters' => $boosters,
            'headline' => $orgConfig['headline'] ?? 'Check Your Scholarship Eligibility',
            'subheadline' => $orgConfig['subheadline'] ?? 'Evaluate tuition waiver in 30 seconds'
        ]);
    }

    /**
     * POST /v1/widget/scholarship/evaluate — Public widget endpoint to evaluate eligibility
     */
    public function evaluate(Request $request): void
    {
        $botToken = trim((string)($request->getBotToken() ?: $request->get('bot_token') ?: $request->get('bot')));
        $courseId = (int)($request->get('course_id') ?: $request->get('courseId'));
        $score = (float)$request->get('score');
        $selectedBoosters = is_array($request->get('boosters')) ? $request->get('boosters') : (is_array($request->get('selected_boosters')) ? $request->get('selected_boosters') : []);

        if (empty($botToken) || empty($courseId)) {
            Response::error('bot_token and course_id are required.', 422);
            return;
        }

        $db = Database::getConnection();
        $stmtBot = $db->prepare("SELECT organization_id FROM chatbots WHERE bot_token = :token AND is_active = 1");
        $stmtBot->execute([':token' => $botToken]);
        $bot = $stmtBot->fetch();

        if (!$bot) {
            Response::error('Invalid or inactive chatbot.', 403);
            return;
        }

        $orgId = (int)$bot['organization_id'];

        // Fetch Org Config
        $stmtOrg = $db->prepare("SELECT scholarship_config FROM organizations WHERE id = ?");
        $stmtOrg->execute([$orgId]);
        $orgRow = $stmtOrg->fetch();
        $orgConfig = $orgRow && !empty($orgRow['scholarship_config']) ? json_decode($orgRow['scholarship_config'], true) : [];

        // Fetch Program & Scholarship Rule from authoritative tables (programs & scholarship_rules)
        $stmtProg = $db->prepare("
            SELECT 
                p.id as program_id,
                p.course_name,
                p.course_code,
                p.program_type as degree_level,
                p.tuition_fee as annual_tuition_fee,
                p.currency,
                sr.id as rule_id,
                sr.title as rule_title,
                sr.evaluation_metric,
                sr.exam_name,
                sr.discount_type,
                sr.discount_value,
                sr.slabs,
                sr.is_active as rule_is_active
            FROM programs p
            LEFT JOIN scholarship_rules sr ON sr.program_id = p.id AND sr.organization_id = p.organization_id AND sr.is_active = 1
            WHERE (p.id = :course_id OR sr.id = :course_id) AND p.organization_id = :org_id
            LIMIT 1
        ");
        $stmtProg->execute([':course_id' => $courseId, ':org_id' => $orgId]);
        $progRow = $stmtProg->fetch();

        $courseName = '';
        $degreeLevel = 'undergraduate';
        $annualFee = null;
        $currency = 'USD';
        $hasScholarship = false;
        $noScholarshipReason = '';
        $evaluationMetric = 'percentage_12th';
        $examName = null;
        $slabs = [];

        if ($progRow) {
            $courseName = $progRow['course_name'];
            $degreeLevel = $progRow['degree_level'] ?: 'undergraduate';
            $annualFee = $progRow['annual_tuition_fee'] ? (float)$progRow['annual_tuition_fee'] : null;
            $currency = $progRow['currency'] ?: 'USD';
            $hasScholarship = !empty($progRow['rule_id']) && (int)$progRow['rule_is_active'] === 1;
            $evaluationMetric = $progRow['evaluation_metric'] ?: 'percentage_12th';
            $examName = $progRow['exam_name'] ?: null;
            $slabs = !empty($progRow['slabs']) ? json_decode($progRow['slabs'], true) : [];
        } else {
            // Fallback to legacy course_scholarships table
            $stmtCourse = $db->prepare("SELECT * FROM course_scholarships WHERE id = ? AND organization_id = ? AND is_active = 1");
            $stmtCourse->execute([$courseId, $orgId]);
            $legacyCourse = $stmtCourse->fetch();

            if (!$legacyCourse) {
                Response::error('Course not found or inactive.', 404);
                return;
            }

            $courseName = $legacyCourse['course_name'];
            $degreeLevel = $legacyCourse['degree_level'];
            $annualFee = $legacyCourse['annual_tuition_fee'] ? (float)$legacyCourse['annual_tuition_fee'] : null;
            $currency = $legacyCourse['currency'] ?? 'INR';
            $hasScholarship = (bool)$legacyCourse['has_scholarship'];
            $noScholarshipReason = $legacyCourse['no_scholarship_reason'] ?? '';
            $evaluationMetric = $legacyCourse['evaluation_metric'] ?? 'percentage_12th';
            $examName = $legacyCourse['exam_name'] ?? null;
            $slabs = !empty($legacyCourse['slabs']) ? json_decode($legacyCourse['slabs'], true) : [];
        }

        // If course does not offer scholarship
        if (!$hasScholarship) {
            Response::success([
                'has_scholarship' => false,
                'course_name' => $courseName,
                'reason' => $noScholarshipReason ?: 'This specialized program follows a standard subsidized tuition fee structure.',
                'headline' => 'Standard Fee Structure & Financial Support',
                'subheadline' => 'Direct merit waivers are not applicable for this course, but flexible financial support is available:',
                'financial_options' => [
                    '💳 0% Interest Monthly Installment / EMI options',
                    '🏦 Institutional Education Loan Tie-ups with quick sanction',
                    '🤝 Need-Based Financial Aid & Work-Study Programs'
                ],
                'annual_fee' => $annualFee,
                'currency' => $currency
            ]);
            return;
        }

        // Calculate Academic Slab Match
        $matchedSlab = null;

        // Sort slabs descending by min threshold
        if (is_array($slabs)) {
            usort($slabs, fn($a, $b) => ($b['min'] ?? 0) <=> ($a['min'] ?? 0));

            foreach ($slabs as $slab) {
                $min = (float)($slab['min'] ?? 0);
                $max = isset($slab['max']) ? (float)$slab['max'] : 100.0;
                if ($score >= $min && $score <= $max) {
                    $matchedSlab = $slab;
                    break;
                }
            }
        }

        $baseWaiverPct = $matchedSlab ? (float)$matchedSlab['waiver_pct'] : 0.0;
        $tierLabel = $matchedSlab ? ($matchedSlab['label'] ?? "{$baseWaiverPct}% Merit Scholarship") : 'General Admission Tier';

        // Calculate Booster Add-ons
        $boosterWaiverPct = 0.0;
        $activeBoosterNames = [];
        $boosterRates = [
            'sports' => (float)($orgConfig['sports_pct'] ?? 5),
            'girl_child' => (float)($orgConfig['girl_child_pct'] ?? 5),
            'defense' => (float)($orgConfig['defense_pct'] ?? 5),
            'early_bird' => (float)($orgConfig['early_bird_pct'] ?? 5)
        ];

        foreach ($selectedBoosters as $bKey) {
            if (isset($boosterRates[$bKey])) {
                $boosterWaiverPct += $boosterRates[$bKey];
                $activeBoosterNames[] = ucfirst(str_replace('_', ' ', $bKey));
            }
        }

        $maxAllowed = (float)($orgConfig['max_total_waiver_pct'] ?? 100.0);
        $totalWaiverPct = min($maxAllowed, $baseWaiverPct + $boosterWaiverPct);

        $estimatedSavings = ($annualFee && $totalWaiverPct > 0) ? round(($annualFee * ($totalWaiverPct / 100)), 2) : null;
        $effectiveFee = ($annualFee && $estimatedSavings) ? ($annualFee - $estimatedSavings) : null;

        Response::success([
            'has_scholarship' => true,
            'is_qualified' => $totalWaiverPct > 0,
            'course_name' => $courseName,
            'degree_level' => $degreeLevel,
            'score_entered' => $score,
            'metric_type' => $evaluationMetric,
            'exam_name' => $examName,
            'base_waiver_pct' => $baseWaiverPct,
            'booster_waiver_pct' => $boosterWaiverPct,
            'total_waiver_pct' => $totalWaiverPct,
            'tier_label' => $totalWaiverPct > 0 ? "🎉 Qualified for {$totalWaiverPct}% Tuition Waiver ({$tierLabel})" : "Standard Merit Bracket",
            'annual_fee' => $annualFee,
            'estimated_savings' => $estimatedSavings,
            'effective_fee' => $effectiveFee,
            'currency' => $currency,
            'active_boosters' => $activeBoosterNames
        ]);
    }
}
