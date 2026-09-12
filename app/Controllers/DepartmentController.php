<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Database\DepartmentPresets;
use PDO;
use Throwable;

class DepartmentController
{
    private function getOrgId(Request $request): ?int
    {
        $orgId = $GLOBALS['organization_id'] ?? $GLOBALS['auth_user']['organization_id'] ?? $request->get('organization_id');
        return $orgId ? (int)$orgId : null;
    }

    private function parseWindowDateCondition(Request $request): array
    {
        $window = strtolower(trim((string)($request->get('window') ?? $request->get('time_window') ?? '30d')));
        $startDate = trim((string)($request->get('start_date') ?? ''));
        $endDate = trim((string)($request->get('end_date') ?? ''));

        $sqlWhereDate = '';
        $dateParams = [];
        $windowLabel = 'Last 30 Days';

        if (!empty($startDate) && !empty($endDate)) {
            $window = 'custom';
            $sqlWhereDate = ' AND created_at >= :start_date AND created_at <= :end_date';
            $dateParams[':start_date'] = $startDate . ' 00:00:00';
            $dateParams[':end_date'] = $endDate . ' 23:59:59';
            $windowLabel = date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate));
        } else {
            switch ($window) {
                case '24h':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)';
                    $windowLabel = 'Last 24 Hours';
                    break;
                case '7d':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
                    $windowLabel = 'Last 7 Days';
                    break;
                case '3m':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
                    $windowLabel = 'Last 3 Months';
                    break;
                case '6m':
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)';
                    $windowLabel = 'Last 6 Months';
                    break;
                case 'all':
                    $sqlWhereDate = '';
                    $windowLabel = 'All Time';
                    break;
                case '30d':
                default:
                    $window = '30d';
                    $sqlWhereDate = ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
                    $windowLabel = 'Last 30 Days';
                    break;
            }
        }

        return [$window, $sqlWhereDate, $dateParams, $windowLabel];
    }

    /**
     * List all departments for the authenticated organization.
     */
    public function index(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        try {
            $db = Database::getConnection();

            $authUserId = $GLOBALS['auth_user']['user_id'] ?? null;

            // Check if performing user is Admin or Staff
            $stmtUser = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmtUser->execute([$authUserId]);
            $user = $stmtUser->fetch();
            $isAdmin = $user && ($user['role'] === 'owner' || $user['role'] === 'superadmin' || $user['role'] === 'admin');

            [$window, $sqlWhereDate, $dateParams, $windowLabel] = $this->parseWindowDateCondition($request);

            if ($isAdmin) {
                $stmt = $db->prepare("
                    SELECT id, name, slug, icon, description, email, phone, whatsapp,
                           working_hours, timezone, auto_away_message, escalation_rules,
                           lead_assignment_rules, greeting_message, is_active, enable_dedicated_widget, is_preset,
                           created_at, updated_at
                    FROM departments
                    WHERE organization_id = ?
                    ORDER BY is_preset DESC, name ASC
                ");
                $stmt->execute([$orgId]);
            } else {
                $stmt = $db->prepare("
                    SELECT DISTINCT d.id, d.name, d.slug, d.icon, d.description, d.email, d.phone, d.whatsapp,
                           d.working_hours, d.timezone, d.auto_away_message, d.escalation_rules,
                           d.lead_assignment_rules, d.greeting_message, d.is_active, d.enable_dedicated_widget, d.is_preset,
                           d.created_at, d.updated_at
                    FROM departments d
                    JOIN department_staff ds ON d.id = ds.department_id
                    WHERE d.organization_id = ? AND ds.user_id = ?
                    ORDER BY d.is_preset DESC, d.name ASC
                ");
                $stmt->execute([$orgId, $authUserId]);
            }
            $departments = $stmt->fetchAll();

            foreach ($departments as &$dept) {
                $deptId = (int)$dept['id'];
                $dept['working_hours'] = json_decode($dept['working_hours'] ?? '{}', true);
                $dept['escalation_rules'] = json_decode($dept['escalation_rules'] ?? '{}', true);
                $dept['lead_assignment_rules'] = json_decode($dept['lead_assignment_rules'] ?? '{}', true);

                // Fetch assigned staff
                $stmtStaff = $db->prepare("
                    SELECT ds.user_id, ds.role, ds.is_on_duty, u.name, u.email
                    FROM department_staff ds
                    JOIN users u ON ds.user_id = u.id
                    WHERE ds.department_id = ?
                ");
                $stmtStaff->execute([$deptId]);
                $dept['staff'] = $stmtStaff->fetchAll();

                // Fetch linked knowledge sources
                $stmtKnowledge = $db->prepare("
                    SELECT ks.id, ks.title, ks.type, ks.status
                    FROM department_knowledge dk
                    JOIN knowledge_sources ks ON dk.knowledge_source_id = ks.id
                    WHERE dk.department_id = ?
                ");
                $stmtKnowledge->execute([$deptId]);
                $dept['knowledge_sources'] = $stmtKnowledge->fetchAll();

                // Fetch FAQs
                $stmtFaqs = $db->prepare("
                    SELECT id, question, answer, keywords, sort_order
                    FROM department_faqs
                    WHERE department_id = ?
                    ORDER BY sort_order ASC
                ");
                $stmtFaqs->execute([$deptId]);
                $faqs = $stmtFaqs->fetchAll();
                foreach ($faqs as &$faq) {
                    $faq['keywords'] = json_decode($faq['keywords'] ?? '[]', true);
                }
                $dept['faqs'] = $faqs;

                // Fetch Department Courses with their mapped Campuses
                $stmtCourses = $db->prepare("
                    SELECT id, course_name, course_code, program_type, duration, mode, 
                           is_admissions_open, tuition_fee, registration_fee, total_fee, 
                           currency, eligibility, application_deadline, application_url, sort_order
                    FROM department_courses
                    WHERE department_id = ?
                    ORDER BY sort_order ASC, id ASC
                ");
                $stmtCourses->execute([$deptId]);
                $courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($courses as &$crs) {
                    $crsId = (int)$crs['id'];
                    $stmtCmp = $db->prepare("
                        SELECT c.id, c.name, c.short_name, c.is_primary
                        FROM campuses c
                        JOIN campus_courses cc ON c.id = cc.campus_id
                        WHERE cc.course_id = ? AND c.organization_id = ? AND c.status = 'active'
                        ORDER BY c.is_primary DESC, c.name ASC
                    ");
                    $stmtCmp->execute([$crsId, $orgId]);
                    $crsCampuses = $stmtCmp->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $crs['id'] = $crsId;
                    $crs['campuses'] = $crsCampuses;
                    $crs['campus_ids'] = array_map('intval', array_column($crsCampuses, 'id'));
                }
                $dept['courses'] = $courses;

                // Accurate database metric counts filtered by time window
                $stmtLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE department_id = :dept_id {$sqlWhereDate}");
                $stmtLeads->execute(array_merge([':dept_id' => $deptId], $dateParams));
                $dept['leads_count'] = (int)$stmtLeads->fetchColumn();

                $stmtCb = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE department_id = :dept_id {$sqlWhereDate}");
                $stmtCb->execute(array_merge([':dept_id' => $deptId], $dateParams));
                $dept['callbacks_count'] = (int)$stmtCb->fetchColumn();

                $stmtCt = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE department_id = :dept_id {$sqlWhereDate}");
                $stmtCt->execute(array_merge([':dept_id' => $deptId], $dateParams));
                $dept['campus_tours_count'] = (int)$stmtCt->fetchColumn();

                $stmtSch = $db->prepare("SELECT COUNT(*) FROM leads WHERE department_id = :dept_id AND scholarship_tier IS NOT NULL {$sqlWhereDate}");
                $stmtSch->execute(array_merge([':dept_id' => $deptId], $dateParams));
                $dept['scholarships_count'] = (int)$stmtSch->fetchColumn();

                $stmtAsset = $db->prepare("SELECT COALESCE(SUM(downloads_count), 0) FROM lead_assets WHERE department_id = :dept_id {$sqlWhereDate}");
                $stmtAsset->execute(array_merge([':dept_id' => $deptId], $dateParams));
                $dept['lead_assets_downloads'] = (int)$stmtAsset->fetchColumn();
            }

            // Also fetch available org staff, knowledge sources, and campuses for UI select dropdowns
            $stmtOrgStaff = $db->prepare("SELECT id, name, email, role FROM users WHERE organization_id = ?");
            $stmtOrgStaff->execute([$orgId]);
            $orgStaff = $stmtOrgStaff->fetchAll();

            $stmtOrgKs = $db->prepare("SELECT id, title, type, status FROM knowledge_sources WHERE organization_id = ? AND status = 'active'");
            $stmtOrgKs->execute([$orgId]);
            $orgKnowledge = $stmtOrgKs->fetchAll();

            $stmtOrgCampuses = $db->prepare("SELECT id, name, short_name, is_primary FROM campuses WHERE organization_id = ? AND status = 'active' ORDER BY is_primary DESC, name ASC");
            $stmtOrgCampuses->execute([$orgId]);
            $orgCampuses = $stmtOrgCampuses->fetchAll(PDO::FETCH_ASSOC) ?: [];

            Response::success([
                'departments' => $departments,
                'available_staff' => $orgStaff,
                'available_knowledge_sources' => $orgKnowledge,
                'available_campuses' => $orgCampuses,
                'presets_count' => count(DepartmentPresets::getPresets())
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve departments: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Return list of pre-built preset departments for catalog modal.
     */
    public function presets(Request $request): void
    {
        Response::success([
            'presets' => DepartmentPresets::getPresets()
        ]);
    }

    /**
     * One-click bulk import of preset departments.
     */
    public function importPresets(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $body = $request->all();
        $selectedSlugs = $body['selected_slugs'] ?? [];

        try {
            $db = Database::getConnection();
            $result = DepartmentPresets::seedPresetsForOrganization($db, $orgId, $selectedSlugs);

            Response::success([
                'imported_count' => $result['imported_count'],
                'department_id' => $result['department_id'],
                'message' => "Successfully imported preset department."
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to import presets: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create custom department.
     */
    public function create(Request $request): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }
        $body = $request->all();

        $name = trim($body['name'] ?? '');
        if (empty($name)) {
            Response::error('Department name is required', 422);
            return;
        }

        $slug = trim($body['slug'] ?? '');
        if (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        }

        $icon = $body['icon'] ?? '🏫';
        $description = $body['description'] ?? '';
        $email = $body['email'] ?? null;
        $phone = $body['phone'] ?? null;
        $whatsapp = $body['whatsapp'] ?? null;
        $greetingMessage = $body['greeting_message'] ?? "I can help with {$name}. How may I assist you today?";
        $isActive = isset($body['is_active']) ? ($body['is_active'] ? 1 : 0) : 0;
        $enableDedicatedWidget = isset($body['enable_dedicated_widget']) ? ($body['enable_dedicated_widget'] ? 1 : 0) : 0;
        $timezone = !empty($body['timezone']) ? trim($body['timezone']) : 'America/New_York';
        $autoAwayMessage = isset($body['auto_away_message']) ? trim($body['auto_away_message']) : null;
        $workingHours = json_encode($body['working_hours'] ?? []);
        $escalationRules = json_encode($body['escalation_rules'] ?? ['max_unresolved_turns' => 2, 'notify_email' => true]);
        $leadAssignmentRules = json_encode($body['lead_assignment_rules'] ?? ['method' => 'round_robin']);

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO departments (
                    organization_id, name, slug, icon, description, email, phone, whatsapp,
                    working_hours, timezone, auto_away_message, greeting_message, escalation_rules, lead_assignment_rules, is_active, enable_dedicated_widget, is_preset
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $orgId, $name, $slug, $icon, $description, $email, $phone, $whatsapp,
                $workingHours, $timezone, $autoAwayMessage, $greetingMessage, $escalationRules, $leadAssignmentRules, $isActive, $enableDedicatedWidget
            ]);

            $newId = (int)$db->lastInsertId();

            Response::success([
                'id' => $newId,
                'message' => 'Department created successfully'
            ], 201);
        } catch (Throwable $e) {
            Response::error('Failed to create department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update department details.
     */
    public function update(Request $request, array $params): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }
        $deptId = (int)($params['id'] ?? 0);
        $body = $request->all();

        try {
            $db = Database::getConnection();

            // Verify ownership
            $stmtCheck = $db->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
            $stmtCheck->execute([$deptId, $orgId]);
            if (!$stmtCheck->fetch()) {
                Response::error('Department not found', 404);
                return;
            }

            $fields = [];
            $values = [];

            if (isset($body['name'])) {
                $fields[] = "name = ?";
                $values[] = trim($body['name']);
            }
            if (isset($body['icon'])) {
                $fields[] = "icon = ?";
                $values[] = trim($body['icon']);
            }
            if (isset($body['description'])) {
                $fields[] = "description = ?";
                $values[] = trim($body['description']);
            }
            if (array_key_exists('email', $body)) {
                $fields[] = "email = ?";
                $values[] = $body['email'];
            }
            if (array_key_exists('phone', $body)) {
                $fields[] = "phone = ?";
                $values[] = $body['phone'];
            }
            if (array_key_exists('whatsapp', $body)) {
                $fields[] = "whatsapp = ?";
                $values[] = $body['whatsapp'];
            }
            if (isset($body['greeting_message'])) {
                $fields[] = "greeting_message = ?";
                $values[] = trim($body['greeting_message']);
            }
            if (isset($body['timezone'])) {
                $fields[] = "timezone = ?";
                $values[] = trim($body['timezone']);
            }
            if (isset($body['auto_away_message'])) {
                $fields[] = "auto_away_message = ?";
                $values[] = trim($body['auto_away_message']);
            }
            if (isset($body['is_active'])) {
                $fields[] = "is_active = ?";
                $values[] = $body['is_active'] ? 1 : 0;
            }
            if (isset($body['enable_dedicated_widget'])) {
                $fields[] = "enable_dedicated_widget = ?";
                $values[] = $body['enable_dedicated_widget'] ? 1 : 0;
            }
            if (isset($body['working_hours'])) {
                $fields[] = "working_hours = ?";
                $values[] = is_string($body['working_hours']) ? $body['working_hours'] : json_encode($body['working_hours']);
            }
            if (isset($body['escalation_rules'])) {
                $fields[] = "escalation_rules = ?";
                $values[] = is_string($body['escalation_rules']) ? $body['escalation_rules'] : json_encode($body['escalation_rules']);
            }
            if (isset($body['lead_assignment_rules'])) {
                $fields[] = "lead_assignment_rules = ?";
                $values[] = is_string($body['lead_assignment_rules']) ? $body['lead_assignment_rules'] : json_encode($body['lead_assignment_rules']);
            }

            if (!empty($fields)) {
                $sql = "UPDATE departments SET " . implode(', ', $fields) . " WHERE id = ? AND organization_id = ?";
                $values[] = $deptId;
                $values[] = $orgId;
                $stmt = $db->prepare($sql);
                $stmt->execute($values);
            }

            Response::success(['message' => 'Department updated successfully']);
        } catch (Throwable $e) {
            Response::error('Failed to update department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete / Archive department.
     */
    public function delete(Request $request, array $params): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }
        $deptId = (int)($params['id'] ?? 0);

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM departments WHERE id = ? AND organization_id = ?");
            $stmt->execute([$deptId, $orgId]);

            Response::success(['message' => 'Department deleted successfully']);
        } catch (Throwable $e) {
            Response::error('Failed to delete department: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Sync assigned staff members for a department.
     */
    public function syncStaff(Request $request, array $params): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }
        $deptId = (int)($params['id'] ?? 0);
        $body = $request->all();
        $staffMembers = $body['staff'] ?? []; // Array of ['user_id' => 1, 'role' => 'lead', 'is_on_duty' => 1]

        try {
            $db = Database::getConnection();

            // Clear existing staff links
            $stmtDel = $db->prepare("DELETE FROM department_staff WHERE department_id = ?");
            $stmtDel->execute([$deptId]);

            if (!empty($staffMembers)) {
                $stmtIns = $db->prepare("
                    INSERT INTO department_staff (department_id, user_id, role, is_on_duty)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($staffMembers as $member) {
                    $userId = (int)($member['user_id'] ?? 0);
                    $role = in_array($member['role'] ?? '', ['lead', 'agent']) ? $member['role'] : 'agent';
                    $isOnDuty = isset($member['is_on_duty']) ? ($member['is_on_duty'] ? 1 : 0) : 1;

                    if ($userId > 0) {
                        $stmtIns->execute([$deptId, $userId, $role, $isOnDuty]);
                    }
                }
            }

            Response::success(['message' => 'Department staff updated successfully']);
        } catch (Throwable $e) {
            Response::error('Failed to sync staff: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Sync linked knowledge base sources for a department.
     */
    public function syncKnowledge(Request $request, array $params): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }
        $deptId = (int)($params['id'] ?? 0);
        $body = $request->all();
        $sourceIds = $body['knowledge_source_ids'] ?? [];

        try {
            $db = Database::getConnection();

            $stmtDel = $db->prepare("DELETE FROM department_knowledge WHERE department_id = ?");
            $stmtDel->execute([$deptId]);

            if (!empty($sourceIds)) {
                $stmtIns = $db->prepare("INSERT INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");
                foreach ($sourceIds as $sId) {
                    $stmtIns->execute([$deptId, (int)$sId]);
                }
            }

            Response::success(['message' => 'Department knowledge sources updated successfully']);
        } catch (Throwable $e) {
            Response::error('Failed to sync knowledge sources: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Manage Department FAQs.
     */
    public function manageFaqs(Request $request, array $params): void
    {
        $deptId = (int)($params['id'] ?? 0);
        $body = $request->all();
        $faqs = $body['faqs'] ?? []; // Array of ['question' => '...', 'answer' => '...']

        try {
            $db = Database::getConnection();

            $stmtDel = $db->prepare("DELETE FROM department_faqs WHERE department_id = ?");
            $stmtDel->execute([$deptId]);

            if (!empty($faqs)) {
                $stmtIns = $db->prepare("
                    INSERT INTO department_faqs (department_id, question, answer, sort_order)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($faqs as $idx => $faq) {
                    $q = trim($faq['question'] ?? '');
                    $a = trim($faq['answer'] ?? '');
                    if (!empty($q) && !empty($a)) {
                        $stmtIns->execute([$deptId, $q, $a, $idx + 1]);
                    }
                }
            }

            Response::success(['message' => 'Department FAQs updated successfully']);
        } catch (Throwable $e) {
            Response::error('Failed to update FAQs: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Manage Department Courses.
     */
    public function manageCourses(Request $request, array $params): void
    {
        $orgId = $this->getOrgId($request);
        if (!$orgId) {
            Response::error('Organization context required', 400);
            return;
        }

        $deptId = (int)($params['id'] ?? 0);
        $body = $request->all();
        $courses = $body['courses'] ?? []; // Array of ['id' => 12, 'course_name' => '...', 'course_code' => '...', 'campus_ids' => [1, 2]]

        try {
            $db = Database::getConnection();

            // Verify department belongs to tenant
            $stmtDept = $db->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
            $stmtDept->execute([$deptId, $orgId]);
            if (!$stmtDept->fetch()) {
                Response::error('Department not found', 404);
                return;
            }

            // Fetch existing courses for this department to preserve IDs
            $stmtOld = $db->prepare("SELECT id FROM department_courses WHERE department_id = ?");
            $stmtOld->execute([$deptId]);
            $existingCourseIds = $stmtOld->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $existingCourseIds = array_map('intval', $existingCourseIds);
            $retainedIds = [];

            $db->beginTransaction();

            if (!empty($courses)) {
                foreach ($courses as $idx => $course) {
                    $cid = isset($course['id']) ? (int)$course['id'] : 0;
                    $name = trim($course['course_name'] ?? '');
                    $code = trim($course['course_code'] ?? '');
                    $campusIds = $course['campus_ids'] ?? null;

                    if (empty($name)) {
                        continue;
                    }

                    $pType = $course['program_type'] ?? null;
                    $dur = $course['duration'] ?? null;
                    $mode = $course['mode'] ?? null;

                    $activeCourseId = null;
                    if ($cid > 0 && in_array($cid, $existingCourseIds, true)) {
                        // Update existing course, retaining ID and campus mappings
                        $stmtUp = $db->prepare("
                            UPDATE department_courses
                            SET course_name = ?, 
                                course_code = ?, 
                                program_type = COALESCE(?, program_type),
                                duration = COALESCE(?, duration),
                                mode = COALESCE(?, mode),
                                sort_order = ?, 
                                organization_id = ?, 
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ? AND department_id = ?
                        ");
                        $stmtUp->execute([$name, $code ?: null, $pType, $dur, $mode, $idx + 1, $orgId, $cid, $deptId]);
                        $activeCourseId = $cid;
                    } else {
                        // Insert new course
                        $stmtIns = $db->prepare("
                            INSERT INTO department_courses (organization_id, department_id, course_name, course_code, program_type, duration, mode, sort_order)
                            VALUES (?, ?, ?, ?, COALESCE(?, 'undergraduate'), ?, COALESCE(?, 'full_time'), ?)
                        ");
                        $stmtIns->execute([$orgId, $deptId, $name, $code ?: null, $pType, $dur, $mode, $idx + 1]);
                        $activeCourseId = (int)$db->lastInsertId();
                    }

                    $retainedIds[] = $activeCourseId;

                    // Sync campus mappings if campus_ids was supplied
                    if ($campusIds !== null && is_array($campusIds)) {
                        $stmtDelCc = $db->prepare("DELETE FROM campus_courses WHERE course_id = ? AND organization_id = ?");
                        $stmtDelCc->execute([$activeCourseId, $orgId]);

                        $cleanCampIds = array_values(array_unique(array_filter(array_map('intval', $campusIds), fn($v) => $v > 0)));
                        if (!empty($cleanCampIds)) {
                            $inCamp = implode(',', array_fill(0, count($cleanCampIds), '?'));
                            $stmtValC = $db->prepare("SELECT id FROM campuses WHERE id IN ($inCamp) AND organization_id = ?");
                            $stmtValC->execute(array_merge($cleanCampIds, [$orgId]));
                            $validCampIds = $stmtValC->fetchAll(PDO::FETCH_COLUMN) ?: [];

                            if (!empty($validCampIds)) {
                                $stmtInsCc = $db->prepare("INSERT INTO campus_courses (organization_id, campus_id, course_id) VALUES (?, ?, ?)");
                                foreach ($validCampIds as $vCid) {
                                    $stmtInsCc->execute([$orgId, (int)$vCid, $activeCourseId]);
                                }
                            }
                        }
                    }
                }
            }

            // Remove courses that were deleted from the department
            $toDeleteIds = array_diff($existingCourseIds, $retainedIds);
            if (!empty($toDeleteIds)) {
                $inDel = implode(',', array_fill(0, count($toDeleteIds), '?'));
                $stmtDelOld = $db->prepare("DELETE FROM department_courses WHERE id IN ($inDel) AND department_id = ?");
                $stmtDelOld->execute(array_merge(array_values($toDeleteIds), [$deptId]));
            }

            $db->commit();

            Response::success(['message' => 'Department courses updated successfully']);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to update courses: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Public Widget API to fetch active departments for widget.
     */
    public function publicList(Request $request, array $params): void
    {
        $botToken = $params['bot_token'] ?? '';
        if (empty($botToken)) {
            Response::error('Bot token required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmtBot = $db->prepare("SELECT organization_id FROM chatbots WHERE bot_token = ? AND is_active = 1");
            $stmtBot->execute([$botToken]);
            $bot = $stmtBot->fetch();

            if (!$bot) {
                Response::error('Chatbot not found or inactive', 404);
                return;
            }

            $orgId = (int)$bot['organization_id'];

            $stmt = $db->prepare("
                SELECT id, name, slug, icon, description, greeting_message, email, phone, whatsapp, enable_dedicated_widget
                FROM departments
                WHERE organization_id = ? AND enable_dedicated_widget = 1
                ORDER BY name ASC
            ");
            $stmt->execute([$orgId]);
            $departments = $stmt->fetchAll();

            foreach ($departments as &$dept) {
                $stmtFaqs = $db->prepare("SELECT question, answer FROM department_faqs WHERE department_id = ? ORDER BY sort_order ASC LIMIT 5");
                $stmtFaqs->execute([(int)$dept['id']]);
                $dept['quick_chips'] = $stmtFaqs->fetchAll();
            }

            Response::success([
                'departments' => $departments
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to load widget departments', 500);
        }
    }

    /**
     * Public Dashboard API to fetch all departments with complete real data for console view.
     */
    public function publicDashboard(Request $request, array $params): void
    {
        $botToken = $params['bot_token'] ?? '';
        if (empty($botToken)) {
            Response::error('Bot token required', 400);
            return;
        }

        try {
            $db = Database::getConnection();
            $stmtBot = $db->prepare("SELECT organization_id FROM chatbots WHERE bot_token = ?");
            $stmtBot->execute([$botToken]);
            $bot = $stmtBot->fetch();

            if (!$bot) {
                Response::error('Chatbot not found', 404);
                return;
            }

            $orgId = (int)$bot['organization_id'];

            $stmt = $db->prepare("
                SELECT id, name, slug, icon, description, email, phone, whatsapp,
                       working_hours, timezone, auto_away_message, escalation_rules,
                       lead_assignment_rules, greeting_message, is_active, enable_dedicated_widget, is_preset,
                       created_at, updated_at
                FROM departments
                WHERE organization_id = ?
                ORDER BY is_active DESC, is_preset DESC, id ASC
            ");
            $stmt->execute([$orgId]);
            $departments = $stmt->fetchAll();

            $totalStaffAssigned = 0;
            $totalScopedDocs = 0;

            [$window, $sqlWhereDate, $dateParams, $windowLabel] = $this->parseWindowDateCondition($request);

            foreach ($departments as &$dept) {
                $deptId = (int)$dept['id'];
                $dept['working_hours'] = json_decode($dept['working_hours'] ?? '{}', true);
                $dept['escalation_rules'] = json_decode($dept['escalation_rules'] ?? '{}', true);
                $dept['lead_assignment_rules'] = json_decode($dept['lead_assignment_rules'] ?? '{}', true);

                // Fetch assigned staff
                $stmtStaff = $db->prepare("
                    SELECT ds.user_id, ds.role, ds.is_on_duty, u.name, u.email
                    FROM department_staff ds
                    JOIN users u ON ds.user_id = u.id
                    WHERE ds.department_id = ?
                ");
                $stmtStaff->execute([$deptId]);
                $staff = $stmtStaff->fetchAll() ?: [];
                $dept['staff'] = $staff;
                $totalStaffAssigned += count($staff);

                // Fetch linked knowledge sources
                $stmtKs = $db->prepare("
                    SELECT ks.id, ks.title, ks.type, ks.status
                    FROM department_knowledge dk
                    JOIN knowledge_sources ks ON dk.knowledge_source_id = ks.id
                    WHERE dk.department_id = ?
                ");
                $stmtKs->execute([$deptId]);
                $ks = $stmtKs->fetchAll() ?: [];
                $dept['knowledge_sources'] = $ks;
                $totalScopedDocs += count($ks);

                // Fetch FAQs
                $stmtFaqs = $db->prepare("
                    SELECT id, question, answer, sort_order
                    FROM department_faqs
                    WHERE department_id = ?
                    ORDER BY sort_order ASC
                ");
                $stmtFaqs->execute([$deptId]);
                $dept['faqs'] = $stmtFaqs->fetchAll() ?: [];

                // Count leads
                $stmtLeads = $db->prepare("SELECT COUNT(*) FROM leads WHERE department_id = :dept_id {$sqlWhereDate}");
            $stmtLeads->execute(array_merge([':dept_id' => $deptId], $dateParams));
            $dept['leads_count'] = (int)$stmtLeads->fetchColumn();

            // Callbacks
            $stmtCb = $db->prepare("SELECT COUNT(*) FROM counselor_callbacks WHERE department_id = :dept_id {$sqlWhereDate}");
            $stmtCb->execute(array_merge([':dept_id' => $deptId], $dateParams));
            $dept['callbacks_count'] = (int)$stmtCb->fetchColumn();

            // Campus tours
            $stmtCt = $db->prepare("SELECT COUNT(*) FROM campus_tour_bookings WHERE department_id = :dept_id {$sqlWhereDate}");
            $stmtCt->execute(array_merge([':dept_id' => $deptId], $dateParams));
            $dept['campus_tours_count'] = (int)$stmtCt->fetchColumn();

            // Scholarships
            $stmtSch = $db->prepare("SELECT COUNT(*) FROM leads WHERE department_id = :dept_id AND scholarship_tier IS NOT NULL {$sqlWhereDate}");
            $stmtSch->execute(array_merge([':dept_id' => $deptId], $dateParams));
            $dept['scholarships_count'] = (int)$stmtSch->fetchColumn();

            // Lead assets downloads
            $stmtAsset = $db->prepare("SELECT COALESCE(SUM(downloads_count), 0) FROM lead_assets WHERE department_id = :dept_id {$sqlWhereDate}");
            $stmtAsset->execute(array_merge([':dept_id' => $deptId], $dateParams));
            $dept['lead_assets_downloads'] = (int)$stmtAsset->fetchColumn();
        }

            Response::success([
                'departments' => $departments,
                'total_departments' => count($departments),
                'active_departments' => count(array_filter($departments, fn($d) => $d['is_active'] == 1)),
                'total_staff_assigned' => $totalStaffAssigned,
                'total_scoped_docs' => $totalScopedDocs
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to load dashboard departments: ' . $e->getMessage(), 500);
        }
    }
}

