<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use App\Services\ContentCompactor;
use App\Services\DocumentParser;
use App\Services\KnowledgeFileStorage;
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
                   ks.previous_version_id, ks.replaced_by_id, ks.lead_magnet,
                   ks.source_url, ks.file_path, ks.original_file_path, ks.original_file_size, ks.file_size_bytes, ks.token_count, ks.checksum_sha256,
                   ks.status, ks.keywords, ks.last_fetched_at, ks.created_at, ks.updated_at
            FROM knowledge_sources ks
            LEFT JOIN programs p ON ks.program_id = p.id
            WHERE ks.organization_id = :org_id
            ORDER BY ks.id DESC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $sources = $stmt->fetchAll();

        $today = date('Y-m-d');
        $todayTime = strtotime($today);

        $formatted = [];
        foreach ($sources as $s) {
            $s['departments'] = [];
            $s['is_global'] = true;
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
                   ks.previous_version_id, ks.replaced_by_id, ks.lead_magnet,
                   ks.source_url, ks.keywords, ks.file_path, ks.original_file_path, ks.original_file_size, ks.file_size_bytes, ks.token_count, ks.checksum_sha256,
                   ks.status, ks.last_fetched_at, ks.created_at, ks.updated_at
            FROM knowledge_sources ks
            LEFT JOIN programs p ON ks.program_id = p.id
            WHERE ks.id = :id AND ks.organization_id = :org_id
        ");
        $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        $source = $stmt->fetch();

        if (!$source) {
            Response::error('Knowledge source not found.', 404);
        }

        // Load content on-demand from filesystem
        $diskContent = KnowledgeFileStorage::loadText($orgId, $id);
        $source['content'] = $diskContent;
        $source['raw_content'] = $diskContent;
        $source['processed_content'] = $diskContent;

        // Fetch attached departments
        $source['departments'] = [];
        $source['department_ids'] = [];
        $source['is_global'] = true;

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
            ':program_id' => $programId,
            ':id' => $id,
            ':org_id' => $orgId
        ];

        if ($rawContent !== null) {
            $saveMeta = KnowledgeFileStorage::saveText($orgId, $id, $rawContent);
            $fields[] = 'file_path = :file_path';
            $fields[] = 'file_size_bytes = :file_size_bytes';
            $fields[] = 'token_count = :token_count';
            $fields[] = 'checksum_sha256 = :checksum_sha256';
            $params[':file_path'] = $saveMeta['file_path'];
            $params[':file_size_bytes'] = $saveMeta['file_size_bytes'];
            $params[':token_count'] = $saveMeta['token_count'];
            $params[':checksum_sha256'] = $saveMeta['checksum_sha256'];

            // Dispatch background re-chunking job
            try {
                $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
                $stmtJob->execute([':p' => json_encode(['source_id' => $id, 'organization_id' => $orgId, 'program_id' => $programId])]);
            } catch (Throwable $jobEx) {
                error_log('[KnowledgeController] Failed to dispatch chunk_and_embed on update: ' . $jobEx->getMessage());
            }
        }

        $sql = "UPDATE knowledge_sources SET " . implode(', ', $fields) . " WHERE id = :id AND organization_id = :org_id";
        $updateStmt = $db->prepare($sql);
        $updateStmt->execute($params);



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

        $programId = !empty($data['program_id']) ? (int)$data['program_id'] : null;

        $rawContent = DocumentParser::sanitizeText($data['content']);
        $compacted = ContentCompactor::process($rawContent, $title);

        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO knowledge_sources (organization_id, chatbot_id, program_id, type, title, category, academic_version,
                                           effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                           keywords, status, created_at, updated_at)
            VALUES (:org_id, :bot_id, :program_id, 'text_paste', :title, :category, :academic_version,
                    :effective_from, :expires_on, NOW(), :review_freq,
                    :keywords, 'pending', NOW(), NOW())
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':bot_id' => $data['chatbot_id'] ?? null,
            ':program_id' => $programId,
            ':title' => $title,
            ':category' => $category,
            ':academic_version' => $academicVersion,
            ':effective_from' => $effectiveFrom,
            ':expires_on' => $expiresOn,
            ':review_freq' => $reviewFreq,
            ':keywords' => $compacted['keywords']
        ]);
        $id = (int)$db->lastInsertId();

        // Save clean text directly to storage/knowledge/{org_id}/source_{id}.txt
        $saveMeta = KnowledgeFileStorage::saveText($orgId, $id, $compacted['processed_content']);

        $stmtMeta = $db->prepare("
            UPDATE knowledge_sources
            SET file_path = :file_path,
                file_size_bytes = :size_bytes,
                token_count = :tokens,
                checksum_sha256 = :sha256
            WHERE id = :id
        ");
        $stmtMeta->execute([
            ':file_path'  => $saveMeta['file_path'],
            ':size_bytes' => $saveMeta['file_size_bytes'],
            ':tokens'     => $saveMeta['token_count'],
            ':sha256'     => $saveMeta['checksum_sha256'],
            ':id'         => $id
        ]);

        AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
            'title' => $title,
            'type' => 'text_paste',
            'category' => $category,
            'expires_on' => $expiresOn
        ]);

        // Dispatch background job to chunk and embed this source into knowledge_items
        try {
            $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
            $stmtJob->execute([':p' => json_encode(['source_id' => $id, 'organization_id' => $orgId, 'program_id' => $programId])]);
        } catch (Throwable $jobEx) {
            error_log('[KnowledgeController] Failed to dispatch chunk_and_embed job: ' . $jobEx->getMessage());
        }

        Response::success([
            'id' => $id,
            'title' => $title,
            'type' => 'text_paste',
            'category' => $category,
            'expires_on' => $expiresOn,
            'status' => 'pending',
            'file_path' => $saveMeta['file_path'],
            'file_size_bytes' => $saveMeta['file_size_bytes'],
            'token_count' => $saveMeta['token_count'],
            'keywords' => $compacted['keywords']
        ], 'Knowledge source created and queued for vector embedding', 201);
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

        $programId = !empty($request->get('program_id')) ? (int)$request->get('program_id') : null;

        try {
            $scraped = UrlScraper::scrape($url);
            $title = !empty($request->get('title')) ? trim($request->get('title')) : $scraped['title'];
            $compacted = ContentCompactor::process($scraped['raw_content'], $title);

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, program_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               source_url, keywords, content_hash, status, last_fetched_at, created_at, updated_at)
                VALUES (:org_id, :bot_id, :program_id, 'url', :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :url, :keywords, :hash, 'pending', NOW(), NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => !empty($request->get('chatbot_id')) ? (int)$request->get('chatbot_id') : null,
                ':program_id' => $programId,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => $academicVersion,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':url' => $url,
                ':keywords' => $compacted['keywords'],
                ':hash' => $scraped['content_hash']
            ]);
            $id = (int)$db->lastInsertId();

            // Save clean text directly to storage/knowledge/{org_id}/source_{id}.txt
            $saveMeta = KnowledgeFileStorage::saveText($orgId, $id, $compacted['processed_content']);

            $stmtMeta = $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path,
                    file_size_bytes = :size_bytes,
                    token_count = :tokens,
                    checksum_sha256 = :sha256
                WHERE id = :id
            ");
            $stmtMeta->execute([
                ':file_path'  => $saveMeta['file_path'],
                ':size_bytes' => $saveMeta['file_size_bytes'],
                ':tokens'     => $saveMeta['token_count'],
                ':sha256'     => $saveMeta['checksum_sha256'],
                ':id'         => $id
            ]);

            AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
                'title' => $title,
                'type' => 'url',
                'category' => $category,
                'url' => $url
            ]);

            // Dispatch background job to chunk and embed this source into knowledge_items
            try {
                $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
                $stmtJob->execute([':p' => json_encode(['source_id' => $id, 'organization_id' => $orgId, 'program_id' => $programId])]);
            } catch (Throwable $jobEx) {
                error_log('[KnowledgeController] Failed to dispatch chunk_and_embed job: ' . $jobEx->getMessage());
            }

            Response::success([
                'id' => $id,
                'title' => $title,
                'type' => 'url',
                'url' => $url,
                'category' => $category,
                'expires_on' => $expiresOn,
                'status' => 'pending',
                'file_path' => $saveMeta['file_path'],
                'file_size_bytes' => $saveMeta['file_size_bytes'],
                'token_count' => $saveMeta['token_count'],
                'keywords' => $compacted['keywords']
            ], 'URL content scraped and queued for vector embedding', 201);

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
        if (!$orgId) {
            Response::error('Organization context missing.', 401);
        }

        // 1. Inspect standard PHP upload error codes
        if (empty($_FILES['file'])) {
            Response::error('No file was received in the request. Please select a document to upload.', 400);
        }

        $uploadError = $_FILES['file']['error'];
        if ($uploadError !== UPLOAD_ERR_OK) {
            switch ($uploadError) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    Response::error('The uploaded file exceeds the server maximum allowed size limit. Please upload a smaller document.', 413);
                    break;
                case UPLOAD_ERR_PARTIAL:
                    Response::error('The document was only partially uploaded due to network interruption. Please try again.', 400);
                    break;
                case UPLOAD_ERR_NO_FILE:
                    Response::error('No file was uploaded. Please choose a file to upload.', 400);
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                case UPLOAD_ERR_CANT_WRITE:
                    Response::error('Server storage error: unable to write temporary file.', 500);
                    break;
                default:
                    Response::error('File upload failed with error code: ' . $uploadError, 400);
                    break;
            }
        }

        $file = $_FILES['file'];
        $originalFilename = basename($file['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (!in_array($extension, ['pdf', 'docx', 'txt'])) {
            Response::error('Invalid file type. Allowed file types: .pdf, .docx, .txt', 422);
        }

        // 2. Enforce Tenant's Plan Upload Limit (max_file_upload_mb) from plan_quotas
        $planData = $this->getTenantPlanInfo($orgId);
        $maxFileMb = (int)($planData['quotas']['max_file_upload_mb'] ?? 15);
        $planName = $planData['plan_name'] ?? 'Starter';

        if ($maxFileMb > 0) {
            $fileSizeBytes = (int)$file['size'];
            $maxBytes = $maxFileMb * 1024 * 1024;
            if ($fileSizeBytes > $maxBytes) {
                $actualMb = round($fileSizeBytes / (1024 * 1024), 2);
                Response::error("File size ({$actualMb} MB) exceeds your {$planName} plan limit of {$maxFileMb} MB per document. Please compress the file or upgrade your plan.", 422);
            }
        }

        // 3. Enforce Tenant's Knowledge Source Count Limit (max_knowledge_sources)
        $maxSources = (int)($planData['quotas']['max_knowledge_sources'] ?? 20);
        if ($maxSources !== -1) {
            $db = Database::getConnection();
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM knowledge_sources WHERE organization_id = :org_id AND status != 'archived'");
            $stmtCount->execute([':org_id' => $orgId]);
            $currentSources = (int)$stmtCount->fetchColumn();
            if ($currentSources >= $maxSources) {
                Response::error("Your {$planName} plan limit of {$maxSources} active knowledge sources has been reached. Please upgrade your plan to add more documents.", 422);
            }
        }

        $originalDir = KnowledgeFileStorage::getOriginalFilesDirectory($orgId);
        KnowledgeFileStorage::ensureDirectoryExists($originalDir);

        $savedFilename = 'doc_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $originalDir . '/' . $savedFilename;
        $originalFilePath = "storage/knowledge/{$orgId}/original/{$savedFilename}";

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            Response::error('Failed to save uploaded file on server.', 500);
        }

        $category = !empty($request->get('category')) ? trim($request->get('category')) : 'General / Institutional';
        $academicVersion = !empty($request->get('academic_version')) ? trim($request->get('academic_version')) : null;
        $effectiveFrom = !empty($request->get('effective_from')) ? $request->get('effective_from') : date('Y-m-d');
        $expiresOn = !empty($request->get('expires_on')) ? $request->get('expires_on') : null;
        $reviewFreq = !empty($request->get('review_frequency_days')) ? (int)$request->get('review_frequency_days') : 180;

        $programId = !empty($request->get('program_id')) ? (int)$request->get('program_id') : (!empty($_POST['program_id']) ? (int)$_POST['program_id'] : null);

        try {
            $rawContent = DocumentParser::parse($targetPath, $originalFilename);
            $title = !empty($request->get('title')) ? trim($request->get('title')) : pathinfo($originalFilename, PATHINFO_FILENAME);
            $compacted = ContentCompactor::process($rawContent, $title);

            $origUploadBytes = (int)$file['size'];

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, program_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               original_file_path, original_file_size, keywords, status, created_at, updated_at)
                VALUES (:org_id, :bot_id, :program_id, 'document', :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :orig_path, :orig_size, :keywords, 'pending', NOW(), NOW())
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => !empty($request->get('chatbot_id')) ? (int)$request->get('chatbot_id') : null,
                ':program_id' => $programId,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => $academicVersion,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':orig_path' => $originalFilePath,
                ':orig_size' => $origUploadBytes,
                ':keywords' => $compacted['keywords']
            ]);
            $id = (int)$db->lastInsertId();

            // Save clean text directly to storage/knowledge/{org_id}/source_{id}.txt
            $saveMeta = KnowledgeFileStorage::saveText($orgId, $id, $compacted['processed_content']);

            $stmtMeta = $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path,
                    file_size_bytes = :size_bytes,
                    token_count = :tokens,
                    checksum_sha256 = :sha256
                WHERE id = :id
            ");
            $stmtMeta->execute([
                ':file_path'  => $saveMeta['file_path'],
                ':size_bytes' => $saveMeta['file_size_bytes'],
                ':tokens'     => $saveMeta['token_count'],
                ':sha256'     => $saveMeta['checksum_sha256'],
                ':id'         => $id
            ]);

            AuditLogger::log('knowledge_source_created', 'knowledge_source', $id, [
                'title' => $title,
                'type' => 'document',
                'category' => $category,
                'filename' => $originalFilename
            ]);

            // Dispatch background job to chunk and embed this source into knowledge_items
            try {
                $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
                $stmtJob->execute([':p' => json_encode(['source_id' => $id, 'organization_id' => $orgId, 'program_id' => $programId])]);
            } catch (Throwable $jobEx) {
                error_log('[KnowledgeController] Failed to dispatch chunk_and_embed job: ' . $jobEx->getMessage());
            }

            Response::success([
                'id' => $id,
                'title' => $title,
                'type' => 'document',
                'filename' => $originalFilename,
                'category' => $category,
                'expires_on' => $expiresOn,
                'status' => 'pending',
                'file_path' => $saveMeta['file_path'],
                'original_file_path' => $originalFilePath,
                'original_file_size' => $origUploadBytes,
                'file_size_bytes' => $saveMeta['file_size_bytes'],
                'token_count' => $saveMeta['token_count'],
                'keywords' => $compacted['keywords']
            ], 'Document uploaded and queued for vector embedding', 201);

        } catch (Throwable $e) {
            if (file_exists($targetPath)) {
                @unlink($targetPath);
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

                // Enforce tenant max_file_upload_mb
                $planData = $this->getTenantPlanInfo($orgId);
                $maxFileMb = (int)($planData['quotas']['max_file_upload_mb'] ?? 15);
                $planName = $planData['plan_name'] ?? 'Starter';
                if ($maxFileMb > 0) {
                    $fileSizeBytes = (int)$file['size'];
                    $maxBytes = $maxFileMb * 1024 * 1024;
                    if ($fileSizeBytes > $maxBytes) {
                        $actualMb = round($fileSizeBytes / (1024 * 1024), 2);
                        Response::error("Replacement file size ({$actualMb} MB) exceeds your {$planName} plan limit of {$maxFileMb} MB. Please compress the file or upgrade your plan.", 422);
                    }
                }

                $originalDir = KnowledgeFileStorage::getOriginalFilesDirectory($orgId);
                KnowledgeFileStorage::ensureDirectoryExists($originalDir);

                $savedFilename = 'doc_' . $orgId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $targetPath = $originalDir . '/' . $savedFilename;
                $originalFilePath = "storage/knowledge/{$orgId}/original/{$savedFilename}";

                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    Response::error('Failed to save replacement file on server.', 500);
                }

                $rawContent = DocumentParser::parse($targetPath, $originalFilename);
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
            $programId = !empty($request->get('program_id')) ? (int)$request->get('program_id') : (!empty($oldSource['program_id']) ? (int)$oldSource['program_id'] : null);

            $origUploadBytes = ($newType === 'document' && !empty($file['size'])) ? (int)$file['size'] : null;

            // 1. Insert new knowledge source linked to previous version
            $stmtInsert = $db->prepare("
                INSERT INTO knowledge_sources (organization_id, chatbot_id, program_id, type, title, category, academic_version,
                                               effective_from, expires_on, last_reviewed_at, review_frequency_days,
                                               previous_version_id, source_url, original_file_path, original_file_size, keywords,
                                               content_hash, status, created_at, updated_at)
                VALUES (:org_id, :bot_id, :program_id, :type, :title, :category, :academic_version,
                        :effective_from, :expires_on, NOW(), :review_freq,
                        :prev_id, :source_url, :orig_path, :orig_size, :keywords,
                        :content_hash, 'pending', NOW(), NOW())
            ");
            $stmtInsert->execute([
                ':org_id' => $orgId,
                ':bot_id' => $oldSource['chatbot_id'],
                ':program_id' => $programId,
                ':type' => $newType,
                ':title' => $title,
                ':category' => $category,
                ':academic_version' => !empty($academicVersion) ? $academicVersion : null,
                ':effective_from' => $effectiveFrom,
                ':expires_on' => $expiresOn,
                ':review_freq' => $reviewFreq,
                ':prev_id' => $oldId,
                ':source_url' => $sourceUrl,
                ':orig_path' => $originalFilePath,
                ':orig_size' => $origUploadBytes,
                ':keywords' => $compacted['keywords'],
                ':content_hash' => $contentHash
            ]);
            $newId = (int)$db->lastInsertId();

            // Save clean text directly to storage/knowledge/{org_id}/source_{newId}.txt
            $saveMeta = KnowledgeFileStorage::saveText($orgId, $newId, $compacted['processed_content']);

            $stmtMeta = $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path,
                    file_size_bytes = :size_bytes,
                    token_count = :tokens,
                    checksum_sha256 = :sha256
                WHERE id = :id
            ");
            $stmtMeta->execute([
                ':file_path'  => $saveMeta['file_path'],
                ':size_bytes' => $saveMeta['file_size_bytes'],
                ':tokens'     => $saveMeta['token_count'],
                ':sha256'     => $saveMeta['checksum_sha256'],
                ':id'         => $newId
            ]);

            // 2. Mark old version as archived and set replaced_by_id
            $stmtArchive = $db->prepare("
                UPDATE knowledge_sources
                SET status = 'archived', replaced_by_id = :new_id, updated_at = NOW()
                WHERE id = :old_id
            ");
            $stmtArchive->execute([':new_id' => $newId, ':old_id' => $oldId]);

            // 3. Purge obsolete chunks and vector embeddings of predecessor to free DB storage & RAM
            $stmtPurge = $db->prepare("DELETE FROM knowledge_items WHERE source_id = :old_id AND organization_id = :org_id");
            $stmtPurge->execute([':old_id' => $oldId, ':org_id' => $orgId]);

            // 4. Dispatch background job to chunk and embed replacement document into knowledge_items
            try {
                $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
                $stmtJob->execute([':p' => json_encode([
                    'source_id' => $newId,
                    'organization_id' => $orgId,
                    'program_id' => $programId
                ])]);
            } catch (Throwable $jobEx) {
                error_log('[KnowledgeController] Failed to dispatch chunk_and_embed for replaced version: ' . $jobEx->getMessage());
            }

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
                'status' => 'pending',
                'file_path' => $saveMeta['file_path'],
                'original_file_path' => $originalFilePath,
                'original_file_size' => $origUploadBytes,
                'file_size_bytes' => $saveMeta['file_size_bytes'],
                'token_count' => $saveMeta['token_count'],
                'expires_on' => $expiresOn
            ], "Document successfully updated to new version ({$academicVersion}). Previous version archived and embeddings queued.", 201);

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

        // Authorization: Staff users without management privileges cannot delete
        if ($userRole === 'staff' && empty($user['can_manage_structure'])) {
            Response::error('Permission denied. Staff members without management privileges cannot delete knowledge sources.', 403);
            return;
        }

        // Remove associated files from disk
        if (!empty($source['file_path'])) {
            $fullPath = dirname(__DIR__, 2) . '/' . $source['file_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
        if (!empty($source['original_file_path'])) {
            $fullOrig = dirname(__DIR__, 2) . '/' . $source['original_file_path'];
            if (file_exists($fullOrig)) {
                @unlink($fullOrig);
            }
        }
        KnowledgeFileStorage::deleteText($orgId, $id);

        $stmtDelete = $db->prepare("DELETE FROM knowledge_sources WHERE id = :id");
        $stmtDelete->execute([':id' => $id]);

        // Purge chunks from knowledge_items as well
        $db->prepare("DELETE FROM knowledge_items WHERE source_id = :id AND organization_id = :org_id")->execute([':id' => $id, ':org_id' => $orgId]);

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

            // Save refreshed text to disk
            $saveMeta = KnowledgeFileStorage::saveText($orgId, $id, $compacted['processed_content']);

            $stmtUpdate = $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path,
                    file_size_bytes = :size_bytes,
                    token_count = :tokens,
                    checksum_sha256 = :sha256,
                    keywords = :keywords,
                    content_hash = :hash,
                    status = 'pending',
                    last_fetched_at = NOW(),
                    last_reviewed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdate->execute([
                ':file_path'  => $saveMeta['file_path'],
                ':size_bytes' => $saveMeta['file_size_bytes'],
                ':tokens'     => $saveMeta['token_count'],
                ':sha256'     => $saveMeta['checksum_sha256'],
                ':keywords'   => $compacted['keywords'],
                ':hash'       => $scraped['content_hash'],
                ':id'         => $id
            ]);

            // Dispatch background re-chunking job
            try {
                $stmtJob = $db->prepare("INSERT INTO jobs (type, payload, status, run_at) VALUES ('chunk_and_embed', :p, 'pending', NOW())");
                $stmtJob->execute([':p' => json_encode(['source_id' => $id, 'organization_id' => $orgId, 'program_id' => $source['program_id']])]);
            } catch (Throwable $jobEx) {
                error_log('[KnowledgeController] Failed to dispatch chunk_and_embed on refresh: ' . $jobEx->getMessage());
            }

            AuditLogger::log('knowledge_source_refreshed', 'knowledge_source', $id, ['title' => $source['title']]);

            Response::success([
                'id' => $id,
                'status' => 'pending',
                'file_size_bytes' => $saveMeta['file_size_bytes'],
                'token_count' => $saveMeta['token_count'],
                'updated_at' => date('Y-m-d H:i:s')
            ], 'URL content refreshed and queued for vector embedding');

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

    /**
     * GET /v1/knowledge/{id}/download — Securely download the document file or text content
     */
    public function download(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);

        $db = Database::getConnection();
        if ($orgId) {
            $stmt = $db->prepare("SELECT id, organization_id, title, type, file_path, original_file_path, source_url FROM knowledge_sources WHERE id = :id AND organization_id = :org_id");
            $stmt->execute([':id' => $id, ':org_id' => $orgId]);
        } else {
            $stmt = $db->prepare("SELECT id, organization_id, title, type, file_path, original_file_path, source_url FROM knowledge_sources WHERE id = :id AND status = 'active'");
            $stmt->execute([':id' => $id]);
        }
        $doc = $stmt->fetch();

        if (!$doc) {
            http_response_code(404);
            echo "Document not found.";
            exit;
        }

        $docOrgId = (int)$doc['organization_id'];

        // 1. If original binary file exists (e.g. PDF, DOCX)
        $origPath = !empty($doc['original_file_path']) ? $doc['original_file_path'] : null;
        if (!empty($origPath)) {
            $absOrig = dirname(__DIR__, 2) . '/' . ltrim($origPath, '/');
            if (file_exists($absOrig) && is_file($absOrig)) {
                $ext = strtolower(pathinfo($absOrig, PATHINFO_EXTENSION));
                $mimeTypes = [
                    'pdf' => 'application/pdf',
                    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'doc' => 'application/msword',
                    'txt' => 'text/plain; charset=utf-8'
                ];
                $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
                $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $doc['title']) . '.' . $ext;

                header('Content-Description: File Transfer');
                header('Content-Type: ' . $mimeType);
                header('Content-Disposition: attachment; filename="' . addslashes($safeFilename) . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($absOrig));
                readfile($absOrig);
                exit;
            }
        }

        // 2. Otherwise serve the text file from storage/knowledge/{org_id}/source_{id}.txt
        $textContent = KnowledgeFileStorage::loadText($docOrgId, $id);
        if ($textContent === null && !empty($doc['file_path'])) {
            $fallbackAbs = dirname(__DIR__, 2) . '/' . ltrim($doc['file_path'], '/');
            if (file_exists($fallbackAbs) && is_file($fallbackAbs)) {
                $textContent = file_get_contents($fallbackAbs);
            }
        }

        if ($textContent === null) {
            $textContent = "Document title: " . $doc['title'] . "\nSource: " . ($doc['source_url'] ?? 'N/A');
        }

        $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $doc['title']) . '.txt';

        header('Content-Description: File Transfer');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . addslashes($safeFilename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($textContent));
        echo $textContent;
        exit;
    }

    /**
     * Helper to resolve tenant active plan and associated plan_quotas
     */
    private function getTenantPlanInfo(int $orgId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT o.plan_id, p.name as plan_name
            FROM organizations o
            LEFT JOIN plans p ON o.plan_id = p.id
            WHERE o.id = :org_id
        ");
        $stmt->execute([':org_id' => $orgId]);
        $row = $stmt->fetch();

        $planId = (int)($row['plan_id'] ?? 1);
        $planName = $row['plan_name'] ?? 'Starter';

        $stmtQ = $db->prepare("SELECT quota_key, quota_value FROM plan_quotas WHERE plan_id = :plan_id");
        $stmtQ->execute([':plan_id' => $planId]);
        $rawQuotas = $stmtQ->fetchAll();

        $quotas = [];
        foreach ($rawQuotas as $q) {
            $quotas[$q['quota_key']] = is_numeric($q['quota_value']) ? (int)$q['quota_value'] : $q['quota_value'];
        }

        return [
            'plan_id' => $planId,
            'plan_name' => $planName,
            'quotas' => $quotas
        ];
    }
}

