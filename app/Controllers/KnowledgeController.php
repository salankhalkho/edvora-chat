<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use App\Services\ContentCompactor;
use App\Services\DocumentParser;
use App\Services\UrlScraper;
use PDO;
use Throwable;

class KnowledgeController
{
    /**
     * GET /v1/knowledge — List all knowledge sources for organization with validity & health details
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();
        $filter = $request->get('health_filter') ?? $request->get('status') ?? 'all';

        $stmt = $db->prepare("
            SELECT ks.id, ks.organization_id, ks.chatbot_id, ks.program_id, p.course_name as program_name,
                   ks.type, ks.title, ks.category, ks.academic_version,
                   ks.effective_from, ks.expires_on, ks.last_reviewed_at, ks.review_frequency_days,
                   ks.previous_version_id, ks.replaced_by_id,
                   ks.source_url, ks.file_path, ks.status, ks.keywords, ks.last_fetched_at, ks.created_at, ks.updated_at,
                   GROUP_CONCAT(CONCAT(d.id, ':::', d.name, ':::', IFNULL(d.icon, '🏫')) SEPARATOR '|||') as departments_raw
            FROM knowledge_sources ks
            LEFT JOIN programs p ON ks.program_id = p.id
            LEFT JOIN department_knowledge dk ON ks.id = dk.knowledge_source_id
            LEFT JOIN departments d ON dk.department_id = d.id
            WHERE ks.organization_id = :org_id
            GROUP BY ks.id
            ORDER BY ks.id DESC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $sources = $stmt->fetchAll();

        $today = date('Y-m-d');
        $todayTime = strtotime($today);

        $formatted = [];
        foreach ($sources as $s) {
            $depts = [];
            if (!empty($s['departments_raw'])) {
                $rawList = explode('|||', $s['departments_raw']);
                foreach ($rawList as $raw) {
                    $parts = explode(':::', $raw);
                    if (count($parts) >= 3) {
                        $depts[] = [
                            'id' => (int)$parts[0],
                            'name' => $parts[1],
                            'icon' => $parts[2]
                        ];
                    }
                }
            }
            unset($s['departments_raw']);
            $s['departments'] = $depts;
            $s['is_global'] = empty($depts);
            $s['category'] = !empty($s['category']) ? $s['category'] : 'General / Institutional';

            // Health and validity metrics
            $expiresOn = $s['expires_on'];
            $daysUntilExpiry = null;
            $isExpired = false;
            $isExpiringSoon = false;
            $isEffectiveFuture = (!empty($s['effective_from']) && $s['effective_from'] > $today);

            if (!empty($expiresOn)) {
                $expiryTime = strtotime($expiresOn);
                $daysUntilExpiry = (int)round(($expiryTime - $todayTime) / 86400);
                if ($daysUntilExpiry < 0) {
                    $isExpired = true;
                } elseif ($daysUntilExpiry <= 30) {
                    $isExpiringSoon = true;
                }
            }

            $noExpiry = empty($expiresOn);
            $reviewFreq = (int)($s['review_frequency_days'] ?: 180);
            $lastReviewed = $s['last_reviewed_at'] ?: substr((string)$s['created_at'], 0, 10);
            $daysSinceReview = (int)round(($todayTime - strtotime($lastReviewed)) / 86400);
            $isReviewDue = ($daysSinceReview >= $reviewFreq);

            // Real-time effective status
            $currentDbStatus = $s['status'] ?? 'active';
            $computedStatus = $currentDbStatus;

            if ($currentDbStatus === 'archived') {
                $computedStatus = 'archived';
            } elseif ($isExpired) {
                $computedStatus = 'expired';
            } elseif ($isExpiringSoon) {
                $computedStatus = 'expiring_soon';
            } elseif ($currentDbStatus === 'active') {
                $computedStatus = 'active';
            }

            $needsAttention = ($computedStatus === 'expired' || $computedStatus === 'expiring_soon' || $isReviewDue || $noExpiry);

            $s['days_until_expiry'] = $daysUntilExpiry;
            $s['is_expired'] = $isExpired;
            $s['is_expiring_soon'] = $isExpiringSoon;
            $s['is_effective_future'] = $isEffectiveFuture;
            $s['no_expiry'] = $noExpiry;
            $s['days_since_review'] = $daysSinceReview;
            $s['is_review_due'] = $isReviewDue;
            $s['computed_status'] = $computedStatus;
            $s['needs_attention'] = $needsAttention;

            // Apply filter if specified
            if ($filter === 'needs_attention' && !$needsAttention) {
                continue;
            } elseif ($filter === 'active' && ($computedStatus !== 'active' || $isExpired)) {
                continue;
            } elseif ($filter === 'expiring_soon' && !$isExpiringSoon) {
                continue;
            } elseif ($filter === 'expired' && !$isExpired && $computedStatus !== 'expired') {
                continue;
            } elseif ($filter === 'review_due' && !$isReviewDue) {
                continue;
            } elseif ($filter === 'no_expiry' && !$noExpiry) {
                continue;
            } elseif ($filter === 'archived' && $computedStatus !== 'archived') {
                continue;
            }

            $formatted[] = $s;
        }

        Response::success($formatted);
    }

    /**
     * GET /v1/knowledge/health-summary — Aggregate health KPIs and statistics
     */
    public function healthSummary(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT id, status, expires_on, last_reviewed_at, created_at, review_frequency_days
            FROM knowledge_sources
            WHERE organization_id = :org_id
        ");
        $stmt->execute([':org_id' => $orgId]);
        $all = $stmt->fetchAll();

        $today = date('Y-m-d');
        $todayTime = strtotime($today);

        $activeCount = 0;
        $expiringSoonCount = 0;
        $expiredCount = 0;
        $needsReviewCount = 0;
        $noExpiryCount = 0;
        $archivedCount = 0;
        $needsAttentionCount = 0;

        foreach ($all as $item) {
            $status = $item['status'] ?? 'active';
            $expiresOn = $item['expires_on'];
            $isArchived = ($status === 'archived');
            $isExpired = false;
            $isExpiringSoon = false;
            $noExpiry = empty($expiresOn);

            if (!empty($expiresOn)) {
                $expiryTime = strtotime($expiresOn);
                $days = (int)round(($expiryTime - $todayTime) / 86400);
                if ($days < 0) {
                    $isExpired = true;
                } elseif ($days <= 30) {
                    $isExpiringSoon = true;
                }
            }

            $reviewFreq = (int)($item['review_frequency_days'] ?: 180);
            $lastReviewed = $item['last_reviewed_at'] ?: substr((string)$item['created_at'], 0, 10);
            $daysSinceReview = (int)round(($todayTime - strtotime($lastReviewed)) / 86400);
            $isReviewDue = ($daysSinceReview >= $reviewFreq);

            if ($isArchived) {
                $archivedCount++;
            } elseif ($isExpired || $status === 'expired') {
                $expiredCount++;
            } elseif ($isExpiringSoon) {
                $expiringSoonCount++;
                $activeCount++;
            } elseif ($status === 'active') {
                $activeCount++;
            }

            if (!$isArchived) {
                if ($noExpiry) {
                    $noExpiryCount++;
                }
                if ($isReviewDue) {
                    $needsReviewCount++;
                }
                if ($isExpired || $isExpiringSoon || $isReviewDue || $noExpiry) {
                    $needsAttentionCount++;
                }
            }
        }

        Response::success([
            'total_sources' => count($all),
            'active_count' => $activeCount,
            'expiring_soon_count' => $expiringSoonCount,
            'expired_count' => $expiredCount,
            'needs_review_count' => $needsReviewCount,
            'no_expiry_count' => $noExpiryCount,
            'archived_count' => $archivedCount,
            'needs_attention_count' => $needsAttentionCount
        ]);
    }

    /**
     * GET /v1/knowledge/{id} — Fetch single knowledge source details
     */
    public function show(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT ks.id, ks.organization_id, ks.chatbot_id, ks.program_id, p.course_name as program_name,
                   ks.type, ks.title, ks.category, ks.academic_version,
                   ks.effective_from, ks.expires_on, ks.last_reviewed_at, ks.review_frequency_days,
                   ks.previous_version_id, ks.replaced_by_id,
                   ks.source_url, ks.raw_content, ks.processed_content, ks.keywords, ks.semantic_keywords, ks.file_path, ks.status, ks.last_fetched_at, ks.created_at, ks.updated_at
            FROM knowledge_sources ks
            LEFT JOIN programs p ON ks.program_id = p.id
            WHERE ks.id = :id AND ks.organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $source = $stmt->fetch();

        if (!$source) {
            Response::error('Knowledge source not found.', 404);
        }

        // Fetch attached departments
        $deptStmt = $db->prepare("
            SELECT d.id, d.name, IFNULL(d.icon, '🏫') as icon
            FROM departments d
            INNER JOIN department_knowledge dk ON d.id = dk.department_id
            WHERE dk.knowledge_source_id = :ks_id AND d.organization_id = :org_id
        ");
        $deptStmt->execute([':ks_id' => $id, ':org_id' => $orgId]);
        $source['departments'] = $deptStmt->fetchAll();
        $source['department_ids'] = array_map(fn($d) => (int)$d['id'], $source['departments']);
        $source['is_global'] = empty($source['departments']);

        $today = date('Y-m-d');
        $todayTime = strtotime($today);
        $expiresOn = $source['expires_on'];
        $daysUntilExpiry = null;
        $isExpired = false;
        $isExpiringSoon = false;

        if (!empty($expiresOn)) {
            $expiryTime = strtotime($expiresOn);
            $daysUntilExpiry = (int)round(($expiryTime - $todayTime) / 86400);
            if ($daysUntilExpiry < 0) {
                $isExpired = true;
            } elseif ($daysUntilExpiry <= 30) {
                $isExpiringSoon = true;
            }
        }

        $currentDbStatus = $source['status'] ?? 'active';
        $computedStatus = $currentDbStatus;
        if ($currentDbStatus === 'archived') {
            $computedStatus = 'archived';
        } elseif ($isExpired) {
            $computedStatus = 'expired';
        } elseif ($isExpiringSoon) {
            $computedStatus = 'expiring_soon';
        }

        $source['days_until_expiry'] = $daysUntilExpiry;
        $source['is_expired'] = $isExpired;
        $source['is_expiring_soon'] = $isExpiringSoon;
        $source['computed_status'] = $computedStatus;

        Response::success($source);
    }

    /**
     * PUT/POST /v1/knowledge/{id} — Update knowledge source details & metadata
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $body = $request->all();

        $db = Database::getConnection();

        // Verify existence & ownership
        $stmt = $db->prepare("SELECT id, type, status FROM knowledge_sources WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $existing = $stmt->fetch();

        if (!$existing) {
            Response::error('Knowledge source not found.', 404);
        }

        $title = trim($body['title'] ?? '');
        if (empty($title)) {
            Response::error('Document title cannot be empty.', 422);
        }

        $category = trim($body['category'] ?? 'General / Institutional');
        $academicVersion = trim($body['academic_version'] ?? '2026-27');
        $effectiveFrom = !empty($body['effective_from']) ? $body['effective_from'] : null;
        $expiresOn = !empty($body['expires_on']) ? $body['expires_on'] : null;
        $reviewFrequencyDays = !empty($body['review_frequency_days']) ? (int)$body['review_frequency_days'] : 180;
        $status = in_array($body['status'] ?? '', ['active', 'expiring_soon', 'expired', 'archived', 'processing']) ? $body['status'] : $existing['status'];
        $keywords = trim($body['keywords'] ?? '');
        $semanticKeywords = trim($body['semantic_keywords'] ?? '');
        $rawContent = isset($body['raw_content']) ? (string)$body['raw_content'] : null;

        // Academic Program Mapping
        $programId = null;
        if (isset($body['program_id']) && $body['program_id'] !== '' && $body['program_id'] !== null) {
            $pId = (int)$body['program_id'];
            if ($pId > 0) {
                $chkProg = $db->prepare("SELECT id FROM programs WHERE id = ? AND organization_id = ?");
                $chkProg->execute([$pId, $orgId]);
                if ($chkProg->fetch()) {
                    $programId = $pId;
                }
            }
        }

        // Auto-recalculate computed status if active
        if ($status !== 'archived') {
            $today = date('Y-m-d');
            if ($expiresOn && $expiresOn < $today) {
                $status = 'expired';
            } elseif ($expiresOn && strtotime($expiresOn) - strtotime($today) <= 30 * 86400) {
                $status = 'expiring_soon';
            } else {
                $status = 'active';
            }
        }

        // Build update statement
        $fields = [
            'title = :title',
            'category = :category',
            'academic_version = :academic_version',
            'effective_from = :effective_from',
            'expires_on = :expires_on',
            'review_frequency_days = :review_frequency_days',
            'status = :status',
            'keywords = :keywords',
            'semantic_keywords = :semantic_keywords',
            'program_id = :program_id',
            'updated_at = NOW()'
        ];

        $params = [
            ':title' => $title,
            ':category' => $category,
            ':academic_version' => $academicVersion,
            ':effective_from' => $effectiveFrom,
            ':expires_on' => $expiresOn,
            ':review_frequency_days' => $reviewFrequencyDays,
            ':status' => $status,
            ':keywords' => $keywords,
            ':semantic_keywords' => $semanticKeywords,
            ':program_id' => $programId,
            ':id' => $id,
            ':org_id' => $orgId
        ];

        if ($rawContent !== null) {
            $fields[] = 'raw_content = :raw_content';
            $fields[] = 'processed_content = :processed_content';
            $params[':raw_content'] = $rawContent;
            $params[':processed_content'] = $rawContent;
        }

        $sql = "UPDATE knowledge_sources SET " . implode(', ', $fields) . " WHERE id = :id AND organization_id = :org_id";
        $updateStmt = $db->prepare($sql);
        $updateStmt->execute($params);

        // Update department linkages if provided
        if (isset($body['departments']) && is_array($body['departments'])) {
            $delStmt = $db->prepare("DELETE FROM department_knowledge WHERE knowledge_source_id = :ks_id");
            $delStmt->execute([':ks_id' => $id]);

            if (!empty($body['departments'])) {
                $insDept = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (:dept_id, :ks_id)");
                foreach ($body['departments'] as $deptId) {
                    $deptId = (int)$deptId;
                    if ($deptId > 0) {
                        $insDept->execute([':dept_id' => $deptId, ':ks_id' => $id]);
                    }
                }
            }
        }

        AuditLogger::log('knowledge_source_updated', 'knowledge_source', $id, ['title' => $title, 'status' => $status]);

        Response::success(['message' => 'Knowledge source updated successfully.', 'id' => $id, 'status' => $status]);
    }

    /**
     * POST /v1/knowledge/paste — Add knowledge source via text paste
     */
    public function paste(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $data = $request->all();

        $errors = Validator::validate($data, [
            'title' => 'required|max:255',
            'content' => 'required|min:10'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $title = trim($data['title']);
        $category = !empty($data['category']) ? trim($data['category']) : 'General / Institutional';
        $academicVersion = !empty($data['academic_version']) ? trim($data['academic_version']) : null;
        $effectiveFrom = !empty($data['effective_from']) ? $data['effective_from'] : date('Y-m-d');
        $expiresOn = !empty($data['expires_on']) ? $data['expires_on'] : null;
        $reviewFreq = !empty($data['review_frequency_days']) ? (int)$data['review_frequency_days'] : 180;

        $rawContent = DocumentParser::sanitizeText($data['content']);
        $compacted = ContentCompactor::process($rawContent, $title);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO knowledge_sources (organization_id, chatbot_id, type, title, category, academic_version,
                                           effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                           raw_content, processed_content, keywords, status, created_at, updated_at)
            VALUES (:org_id, :bot_id, 'text_paste', :title, :category, :academic_version,
                    :effective_from, :expires_on, NOW(), :review_freq,
                    :raw_content, :processed_content, :keywords, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':bot_id' => $data['chatbot_id'] ?? null,
            ':title' => $title,
            ':category' => $category,
            ':academic_version' => $academicVersion,
            ':effective_from' => $effectiveFrom,
            ':expires_on' => $expiresOn,
            ':review_freq' => $reviewFreq,
            ':raw_content' => $rawContent,
            ':processed_content' => $compacted['processed_content'],
            ':keywords' => $compacted['keywords']
        ]);
        $id = (int)$db->lastInsertId();

        // Auto-link to department if department_id was passed
        $deptId = $data['department_id'] ?? $request->get('department_id') ?? $_POST['department_id'] ?? null;
        if (!empty($deptId)) {
            $stmtDeptKs = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");
            $stmtDeptKs->execute([(int)$deptId, $id]);
        }

        // Dispatch async job to enrich semantic_keywords via LLM
        $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('enrich_keywords', :payload, 'pending', NOW(), NOW())")
           ->execute([':payload' => json_encode(['knowledge_source_id' => $id])]);

        AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
            'title' => $title,
            'type' => 'text_paste',
            'category' => $category,
            'expires_on' => $expiresOn
        ]);

        Response::success([
            'id' => $id,
            'title' => $title,
            'type' => 'text_paste',
            'category' => $category,
            'expires_on' => $expiresOn,
            'status' => 'active',
            'keywords' => $compacted['keywords']
        ], 'Knowledge source created successfully', 201);
    }

    /**
     * POST /v1/knowledge/url — Submit URL for scraping
     */
    public function addUrl(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $url = trim((string)$request->get('url'));

        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            Response::error('A valid URL is required.', 422);
        }

        $category = !empty($request->get('category')) ? trim($request->get('category')) : 'General / Institutional';
        $academicVersion = !empty($request->get('academic_version')) ? trim($request->get('academic_version')) : null;
        $effectiveFrom = !empty($request->get('effective_from')) ? $request->get('effective_from') : date('Y-m-d');
        $expiresOn = !empty($request->get('expires_on')) ? $request->get('expires_on') : null;
        $reviewFreq = !empty($request->get('review_frequency_days')) ? (int)$request->get('review_frequency_days') : 180;

        try {
            $scraped = UrlScraper::scrape($url);
            $title = !empty($request->get('title')) ? trim($request->get('title')) : $scraped['title'];
            $compacted = ContentCompactor::process($scraped['raw_content'], $title);

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               source_url, raw_content, processed_content, keywords, content_hash, status, last_fetched_at, created_at, updated_at)
                VALUES (:org_id, :bot_id, 'url', :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :url, :raw_content, :processed_content, :keywords, :hash, 'active', NOW(), NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => !empty($request->get('chatbot_id')) ? (int)$request->get('chatbot_id') : null,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => $academicVersion,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':url' => $url,
                ':raw_content' => $scraped['raw_content'],
                ':processed_content' => $compacted['processed_content'],
                ':keywords' => $compacted['keywords'],
                ':hash' => $scraped['content_hash']
            ]);
            $id = (int)$db->lastInsertId();

            $deptId = $request->get('department_id') ?? $_POST['department_id'] ?? null;
            if (!empty($deptId)) {
                $stmtDeptKs = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");
                $stmtDeptKs->execute([(int)$deptId, $id]);
            }

            $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('enrich_keywords', :payload, 'pending', NOW(), NOW())")
               ->execute([':payload' => json_encode(['knowledge_source_id' => $id])]);

            AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
                'title' => $title,
                'type' => 'url',
                'category' => $category,
                'url' => $url
            ]);

            Response::success([
                'id' => $id,
                'title' => $title,
                'type' => 'url',
                'url' => $url,
                'category' => $category,
                'expires_on' => $expiresOn,
                'status' => 'active',
                'keywords' => $compacted['keywords']
            ], 'URL content scraped and added to knowledge base successfully', 201);

        } catch (Throwable $e) {
            Response::error("Failed to scrape URL: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/knowledge/upload — Upload document file (PDF, DOCX, TXT)
     */
    public function upload(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('No valid file uploaded or upload error occurred.', 400);
        }

        $file = $_FILES['file'];
        $originalFilename = basename($file['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (!in_array($extension, ['pdf', 'docx', 'txt'])) {
            Response::error('Invalid file type. Allowed file types: .pdf, .docx, .txt', 422);
        }

        $storageDir = dirname(__DIR__, 2) . '/storage/uploads/';
        if (!file_exists($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $savedFilename = 'doc_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $storageDir . $savedFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('Failed to save uploaded file on server.', 500);
        }

        $category = !empty($request->get('category')) ? trim($request->get('category')) : 'General / Institutional';
        $academicVersion = !empty($request->get('academic_version')) ? trim($request->get('academic_version')) : null;
        $effectiveFrom = !empty($request->get('effective_from')) ? $request->get('effective_from') : date('Y-m-d');
        $expiresOn = !empty($request->get('expires_on')) ? $request->get('expires_on') : null;
        $reviewFreq = !empty($request->get('review_frequency_days')) ? (int)$request->get('review_frequency_days') : 180;

        try {
            $rawContent = DocumentParser::parse($targetPath, $originalFilename);
            $title = !empty($request->get('title')) ? trim($request->get('title')) : pathinfo($originalFilename, PATHINFO_FILENAME);
            $compacted = ContentCompactor::process($rawContent, $title);

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               raw_content, processed_content, keywords, file_path, status, created_at, updated_at)
                VALUES (:org_id, :bot_id, 'document', :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :raw_content, :processed_content, :keywords, :file_path, 'active', NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => !empty($request->get('chatbot_id')) ? (int)$request->get('chatbot_id') : null,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => $academicVersion,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':raw_content' => $rawContent,
                ':processed_content' => $compacted['processed_content'],
                ':keywords' => $compacted['keywords'],
                ':file_path' => 'storage/uploads/' . $savedFilename
            ]);
            $id = (int)$db->lastInsertId();

            $deptId = $request->get('department_id') ?? $_POST['department_id'] ?? null;
            if (!empty($deptId)) {
                $stmtDeptKs = $db->prepare("INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id) VALUES (?, ?)");
                $stmtDeptKs->execute([(int)$deptId, $id]);
            }

            $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('enrich_keywords', :payload, 'pending', NOW(), NOW())")
               ->execute([':payload' => json_encode(['knowledge_source_id' => $id])]);

            AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
                'title' => $title,
                'type' => 'document',
                'category' => $category,
                'filename' => $originalFilename
            ]);

            Response::success([
                'id' => $id,
                'title' => $title,
                'type' => 'document',
                'filename' => $originalFilename,
                'category' => $category,
                'expires_on' => $expiresOn,
                'status' => 'active',
                'keywords' => $compacted['keywords']
            ], 'Document uploaded and processed successfully', 201);

        } catch (Throwable $e) {
            if (file_exists($targetPath)) {
                unlink($targetPath);
            }
            Response::error("Failed to parse document: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/knowledge/{id}/replace — 1-Click Replace Document with New Version (auto-archives predecessor)
     */
    public function replaceVersion(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $oldId = (int)($params['id'] ?? 0);
        $db = Database::getConnection();

        $stmtOld = $db->prepare("SELECT * FROM knowledge_sources WHERE id = :id AND organization_id = :org_id");
        $stmtOld->execute([':id' => $oldId, ':org_id' => $orgId]);
        $oldSource = $stmtOld->fetch();

        if (!$oldSource) {
            Response::error('Original knowledge source not found.', 404);
        }

        $title = trim((string)($request->get('title') ?? $oldSource['title']));
        $category = trim((string)($request->get('category') ?? $oldSource['category'] ?? 'Admissions'));
        $academicVersion = trim((string)($request->get('academic_version') ?? ''));
        $effectiveFrom = $request->get('effective_from') ?? date('Y-m-d');
        $expiresOn = $request->get('expires_on') ?? null;
        $reviewFreq = (int)($request->get('review_frequency_days') ?? $oldSource['review_frequency_days'] ?? 180);

        $newType = $request->get('type') ?? $oldSource['type'];
        $rawContent = '';
        $filePath = null;
        $sourceUrl = null;
        $contentHash = null;

        try {
            if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file'];
                $originalFilename = basename($file['name']);
                $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

                if (!in_array($extension, ['pdf', 'docx', 'txt'])) {
                    Response::error('Invalid file type for replacement. Allowed: .pdf, .docx, .txt', 422);
                }

                $storageDir = dirname(__DIR__, 2) . '/storage/uploads/';
                if (!file_exists($storageDir)) {
                    mkdir($storageDir, 0775, true);
                }
                $savedFilename = 'doc_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $targetPath = $storageDir . $savedFilename;

                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    Response::error('Failed to save replacement file on server.', 500);
                }

                $rawContent = DocumentParser::parse($targetPath, $originalFilename);
                $filePath = 'storage/uploads/' . $savedFilename;
                $newType = 'document';
            } elseif (!empty($request->get('content'))) {
                $rawContent = DocumentParser::sanitizeText((string)$request->get('content'));
                $newType = 'text_paste';
            } elseif (!empty($request->get('url'))) {
                $url = trim((string)$request->get('url'));
                $scraped = UrlScraper::scrape($url);
                $rawContent = $scraped['raw_content'];
                $sourceUrl = $url;
                $contentHash = $scraped['content_hash'];
                $newType = 'url';
            } else {
                Response::error('Please provide a new document file, pasted text, or web URL for the replacement version.', 422);
            }

            $compacted = ContentCompactor::process($rawContent, $title);

            // 1. Insert new knowledge source linked to previous version
            $stmtInsert = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               previous_version_id, source_url, raw_content, processed_content, keywords,
                                               file_path, content_hash, status, created_at, updated_at)
                VALUES (:org_id, :bot_id, :type, :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :prev_id, :source_url, :raw_content, :processed_content, :keywords,
                        :file_path, :content_hash, 'active', NOW(), NOW())
            ");
            $stmtInsert->execute([
                ':org_id' => $orgId,
                ':bot_id' => $oldSource['chatbot_id'],
                ':type' => $newType,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => !empty($academicVersion) ? $academicVersion : null,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':prev_id' => $oldId,
                ':source_url' => $sourceUrl,
                ':raw_content' => $rawContent,
                ':processed_content' => $compacted['processed_content'],
                ':keywords' => $compacted['keywords'],
                ':file_path' => $filePath,
                ':content_hash' => $contentHash
            ]);
            $newId = (int)$db->lastInsertId();

            // 2. Mark old version as archived and set replaced_by_id
            $stmtArchive = $db->prepare("
                UPDATE knowledge_sources
                SET status = 'archived', replaced_by_id = :new_id, updated_at = NOW()
                WHERE id = :old_id
            ");
            $stmtArchive->execute([':new_id' => $newId, ':old_id' => $oldId]);

            // 3. Copy department links from old source to new source
            $stmtCopyDepts = $db->prepare("
                INSERT IGNORE INTO department_knowledge (department_id, knowledge_source_id)
                SELECT department_id, :new_id FROM department_knowledge WHERE knowledge_source_id = :old_id
            ");
            $stmtCopyDepts->execute([':new_id' => $newId, ':old_id' => $oldId]);

            // 4. Dispatch async enrichment job for new version
            $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('enrich_keywords', :payload, 'pending', NOW(), NOW())")
               ->execute([':payload' => json_encode(['knowledge_source_id' => $newId])]);

            AuditLogger::log('knowledge_source_replaced', 'knowledge_source', $newId, [
                'previous_id' => $oldId,
                'title' => $title,
                'academic_version' => $academicVersion,
                'expires_on' => $expiresOn
            ]);

            Response::success([
                'new_id' => $newId,
                'archived_old_id' => $oldId,
                'title' => $title,
                'category' => $category,
                'academic_version' => $academicVersion,
                'status' => 'active',
                'expires_on' => $expiresOn
            ], "Document successfully updated to new version ({$academicVersion}). Previous version archived.", 201);

        } catch (Throwable $e) {
            Response::error("Replacement failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/knowledge/{id}/extend — Extend expiration date & re-activate document
     */
    public function extendValidity(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $newExpiry = $request->get('expires_on');

        if (empty($newExpiry)) {
            Response::error('A valid future expiration date is required.', 422);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE knowledge_sources
            SET expires_on = :expires_on,
                status = 'active',
                last_reviewed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([
            ':expires_on' => $newExpiry,
            ':id' => $id,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('knowledge_source_extended', 'knowledge_source', $id, ['new_expires_on' => $newExpiry]);

        Response::success([
            'id' => $id,
            'expires_on' => $newExpiry,
            'status' => 'active',
            'last_reviewed_at' => date('Y-m-d')
        ], 'Validity extended successfully. Document is active in AI knowledge.');
    }

    /**
     * POST /v1/knowledge/{id}/review — Mark document as audited/reviewed
     */
    public function markReviewed(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE knowledge_sources
            SET last_reviewed_at = NOW(),
                updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);

        AuditLogger::log('knowledge_source_reviewed', 'knowledge_source', $id, ['reviewed_at' => date('Y-m-d H:i:s')]);

        Response::success([
            'id' => $id,
            'last_reviewed_at' => date('Y-m-d')
        ], 'Document marked as reviewed. Freshness clock reset.');
    }

    /**
     * POST /v1/knowledge/{id}/archive — Manually archive knowledge source
     */
    public function archive(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE knowledge_sources
            SET status = 'archived', updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);

        AuditLogger::log('knowledge_source_archived', 'knowledge_source', $id, ['archived_at' => date('Y-m-d H:i:s')]);

        Response::success([
            'id' => $id,
            'status' => 'archived'
        ], 'Document archived. It will no longer be used for AI visitor answers.');
    }

    /**
     * DELETE /v1/knowledge/{id} — Delete knowledge source
     */
    public function delete(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $user = $GLOBALS['auth_user'] ?? [];
        $userRole = $user['role'] ?? 'staff';
        $userId = (int)($user['user_id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, title, file_path FROM knowledge_sources WHERE id = :id AND organization_id = :org_id");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $source = $stmt->fetch();

        if (!$source) {
            Response::error('Knowledge source not found.', 404);
        }

        // Authorization: Staff users can only delete knowledge sources assigned to their departments
        if ($userRole === 'staff') {
            $stmtAuth = $db->prepare("
                SELECT COUNT(*) as cnt
                FROM department_knowledge dk
                INNER JOIN department_staff ds ON dk.department_id = ds.department_id
                WHERE dk.knowledge_source_id = :ks_id AND ds.user_id = :user_id
            ");
            $stmtAuth->execute([':ks_id' => $id, ':user_id' => $userId]);
            $authCheck = $stmtAuth->fetch();

            if (!$authCheck || (int)$authCheck['cnt'] === 0) {
                Response::error('Permission denied. Staff members cannot delete global college knowledge sources or sources from unassigned departments.', 403);
                return;
            }
        }

        // Remove associated file if document type
        if (!empty($source['file_path'])) {
            $fullPath = dirname(__DIR__, 2) . '/' . $source['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $stmtDelete = $db->prepare("DELETE FROM knowledge_sources WHERE id = :id");
        $stmtDelete->execute([':id' => $id]);

        AuditLogger::log('knowledge_source_deleted', 'knowledge_source', $id, ['title' => $source['title']]);

        Response::success(null, 'Knowledge source deleted successfully');
    }

    /**
     * POST /v1/knowledge/{id}/refresh — Refresh URL knowledge source
     */
    public function refresh(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM knowledge_sources WHERE id = :id AND organization_id = :org_id AND type = 'url'");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $source = $stmt->fetch();

        if (!$source || empty($source['source_url'])) {
            Response::error('Knowledge source URL not found or not a valid URL type.', 404);
        }

        try {
            $scraped = UrlScraper::scrape($source['source_url']);

            if ($scraped['content_hash'] === $source['content_hash']) {
                $stmtTouch = $db->prepare("UPDATE knowledge_sources SET last_fetched_at = NOW(), last_reviewed_at = NOW() WHERE id = :id");
                $stmtTouch->execute([':id' => $id]);
                Response::success(null, 'URL checked. Content is up to date (no changes detected).');
            }

            $compacted = ContentCompactor::process($scraped['raw_content'], $source['title']);

            $stmtUpdate = $db->prepare("
                UPDATE knowledge_sources
                SET raw_content = :raw_content, processed_content = :processed_content, keywords = :keywords, content_hash = :hash,
                    last_fetched_at = NOW(), last_reviewed_at = NOW(), updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':raw_content' => $scraped['raw_content'],
                ':processed_content' => $compacted['processed_content'],
                ':keywords' => $compacted['keywords'],
                ':hash' => $scraped['content_hash'],
                ':id' => $id
            ]);

            AuditLogger::log('knowledge_source_refreshed', 'knowledge_source', $id, ['title' => $source['title']]);

            Response::success([
                'id' => $id,
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s')
            ], 'URL content refreshed and re-processed successfully');

        } catch (Throwable $e) {
            Response::error("Failed to refresh URL: " . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/knowledge/search — Test relevance search & context selection
     */
    public function search(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $query = trim((string)$request->get('query'));

        if (empty($query)) {
            Response::error('Search query is required.', 422);
        }

        $selectedContext = \App\Services\ContentEngine::selectContext($orgId, $query);

        Response::success([
            'query' => $query,
            'selected_count' => count($selectedContext),
            'sources' => $selectedContext
        ]);
    }
}
