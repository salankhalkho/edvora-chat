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

            // Fetch Departments with their specific overrides
            $stmtDepts = $db->prepare("
                SELECT id, name, slug, icon, scholarship_config
                FROM departments
                WHERE organization_id = ? AND is_active = 1
                ORDER BY name ASC
            ");
            $stmtDepts->execute([$orgId]);
            $depts = $stmtDepts->fetchAll();

            foreach ($depts as &$d) {
                $d['scholarship_config'] = !empty($d['scholarship_config']) ? json_decode($d['scholarship_config'], true) : ['mode' => 'inherit'];
            }

            // Fetch all course scholarships
            $stmtCourses = $db->prepare("
                SELECT cs.*, d.name as department_name, d.icon as department_icon
                FROM course_scholarships cs
                LEFT JOIN departments d ON cs.department_id = d.id
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
            if (isset($data['department_id']) && $data['department_id'] > 0) {
                // Update Department override
                $deptId = (int)$data['department_id'];
                $deptConfig = $data['config'] ?? ['mode' => 'inherit'];

                $stmt = $db->prepare("UPDATE departments SET scholarship_config = ? WHERE id = ? AND organization_id = ?");
                $stmt->execute([json_encode($deptConfig), $deptId, $orgId]);

                AuditLogger::log('department_scholarship_updated', 'department', $deptId, $deptConfig);
                Response::success(['department_id' => $deptId, 'config' => $deptConfig], 'Department scholarship settings updated.');
            } else {
                // Update Org global config
                $orgConfig = $data['config'] ?? $data;
                $stmt = $db->prepare("UPDATE organizations SET scholarship_config = ? WHERE id = ?");
                $stmt->execute([json_encode($orgConfig), $orgId]);

                AuditLogger::log('organization_scholarship_updated', 'organization', $orgId, $orgConfig);
                Response::success(['config' => $orgConfig], 'Organization scholarship settings updated.');
            }
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
        $deptId = !empty($data['department_id']) ? (int)$data['department_id'] : null;
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
                    SET department_id = :dept_id,
                        course_name = :name,
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
                    ':dept_id' => $deptId,
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
                    (organization_id, department_id, course_name, course_code, degree_level, has_scholarship, no_scholarship_reason, evaluation_metric, exam_name, slabs, annual_tuition_fee, currency, is_active, created_at, updated_at)
                    VALUES
                    (:org_id, :dept_id, :name, :code, :deg, :has_sch, :no_reason, :metric, :exam, :slabs, :fee, :curr, :active, NOW(), NOW())
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':dept_id' => $deptId,
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

        // Check Dept setting if scoped
        if ($deptId) {
            $stmtDept = $db->prepare("SELECT scholarship_config FROM departments WHERE id = ? AND organization_id = ?");
            $stmtDept->execute([$deptId, $orgId]);
            $deptRow = $stmtDept->fetch();
            $deptConfig = $deptRow && !empty($deptRow['scholarship_config']) ? json_decode($deptRow['scholarship_config'], true) : ['mode' => 'inherit'];
            if (($deptConfig['mode'] ?? 'inherit') === 'disabled') {
                Response::success([
                    'scholarships_enabled' => false,
                    'courses' => [],
                    'boosters' => []
                ]);
                return;
            }
        }

        // Query active courses
        $sql = "
            SELECT cs.id, cs.course_name, cs.course_code, cs.degree_level, cs.has_scholarship,
                   cs.no_scholarship_reason, cs.evaluation_metric, cs.exam_name, cs.annual_tuition_fee, cs.currency,
                   d.name as department_name, d.icon as department_icon
            FROM course_scholarships cs
            LEFT JOIN departments d ON cs.department_id = d.id
            WHERE cs.organization_id = :org_id AND cs.is_active = 1
        ";
        $params = [':org_id' => $orgId];

        if ($deptId) {
            $sql .= " AND (cs.department_id = :dept_id OR cs.department_id IS NULL)";
            $params[':dept_id'] = $deptId;
        }

        $sql .= " ORDER BY cs.degree_level ASC, cs.course_name ASC";
        $stmtCourses = $db->prepare($sql);
        $stmtCourses->execute($params);
        $courses = $stmtCourses->fetchAll();

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

        // Fetch Course Scholarship Rule
        $stmtCourse = $db->prepare("SELECT * FROM course_scholarships WHERE id = ? AND organization_id = ? AND is_active = 1");
        $stmtCourse->execute([$courseId, $orgId]);
        $course = $stmtCourse->fetch();

        if (!$course) {
            Response::error('Course not found or inactive.', 404);
            return;
        }

        // If course does not offer scholarship
        if (!(bool)$course['has_scholarship']) {
            Response::success([
                'has_scholarship' => false,
                'course_name' => $course['course_name'],
                'reason' => $course['no_scholarship_reason'] ?: 'This specialized program follows a standard subsidized tuition fee structure.',
                'headline' => 'Standard Fee Structure & Financial Support',
                'subheadline' => 'Direct merit waivers are not applicable for this course, but flexible financial support is available:',
                'financial_options' => [
                    '💳 0% Interest Monthly Installment / EMI options',
                    '🏦 Institutional Education Loan Tie-ups with quick sanction',
                    '🤝 Need-Based Financial Aid & Work-Study Programs'
                ],
                'annual_fee' => $course['annual_tuition_fee'] ? (float)$course['annual_tuition_fee'] : null,
                'currency' => $course['currency'] ?? 'INR'
            ]);
            return;
        }

        // Calculate Academic Slab Match
        $slabs = !empty($course['slabs']) ? json_decode($course['slabs'], true) : [];
        $matchedSlab = null;

        // Sort slabs descending by min threshold
        usort($slabs, fn($a, $b) => ($b['min'] ?? 0) <=> ($a['min'] ?? 0));

        foreach ($slabs as $slab) {
            $min = (float)($slab['min'] ?? 0);
            $max = isset($slab['max']) ? (float)$slab['max'] : 100.0;
            if ($score >= $min && $score <= $max) {
                $matchedSlab = $slab;
                break;
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

        $annualFee = $course['annual_tuition_fee'] ? (float)$course['annual_tuition_fee'] : null;
        $estimatedSavings = ($annualFee && $totalWaiverPct > 0) ? round(($annualFee * ($totalWaiverPct / 100)), 2) : null;
        $effectiveFee = ($annualFee && $estimatedSavings) ? ($annualFee - $estimatedSavings) : null;

        Response::success([
            'has_scholarship' => true,
            'is_qualified' => $totalWaiverPct > 0,
            'course_name' => $course['course_name'],
            'degree_level' => $course['degree_level'],
            'score_entered' => $score,
            'metric_type' => $course['evaluation_metric'],
            'exam_name' => $course['exam_name'],
            'base_waiver_pct' => $baseWaiverPct,
            'booster_waiver_pct' => $boosterWaiverPct,
            'total_waiver_pct' => $totalWaiverPct,
            'tier_label' => $totalWaiverPct > 0 ? "🎉 Qualified for {$totalWaiverPct}% Tuition Waiver ({$tierLabel})" : "Standard Merit Bracket",
            'annual_fee' => $annualFee,
            'estimated_savings' => $estimatedSavings,
            'effective_fee' => $effectiveFee,
            'currency' => $course['currency'] ?? 'INR',
            'active_boosters' => $activeBoosterNames
        ]);
    }
}
