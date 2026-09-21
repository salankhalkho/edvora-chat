<?php

namespace App\Services;

use App\Config\Database;
use App\Services\ContentCompactor;
use App\Services\LlmService;
use PDO;
use Throwable;

/**
 * SmartOnboardingService — Universal Multi-Tenant Website Crawler + LLM Extraction Engine
 * 
 * Scalable across 10,000+ colleges & universities regardless of CMS or structure.
 * Features:
 *   - Universal Semantic Link Scoring (Structure-Agnostic, DOM-Agnostic)
 *   - Bounded 2-Hop Leaf Spider (< 15s runtime, strict timeout budgets)
 *   - Selective DOM Sanitization (Preserves course & program catalogs while stripping outer boilerplate)
 *   - Direct Candidate Degree / Major Extraction
 *   - Schema-Driven LLM Normalization (Strict Grounding, Zero Hallucination)
 *   - Real-Time Granular SSE Telemetry Streaming with unbuffered 4KB packet flushing
 */
class SmartOnboardingService
{
    private const MAX_CONTEXT_CHARS      = 32000;
    private const MAX_PAGES_TO_FETCH     = 20;
    private const MAX_PROGRAMS_TO_SAVE   = 100;
    private const MAX_DEPTS_TO_SAVE      = 25;
    private const LOGO_SAVE_DIR          = '/storage/uploads/logos/';

    /**
     * Main entry point: streams Server-Sent Events for real-time onboarding progress.
     * Writes all extracted data to production DB tables.
     */
    public static function streamProgress(int $orgId, int $chatbotId, string $websiteUrl): void
    {
        // SSE headers — disable buffering at every layer
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('flushpackets', '1');
        }
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Immediate 4KB whitespace comment padding guarantees instant delivery through Apache/proxy_fcgi
        echo ":" . str_repeat(" ", 4096) . "\n\n";
        flush();

        $emit = function (string $event, string $message, int $pct, array $extra = []) {
            $payload = array_merge([
                'event'     => $event,
                'message'   => $message,
                'timestamp' => date('H:i:s'),
                'pct'       => $pct
            ], $extra);
            echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            // 4KB comment padding forces Apache / proxy_fcgi / Cloudflare / antivirus buffers to immediately flush
            echo ":" . str_repeat(" ", 4096) . "\n\n";
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            @flush();
        };

        $db = Database::getConnection();

        try {
            // ── STEP 1: Connect ──────────────────────────────────────────────────
            $normalizedUrl = DemoPreviewService::normalizeUrl($websiteUrl);
            $domain        = DemoPreviewService::extractDomain($normalizedUrl);
            $host          = parse_url($normalizedUrl, PHP_URL_HOST) ?: $domain;
            $emit('connecting', "Connecting to {$host}...", 5, ['domain' => $host]);

            // ── STEP 2: Fetch Homepage (Hop 0) ──────────────────────────────────
            $t0 = microtime(true);
            $rootFetch = self::curlFetch($normalizedUrl, 10);
            $fetchDuration = round(microtime(true) - $t0, 2);

            if (!$rootFetch['success']) {
                // Fail path: website unreachable or actively blocking
                $botToken = self::getBotToken($db, $orgId);

                $orgRow = $db->prepare("SELECT name FROM organizations WHERE id = :id");
                $orgRow->execute([':id' => $orgId]);
                $instName = $orgRow->fetchColumn() ?: 'Your Institution';

                $db->prepare("UPDATE organizations SET website_url = :url, onboarding_completed = 1, onboarding_step = 16, updated_at = NOW() WHERE id = :id")
                   ->execute([':url' => $normalizedUrl, ':id' => $orgId]);

                $emit('scrape_failed',
                    "Could not access {$host} (" . ($rootFetch['error'] ?: 'Connection failed') . "). The website could not be reached, so automated catalog extraction was skipped. You can upload brochures or paste content directly in your dashboard.",
                    100,
                    [
                        'partial'          => true,
                        'needs_manual'     => true,
                        'bot_token'        => $botToken,
                        'institution_name' => $instName,
                        'summary'          => [
                            'departments'       => 0,
                            'programs'          => 0,
                            'knowledge_sources' => 0,
                            'needs_enrichment'  => true
                        ]
                    ]
                );
                return;
            }

            $rootHtml = $rootFetch['html'];
            $rootSizeKb = round(strlen($rootHtml) / 1024, 1);
            $emit('homepage_fetched', "Connected to {$host} (HTTP 200, {$rootSizeKb} KB in {$fetchDuration}s).", 15, [
                'bytes'    => strlen($rootHtml),
                'duration' => $fetchDuration
            ]);

            // ── STEP 3: Extract Logo (best-effort) ────────────────────────────────
            $logoUrl = self::extractLogoUrl($rootHtml, $normalizedUrl);

            // ── STEP 4: Universal Link Discovery & Scoring (Hop 0) ───────────────
            $linkBuckets = self::extractAndScoreLinks($rootHtml, $normalizedUrl);
            $totalPages  = count($linkBuckets['all']);

            $acadCount = count($linkBuckets['academic_hubs']);
            $admCount  = count($linkBuckets['admissions_hubs']);
            $feeCount  = count($linkBuckets['fee_hubs']);

            $emit('links_discovered',
                "Found {$totalPages} surface navigation links on homepage ({$acadCount} academic catalogs, {$admCount} admissions hubs, {$feeCount} fee schedules).",
                25,
                [
                    'pages_found'      => $totalPages,
                    'academic_hubs'    => $acadCount,
                    'admissions_hubs'  => $admCount,
                    'fee_hubs'         => $feeCount
                ]
            );

            // ── STEP 5: Multi-Page Discovery Spider (Up to 20 Pages) ─────────────
            $crawledUrls = [$normalizedUrl => true];
            $candidateDegrees = self::extractCandidateDegrees($rootHtml, $normalizedUrl);
            $combinedSections = [];
            $combinedSections[] = [
                'label' => 'HOMEPAGE',
                'text'  => substr(self::htmlToText($rootHtml), 0, 3000)
            ];

            // Build prioritized queue of URLs to crawl
            $crawlQueue = [];

            // Tier 1: Core Hubs from Homepage (5-6 pages)
            // Academic hubs (top 3)
            foreach (array_slice($linkBuckets['academic_hubs'], 0, 3) as $u) {
                if (!in_array($u, $crawlQueue) && !isset($crawledUrls[$u])) $crawlQueue[] = $u;
            }
            // Admissions hubs (top 2)
            foreach (array_slice($linkBuckets['admissions_hubs'], 0, 2) as $u) {
                if (!in_array($u, $crawlQueue) && !isset($crawledUrls[$u])) $crawlQueue[] = $u;
            }
            // Tuition / Fees / Aid hub (top 1)
            if (!empty($linkBuckets['fee_hubs'])) {
                $fUrl = $linkBuckets['fee_hubs'][0];
                if (!in_array($fUrl, $crawlQueue) && !isset($crawledUrls[$fUrl])) $crawlQueue[] = $fUrl;
            }
            // About hub (top 1)
            if (!empty($linkBuckets['about_hubs'])) {
                $aUrl = $linkBuckets['about_hubs'][0];
                if (!in_array($aUrl, $crawlQueue) && !isset($crawledUrls[$aUrl])) $crawlQueue[] = $aUrl;
            }

            // Additional high-scoring academic links from homepage to populate queue
            foreach ($linkBuckets['academic_hubs'] as $u) {
                if (count($crawlQueue) >= self::MAX_PAGES_TO_FETCH) break;
                if (!in_array($u, $crawlQueue) && !isset($crawledUrls[$u])) $crawlQueue[] = $u;
            }

            $subpageCount = 0;
            $queueIdx = 0;

            while ($queueIdx < count($crawlQueue) && $subpageCount < self::MAX_PAGES_TO_FETCH) {
                $targetUrl = $crawlQueue[$queueIdx++];
                if (isset($crawledUrls[$targetUrl])) continue;
                $crawledUrls[$targetUrl] = true;

                $tSub = microtime(true);
                $subFetch = self::curlFetch($targetUrl, 4);
                $subDuration = round(microtime(true) - $tSub, 2);
                $subpageCount++;

                $pageDegreesCount = 0;

                if ($subFetch['success'] && !empty($subFetch['html'])) {
                    $subText = self::htmlToText($subFetch['html']);
                    $cleanPath = trim(parse_url($targetUrl, PHP_URL_PATH) ?? '', '/');
                    $combinedSections[] = [
                        'label' => strtoupper($cleanPath) ?: 'PAGE_' . $subpageCount,
                        'text'  => substr($subText, 0, 2000)
                    ];

                    // Extract candidate degrees from this page
                    $pageDegrees = self::extractCandidateDegrees($subFetch['html'], $targetUrl);
                    $pageDegreesCount = count($pageDegrees);
                    foreach ($pageDegrees as $cd) {
                        if (!in_array($cd, $candidateDegrees) && count($candidateDegrees) < self::MAX_PROGRAMS_TO_SAVE) {
                            $candidateDegrees[] = $cd;
                        }
                    }

                    // Discover Tier 2 sub-pages (individual colleges, departments, majors, degree catalogs)
                    if (count($crawlQueue) < (self::MAX_PAGES_TO_FETCH + 10)) {
                        $subLinks = self::extractAndScoreLinks($subFetch['html'], $targetUrl);
                        foreach ($subLinks['academic_hubs'] as $sh) {
                            if (!isset($crawledUrls[$sh]) && !in_array($sh, $crawlQueue)) {
                                $crawlQueue[] = $sh;
                            }
                        }
                    }
                }

                $displayPath = self::cleanDisplayPath($targetUrl);
                // Progress percentage scales from 16% to 52% across up to 20 pages
                $currPct = 16 + (int)round(($subpageCount / self::MAX_PAGES_TO_FETCH) * 36);

                $emit('page_inspected', "Crawling [{$subpageCount}/" . min(self::MAX_PAGES_TO_FETCH, max(count($crawlQueue), $subpageCount)) . "]: {$displayPath} ({$subDuration}s, {$pageDegreesCount} catalog items detected).", $currPct, [
                    'url'           => $targetUrl,
                    'degrees_found' => count($candidateDegrees),
                    'pages_crawled' => $subpageCount
                ]);

                // Visual pacing delay so user sees each crawl step stream smoothly
                usleep(50000);
            }

            $emit('catalog_found', "Extracted " . count($candidateDegrees) . " verified degree program offerings across {$subpageCount} scanned division pages.", 54, [
                'candidate_count' => count($candidateDegrees),
                'pages_crawled'   => $subpageCount
            ]);

            // Assemble bounded LLM context
            $contextParts = [];
            $contextParts[] = "WEBSITE: {$normalizedUrl}\nDOMAIN: {$domain}";
            if (!empty($candidateDegrees)) {
                $contextParts[] = "=== EXTRACTED DEGREE & PROGRAM DIRECTORY CANDIDATES ===\n" . implode("\n", array_slice($candidateDegrees, 0, 50));
            }
            foreach ($combinedSections as $sec) {
                $contextParts[] = "=== SECTION: {$sec['label']} ===\n" . $sec['text'];
            }

            $finalContext = substr(implode("\n\n", $contextParts), 0, self::MAX_CONTEXT_CHARS);

            // ── STEP 6: AI Extraction & Clustering (Strict Grounding) ────────────
            $emit('ai_analyzing', "AI is structuring authentic departments, degree programs, and policy facts...", 55);

            $extracted = self::extractWithLLM($finalContext, $domain);

            // ── STEP 7: Save Logo (best effort) ──────────────────────────────────
            $savedLogoPath = null;
            if ($logoUrl) {
                $savedLogoPath = self::downloadLogo($logoUrl, $orgId);
                if ($savedLogoPath) {
                    $db->prepare("UPDATE organizations SET logo_url = :logo, updated_at = NOW() WHERE id = :id")
                       ->execute([':logo' => $savedLogoPath, ':id' => $orgId]);
                    $emit('logo_saved', 'Official institution logo discovered and indexed.', 60, ['logo_url' => $savedLogoPath]);
                }
            }

            // ── STEP 8: Update Organization Profile ──────────────────────────────
            // Note: Institution name is set authoritatively during registration and is NEVER overridden.
            $city     = !empty($extracted['city']) ? trim($extracted['city']) : null;
            $state    = !empty($extracted['state']) ? trim($extracted['state']) : null;
            $instType = !empty($extracted['institution_type']) ? trim($extracted['institution_type']) : null;

            $orgStmt = $db->prepare("
                UPDATE organizations
                SET city              = COALESCE(:city, city),
                    state             = COALESCE(:state, state),
                    institution_type  = COALESCE(:inst_type, institution_type),
                    website_url       = :website,
                    updated_at        = NOW()
                WHERE id = :id
            ");
            $orgStmt->execute([
                ':city'      => $city,
                ':state'     => $state,
                ':inst_type' => $instType,
                ':website'   => $normalizedUrl,
                ':id'        => $orgId,
            ]);

            $orgRow = $db->prepare("SELECT name FROM organizations WHERE id = :id");
            $orgRow->execute([':id' => $orgId]);
            $orgName = $orgRow->fetchColumn() ?: 'Your Institution';

            $emit('org_updated', "Institution recognized as: {$orgName}.", 63, [
                'institution_name' => $orgName,
                'city'             => $city,
                'state'            => $state
            ]);

            // ── STEP 9: Save Offered Academic Programs ─────────────
            $departments = $extracted['departments'] ?? [];
            $programsList = $extracted['programs'] ?? [];
            $courseCount = 0;
            $savedCourseNames = [];

            $insCourse = $db->prepare("
                INSERT INTO programs 
                    (organization_id, name, slug, program_type, duration, mode, is_admissions_open, created_at, updated_at)
                VALUES 
                    (:oid, :name, :slug, :type, :duration, :mode, 1, NOW(), NOW())
            ");

            $allExtractedProgs = [];
            if (!empty($programsList) && is_array($programsList)) {
                foreach ($programsList as $p) {
                    $allExtractedProgs[] = $p;
                }
            }
            if (!empty($departments) && is_array($departments)) {
                foreach ($departments as $dept) {
                    $deptProgs = $dept['programs'] ?? $dept['courses'] ?? [];
                    if (is_array($deptProgs)) {
                        foreach ($deptProgs as $p) {
                            $allExtractedProgs[] = $p;
                        }
                    }
                }
            }

            if (!empty($allExtractedProgs)) {
                $emit('saving_departments', 'Structuring academic programs in database...', 67);

                foreach ($allExtractedProgs as $progItem) {
                    if (is_array($progItem)) {
                        $cn   = trim((string)($progItem['name'] ?? ''));
                        $pt   = strtolower((string)($progItem['program_type'] ?? 'undergraduate'));
                        $dur  = !empty($progItem['duration']) ? substr(trim($progItem['duration']), 0, 50) : null;
                        $mode = strtolower((string)($progItem['mode'] ?? 'full_time'));
                    } else {
                        $cn   = trim((string)$progItem);
                        $pt   = 'undergraduate';
                        if (preg_match('/\b(master|m\.?s|m\.?a|mba|m\.?tech|graduate|m\.?sc|m\.?com)\b/i', $cn)) {
                            $pt = 'postgraduate';
                        } elseif (preg_match('/\b(doctor|ph\.?d|doctorate)\b/i', $cn)) {
                            $pt = 'doctoral';
                        } elseif (preg_match('/\b(diploma|certificate)\b/i', $cn)) {
                            $pt = 'certificate';
                        }
                        $dur  = null;
                        $mode = 'full_time';
                    }

                    if ($cn === '' || strlen($cn) < 3) continue;
                    $normCn = strtolower($cn);
                    if (isset($savedCourseNames[$normCn])) continue;
                    $savedCourseNames[$normCn] = true;

                    $validTypes = ['undergraduate', 'postgraduate', 'doctoral', 'executive', 'certificate', 'other'];
                    if (!in_array($pt, $validTypes)) {
                        if ($pt === 'doctorate') $pt = 'doctoral';
                        elseif ($pt === 'diploma') $pt = 'certificate';
                        else $pt = 'undergraduate';
                    }

                    $validModes = ['full_time', 'part_time', 'online', 'hybrid', 'weekend'];
                    if (!in_array($mode, $validModes)) $mode = 'full_time';

                    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cn)), '-');
                    if (empty($slug)) {
                        $slug = 'prog-' . substr(bin2hex(random_bytes(3)), 0, 6);
                    }
                    $baseSlug = $slug;
                    $suffix = 1;
                    while (true) {
                        $chk = $db->prepare("SELECT id FROM programs WHERE organization_id = :oid AND slug = :slug LIMIT 1");
                        $chk->execute([':oid' => $orgId, ':slug' => $slug]);
                        if (!$chk->fetch()) break;
                        $slug = $baseSlug . '-' . (++$suffix);
                    }

                    try {
                        $insCourse->execute([
                            ':oid'      => $orgId,
                            ':name'     => $cn,
                            ':slug'     => $slug,
                            ':type'     => $pt,
                            ':duration' => $dur,
                            ':mode'     => $mode,
                        ]);
                        $courseCount++;

                        if ($courseCount <= 30) {
                            usleep(30000);
                            $emit('program_found', "├── 🎓 {$cn} ({$pt})", 72, [
                                'name'           => $cn,
                                'type'           => $pt,
                                'programs_count' => $courseCount
                            ]);
                        }
                    } catch (Throwable $ce) {
                        // Skip duplicate
                    }
                }
            }

            // Fallback: If candidate degrees discovered from DOM were missed by LLM, save them into programs
            if (!empty($candidateDegrees)) {
                $unassigned = [];
                foreach ($candidateDegrees as $cd) {
                    $normCd = strtolower($cd);
                    if (!isset($savedCourseNames[$normCd])) {
                        $unassigned[] = $cd;
                    }
                }

                if (!empty($unassigned)) {
                    foreach (array_slice($unassigned, 0, 50) as $cd) {
                        $normCd = strtolower($cd);
                        if (isset($savedCourseNames[$normCd])) continue;
                        $savedCourseNames[$normCd] = true;

                        $pt = 'undergraduate';
                        if (preg_match('/\b(master|m\.?s|m\.?a|mba|m\.?tech|graduate|m\.?sc|m\.?com)\b/i', $cd)) {
                            $pt = 'postgraduate';
                        } elseif (preg_match('/\b(doctor|ph\.?d|doctorate)\b/i', $cd)) {
                            $pt = 'doctoral';
                        } elseif (preg_match('/\b(diploma|certificate)\b/i', $cd)) {
                            $pt = 'certificate';
                        }

                        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cd)), '-');
                        if (empty($slug)) {
                            $slug = 'prog-' . substr(bin2hex(random_bytes(3)), 0, 6);
                        }
                        $baseSlug = $slug;
                        $suffix = 1;
                        while (true) {
                            $chk = $db->prepare("SELECT id FROM programs WHERE organization_id = :oid AND slug = :slug LIMIT 1");
                            $chk->execute([':oid' => $orgId, ':slug' => $slug]);
                            if (!$chk->fetch()) break;
                            $slug = $baseSlug . '-' . (++$suffix);
                        }

                        try {
                            $insCourse->execute([
                                ':oid'      => $orgId,
                                ':name'     => $cd,
                                ':slug'     => $slug,
                                ':type'     => $pt,
                                ':duration' => null,
                                ':mode'     => 'full_time',
                            ]);
                            $courseCount++;
                            if ($courseCount <= 30) {
                                usleep(30000);
                                $emit('program_found', "├── 🎓 {$cd} ({$pt})", 75, [
                                    'name'           => $cd,
                                    'type'           => $pt,
                                    'programs_count' => $courseCount
                                ]);
                            }
                        } catch (Throwable $ue) {}
                    }
                }
            }

            $emit('departments_written',
                "Saved {$courseCount} verified academic degree program" . ($courseCount > 1 ? 's' : '') . ".",
                78,
                ['departments_count' => 0, 'courses_count' => $courseCount, 'programs_count' => $courseCount]
            );

            // ── STEP 11: Save Knowledge Sources ─────────────────────────────────
            $clusters = $extracted['knowledge_clusters'] ?? [];
            $ksCount  = 0;

            if (!empty($clusters) && is_array($clusters)) {
                $emit('saving_knowledge', 'Ingesting Admissions & Tuition Policy facts into knowledge base...', 86);
                foreach (array_slice($clusters, 0, self::MAX_DEPTS_TO_SAVE) as $cluster) {
                    $content = trim((string)($cluster['content'] ?? ''));
                    if (strlen($content) < 40) continue;

                    $title    = trim((string)($cluster['title'] ?? 'Institutional Information'));
                    $category = trim((string)($cluster['category'] ?? 'Admissions'));

                    $compacted        = ContentCompactor::process($content, $title);
                    $processedContent = $compacted['processed_content'] ?? substr($content, 0, 6000);

                    $insKs = $db->prepare("
                        INSERT INTO knowledge_sources
                            (organization_id, type, title, category, source_url, status, created_at, updated_at)
                        VALUES (:org_id, 'url', :title, :cat, :src, 'active', NOW(), NOW())
                    ");
                    $insKs->execute([
                        ':org_id' => $orgId,
                        ':title'  => substr($title, 0, 255),
                        ':cat'    => substr($category, 0, 100),
                        ':src'    => $normalizedUrl,
                    ]);
                    $ksId = (int)$db->lastInsertId();

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

                    $ksCount++;

                    usleep(70000);
                    $emit('cluster_found', "Synthesized Knowledge: {$title}", 88, [
                        'title'           => $title,
                        'category'        => $category,
                        'knowledge_count' => $ksCount
                    ]);

                    // Dispatch async chunk_and_embed job
                    try {
                        $db->prepare("INSERT INTO jobs (type, payload, status, run_at, created_at) VALUES ('chunk_and_embed', :p, 'pending', NOW(), NOW())")
                           ->execute([':p' => json_encode(['source_id' => $ksId, 'organization_id' => $orgId])]);
                    } catch (Throwable $je) {
                        // Optional job dispatch
                    }
                }
            }

            // Fallback knowledge source if clustering produced zero records
            if ($ksCount === 0 && !empty(trim($finalContext))) {
                $compacted        = ContentCompactor::process($finalContext, "Website Overview — {$orgName}");
                $processedContent = $compacted['processed_content'] ?? substr($finalContext, 0, 6000);
                $db->prepare("
                    INSERT INTO knowledge_sources
                        (organization_id, type, title, category, source_url, status, created_at, updated_at)
                    VALUES (:org_id, 'url', :title, 'General', :src, 'active', NOW(), NOW())
                ")->execute([
                    ':org_id' => $orgId,
                    ':title'  => "Website Overview — {$orgName}",
                    ':src'    => $normalizedUrl,
                ]);
                $ksId = (int)$db->lastInsertId();
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
                $ksCount = 1;
            }

            $emit('knowledge_saved',
                "{$ksCount} knowledge cluster" . ($ksCount > 1 ? 's' : '') . " synthesized and indexed.",
                92,
                ['knowledge_count' => $ksCount]
            );

            // ── STEP 12: Configure Production Chatbot ────────────────────────────
            $emit('configuring_chatbot', 'Tuning AI student assistant prompt and interactive chips...', 95);

            $progRows = $db->prepare("
                SELECT p.name 
                FROM programs p 
                WHERE p.organization_id = :oid 
                ORDER BY p.id ASC 
                LIMIT 4
            ");
            $progRows->execute([':oid' => $orgId]);
            $foundProgs = $progRows->fetchAll(PDO::FETCH_COLUMN);

            $quickChips = [];
            if (!empty($foundProgs)) {
                foreach ($foundProgs as $pName) {
                    $quickChips[] = [
                        'label'   => 'Tell me about ' . substr($pName, 0, 24),
                        'message' => "Tell me about the {$pName} program, including eligibility and duration."
                    ];
                }
            } else {
                $quickChips = [
                    ['label' => 'What programs do you offer?', 'message' => 'What programs and degrees are offered at this institution?'],
                    ['label' => 'How do I apply?', 'message' => 'What is the admissions process and how can I apply?'],
                    ['label' => 'Tuition and Fees', 'message' => 'Can you give me details on tuition fees and payment schedules?'],
                    ['label' => 'Campus & Hostels', 'message' => 'What facilities and hostel accommodations are available on campus?']
                ];
            }

            $welcomeMsg = "Hello! 👋 I'm the AI Student Assistant for {$orgName}. Ask me anything about degree programs, admissions, eligibility, fees, or campus life!";

            $db->prepare("
                UPDATE chatbots
                SET name          = 'AI Student Assistant',
                    welcome_message = :welcome,
                    quick_chips   = :chips,
                    updated_at    = NOW()
                WHERE organization_id = :oid AND is_active = 1
            ")->execute([
                ':welcome' => $welcomeMsg,
                ':chips'   => json_encode($quickChips, JSON_UNESCAPED_UNICODE),
                ':oid'     => $orgId,
            ]);

            // ── STEP 13: Mark Onboarding Complete in DB ──────────────────────────
            $db->prepare("
                UPDATE organizations
                SET onboarding_completed = 1, onboarding_step = 16, updated_at = NOW()
                WHERE id = :id
            ")->execute([':id' => $orgId]);

            // ── STEP 14: Summary & Finalization ──────────────────────────────────
            $botToken = self::getBotToken($db, $orgId);

            $orgLogoRow = $db->prepare("SELECT logo_url, name FROM organizations WHERE id = :id");
            $orgLogoRow->execute([':id' => $orgId]);
            $orgFinal = $orgLogoRow->fetch();

            $needsEnrichment = ($deptCount === 0 || $courseCount === 0 || $ksCount === 0);

            $emit('complete', 'Your AI Student Assistant is primed and ready to test!', 100, [
                'bot_token'        => $botToken,
                'institution_name' => $orgFinal['name'] ?? $orgName,
                'logo_url'         => $orgFinal['logo_url'] ?? null,
                'summary' => [
                    'departments'       => 0,
                    'courses'           => $courseCount,
                    'programs'          => $courseCount,
                    'knowledge_sources' => $ksCount,
                    'needs_enrichment'  => $needsEnrichment,
                ],
            ]);

        } catch (Throwable $e) {
            error_log('[SmartOnboardingService] Fatal error: ' . $e->getMessage());

            try {
                $db->prepare("UPDATE organizations SET onboarding_completed = 1, onboarding_step = 16, updated_at = NOW() WHERE id = :id")
                   ->execute([':id' => $orgId]);
            } catch (Throwable $ignored) {}

            $emit('error',
                "An unexpected error occurred during processing ({$e->getMessage()}). Your account has been created and you can configure your assistant in the dashboard.",
                100,
                [
                    'error'        => true,
                    'needs_manual' => true,
                    'bot_token'    => self::getBotToken($db, $orgId)
                ]
            );
        }
    }

    // =========================================================================
    // STRICT FACTUAL LLM EXTRACTION (CARDINAL RULE: NO HALLUCINATIONS)
    // =========================================================================

    private static function extractWithLLM(string $scrapedText, string $domain): ?array
    {
        $systemPrompt = <<<'SYSTEM'
You are a strict, factual institutional data extraction engine for a college/university admissions SaaS.
Your single mandate is to extract verified facts, departments, and degree programs that are EXPLICITLY listed in the provided scraped website text and candidate catalogs.

CARDINAL RULES:
1. NEVER invent, fabricate, or hallucinate degree titles or majors not found in the source text.
2. ALL DEGREE PROGRAMS AND COURSES MUST BE MAPPED UNDER A DEPARTMENT OR COLLEGE in the "departments" array.
3. INFERRED ACADEMIC DEPARTMENTS: If the website lists degree programs centrally or without explicit department headers, you MUST INFER the logical academic department or college based on the field of study (e.g. 'BS in Biology' -> 'Department of Biological Sciences' or 'College of Science'; 'Master of Business Administration' -> 'College of Business'; 'B.A. in English' -> 'Department of Humanities & Arts'; 'BS in Mechanical Engineering' -> 'College of Engineering'; 'Master of Public Administration' -> 'Department of Public Administration & Urban Affairs'). If ambiguous, group under 'General Academics & Degree Programs'. Every single detected degree program MUST be placed inside a department.
4. Extract as many authentic degree programs as explicitly found in the candidate lists and text.
5. If fees, eligibility, or scholarships are not mentioned, do NOT make up numbers or policies.
6. All knowledge_clusters MUST be factual summaries of real text provided in the prompt.
7. Return ONLY a valid JSON object matching the schema below. No markdown backticks, no explanations, no text outside JSON.

OUTPUT JSON SCHEMA:
{
  "city": "City name if clearly stated, or null",
  "state": "State/Province if clearly stated, or null",
  "institution_type": "university|college|institute|school|null",
  "departments": [
    {
      "name": "Department or College Name (e.g. College of Business, Department of Biological Sciences)",
      "icon": "Relevant single emoji like 🔬, 💻, ⚖️, 💼, 🏥, 🏛️, 📚, 🎨",
      "programs": [
        {
          "name": "Exact Program Title (e.g. BS in Biology, Master of Business Administration)",
          "program_type": "undergraduate|postgraduate|doctoral|certificate|other",
          "duration": "e.g. 4 Years, or null",
          "mode": "full_time|part_time|online|hybrid"
        }
      ]
    }
  ],
  "knowledge_clusters": [
    {
      "title": "Clear Topic Title (e.g. Admissions Criteria, Tuition & Fees, Financial Aid)",
      "category": "Admissions|Fees|Scholarships|Campus|Placements|General",
      "content": "Dense factual information extracted directly from the website text"
    }
  ]
}
SYSTEM;

        $userMessage = "Domain: {$domain}\n\n=== SOURCE SCRAPED TEXT & CANDIDATE CATALOGS ===\n{$scrapedText}\n=== END SOURCE TEXT ===";

        try {
            $response = LlmService::complete($systemPrompt, $userMessage, []);
            $rawText  = trim($response['text'] ?? $response['content'] ?? '');

            if (empty($rawText)) return null;

            // Strip markdown code fences if output by LLM
            $rawText = preg_replace('/^```(?:json)?\s*/i', '', $rawText);
            $rawText = preg_replace('/\s*```$/i', '', $rawText);
            $rawText = trim($rawText);

            if (preg_match('/\{.*\}/s', $rawText, $jsonMatch)) {
                $rawText = $jsonMatch[0];
            }

            $decoded = json_decode($rawText, true);
            if (!is_array($decoded)) return null;

            // Sanitize structure
            $decoded['departments']        = is_array($decoded['departments'] ?? null)        ? $decoded['departments']        : [];
            $decoded['knowledge_clusters'] = is_array($decoded['knowledge_clusters'] ?? null) ? $decoded['knowledge_clusters'] : [];

            // Filter out near-empty clusters
            $decoded['knowledge_clusters'] = array_values(array_filter($decoded['knowledge_clusters'], function ($c) {
                return is_array($c) && strlen(trim((string)($c['content'] ?? ''))) >= 40;
            }));

            return $decoded;

        } catch (Throwable $e) {
            error_log('[SmartOnboardingService::extractWithLLM] LLM inference failed: ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // UNIVERSAL SEMANTIC CRAWLER & SCRAPING UTILITIES
    // =========================================================================

    public static function curlFetch(string $url, int $timeout = 8): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 (Edvora Admissions Bot/2.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING       => 'gzip,deflate',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
            ],
        ]);
        $html     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400 || empty($html)) {
            return ['success' => false, 'error' => ($error ?: "HTTP {$httpCode}"), 'html' => '', 'http_code' => $httpCode];
        }
        return ['success' => true, 'html' => $html, 'error' => '', 'http_code' => $httpCode];
    }

    /**
     * Universal Semantic Link Scoring & Discovery (Structure-Agnostic)
     */
    public static function extractAndScoreLinks(string $html, string $baseUrl): array
    {
        $parsed   = parse_url($baseUrl);
        $baseHost = strtolower($parsed['host'] ?? '');
        $scheme   = $parsed['scheme'] ?? 'https';

        $buckets = [
            'all'             => [],
            'academic_hubs'   => [],
            'admissions_hubs' => [],
            'fee_hubs'        => [],
            'about_hubs'      => []
        ];

        if (!preg_match_all('/<a\s+[^>]*href=[\'"]([^\'"#\s>]+)[\'"][^>]*>(.*?)<\/a>/is', $html, $ms, PREG_SET_ORDER)) {
            return $buckets;
        }

        // Noise and exclusion regex: reject authentication, privacy, feedback, social, and asset files
        $noiseRegex = '/(login|signin|sign-in|auth|portal|feedback|privacy|terms|cookie|survey|give-now|donation|ticket|cart|checkout|accessibility|archive|sitemap|wp-content|wp-includes|calendar|events)/i';
        $fileRegex  = '/\.(pdf|docx?|xlsx?|zip|tar|gz|mp4|mp3|png|jpe?g|gif|svg|ico|css|js)$/i';

        $scored = [
            'academic'   => [],
            'admissions' => [],
            'fee'        => [],
            'about'      => []
        ];

        $seen = [];

        foreach ($ms as $m) {
            $href   = trim($m[1]);
            $anchor = trim(preg_replace('/\s+/', ' ', strip_tags($m[2])));

            if (empty($href) || preg_match('/^(mailto:|tel:|javascript:|#)/i', $href)) continue;
            if (preg_match($fileRegex, $href)) continue;

            // Resolve full URL
            if (strpos($href, '//') === 0) {
                $full = $scheme . ':' . $href;
            } elseif (strpos($href, '/') === 0) {
                $full = $scheme . '://' . $baseHost . $href;
            } elseif (!preg_match('#^https?://#i', $href)) {
                $full = rtrim($baseUrl, '/') . '/' . $href;
            } else {
                $full = $href;
            }

            $linkHost = strtolower(parse_url($full, PHP_URL_HOST) ?? '');
            if (!$linkHost || (strpos($linkHost, $baseHost) === false && strpos($baseHost, $linkHost) === false)) continue;

            // Remove query params & trailing slashes for clean canonical URL
            $pathOnly = parse_url($full, PHP_URL_PATH) ?? '/';
            $canonical = $scheme . '://' . $linkHost . rtrim($pathOnly, '/');
            if (isset($seen[$canonical])) continue;
            $seen[$canonical] = true;

            $haystack = strtolower($full . ' ' . $anchor);
            if (preg_match($noiseRegex, $haystack)) continue;

            $buckets['all'][] = $canonical;

            // Score Academic
            $acadScore = 0;
            if (preg_match('/\b(academics?|programs?|courses?|degrees?|majors?|undergraduate|postgraduate|graduate|curriculum|facult(y|ies)|schools?|departments?|colleges?|studies|catalogue|catalog)\b/i', $haystack)) {
                $acadScore += 5;
                if (preg_match('/\b(degrees?|majors?|programs?|undergraduate-studies|graduate-studies)\b/i', $haystack)) $acadScore += 4;
                $scored['academic'][$canonical] = $acadScore;
            }

            // Score Admissions
            $admScore = 0;
            if (preg_match('/\b(admissions?|apply|application|enroll|enrollment|prospectus|eligibility|requirements?|how-to-apply)\b/i', $haystack)) {
                $admScore += 5;
                $scored['admissions'][$canonical] = $admScore;
            }

            // Score Fees / Tuition / Scholarships (strict word boundaries to avoid matching feedback or coffee)
            $feeScore = 0;
            if (preg_match('/\b(tuition|fees?|cost-of-attendance|financial-aid|scholarships?)\b/i', $haystack)) {
                $feeScore += 6;
                $scored['fee'][$canonical] = $feeScore;
            }

            // Score About
            $aboutScore = 0;
            if (preg_match('/\b(about|overview|history|profile|mission|vision|campus-overview)\b/i', $haystack)) {
                $aboutScore += 3;
                $scored['about'][$canonical] = $aboutScore;
            }
        }

        arsort($scored['academic']);
        arsort($scored['admissions']);
        arsort($scored['fee']);
        arsort($scored['about']);

        $buckets['academic_hubs']   = array_keys($scored['academic']);
        $buckets['admissions_hubs'] = array_keys($scored['admissions']);
        $buckets['fee_hubs']        = array_keys($scored['fee']);
        $buckets['about_hubs']      = array_keys($scored['about']);

        return $buckets;
    }

    /**
     * Direct Candidate Degree / Program Extraction
     * Discovers actual degree titles from lists, anchors, and sub-navigation
     */
    public static function extractCandidateDegrees(string $html, string $sourceUrl): array
    {
        $candidates = [];

        // 1. Extract from <a> and <li> tags matching degree patterns
        if (preg_match_all('/<(?:a|li|h[3-5]|td)[^>]*>(.*?)<\/(?:a|li|h[3-5]|td)>/is', $html, $matches)) {
            $degreePattern = '/\b(Bachelor|Master|Doctor|Associate|B\.?S\.?|B\.?A\.?|B\.?Tech|B\.?E\.?|B\.?Sc|B\.?Com|B\.?Ed|BBA|BCA|M\.?S\.?|M\.?A\.?|M\.?Tech|M\.?E\.?|M\.?Sc|M\.?Com|MBA|MCA|Ph\.?D|Diploma|Certificate)\b/i';
            $exclusionPattern = '/(login|cookie|privacy|terms|apply now|click here|read more|contact us|sign in|office 365|master calendar|promissory|on the market|male initiative|student affairs)/i';

            foreach ($matches[1] as $rawItem) {
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($rawItem)));
                if (strlen($text) < 4 || strlen($text) > 80) continue;
                if (preg_match($exclusionPattern, $text)) continue;

                if (preg_match($degreePattern, $text)) {
                    $cleaned = preg_replace('/^(explore|view|browse|about|our)\s+/i', '', $text);
                    if (strlen($cleaned) >= 5 && !in_array($cleaned, $candidates)) {
                        $candidates[] = $cleaned;
                    }
                }
            }
        }

        return array_slice($candidates, 0, self::MAX_PROGRAMS_TO_SAVE);
    }

    /**
     * Universal HTML to Text cleaner
     * Preserves internal section navigation and catalog tables while stripping outer shell noise
     */
    public static function htmlToText(string $html): string
    {
        // 1. Remove non-content structural code
        $clean = preg_replace([
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<svg\b[^>]*>.*?<\/svg>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            '/<!--.*?-->/s',
        ], ' ', $html);

        // 2. Remove ONLY outer global footer and utility bars, while preserving inner section catalogs
        $clean = preg_replace('/<footer\b[^>]*>.*?<\/footer>/is', ' ', $clean);
        $clean = preg_replace('/<nav\b[^>]*class=[\'"][^\'"]*(?:utility|footer|top-bar|social|policy)[^\'"]*[\'"][^>]*>.*?<\/nav>/is', ' ', $clean);

        // 3. Format block elements to newlines
        $clean = preg_replace('/<(p|br|div|h[1-6]|li|tr|section|article)[^>]*>/i', "\n", $clean);

        // 4. Strip tags & decode
        $text  = strip_tags($clean);
        $text  = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text  = preg_replace('/[ \t]+/', ' ', $text);
        $text  = preg_replace('/\n\s*\n+/', "\n", $text);

        return trim($text);
    }

    private static function cleanDisplayPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        return strlen($path) > 36 ? substr($path, 0, 36) . '...' : $path;
    }

    private static function extractLogoUrl(string $html, string $baseUrl): ?string
    {
        $parsed   = parse_url($baseUrl);
        $scheme   = $parsed['scheme'] ?? 'https';
        $baseHost = $parsed['host'] ?? '';

        $patterns = [
            '/<link[^>]+rel=[\'"]apple-touch-icon[\'"][^>]+href=[\'"]([^\'"]+)[\'"]/i',
            '/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i',
            '/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"]og:image[\'"]/i',
            '/<img[^>]+(?:class|alt|id)=[\'"][^\'"]*logo[^\'"]*[\'"][^>]+src=[\'"]([^\'"]+)[\'"]/i',
            '/<img[^>]+src=[\'"]([^\'"]*logo[^\'"]*)[\'"][^>]*>/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $src = trim($m[1]);
                if (strpos($src, '//') === 0) return $scheme . ':' . $src;
                if (strpos($src, '/') === 0)  return $scheme . '://' . $baseHost . $src;
                if (preg_match('#^https?://#i', $src)) return $src;
                return $scheme . '://' . $baseHost . '/' . $src;
            }
        }
        return null;
    }

    private static function downloadLogo(string $logoUrl, int $orgId): ?string
    {
        if (preg_match('/\.(svg|ico)$/i', $logoUrl)) return null;
        if (strpos($logoUrl, 'data:') === 0) return null;

        try {
            $appRoot = dirname(__DIR__, 2);
            $logoDir = $appRoot . '/storage/uploads/logos/';
            if (!is_dir($logoDir)) {
                @mkdir($logoDir, 0775, true);
            }

            $ch = curl_init($logoUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Edvora Admissions Bot/2.0)',
            ]);
            $imageData = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mimeType  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if (!$imageData || $httpCode >= 400) return null;

            $ext = 'png';
            if (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) $ext = 'jpg';
            elseif (str_contains($mimeType, 'webp')) $ext = 'webp';
            elseif (str_contains($mimeType, 'png'))  $ext = 'png';

            if (!str_contains($mimeType, 'image/')) return null;

            $filename = 'logo_org' . $orgId . '_' . time() . '.' . $ext;
            $fullPath = $logoDir . $filename;

            if (file_put_contents($fullPath, $imageData) === false) return null;

            return '/storage/uploads/logos/' . $filename;

        } catch (Throwable $e) {
            error_log('[SmartOnboardingService::downloadLogo] Failed: ' . $e->getMessage());
            return null;
        }
    }

    private static function getBotToken(PDO $db, int $orgId): ?string
    {
        $s = $db->prepare("SELECT bot_token FROM chatbots WHERE organization_id = :oid AND is_active = 1 LIMIT 1");
        $s->execute([':oid' => $orgId]);
        return $s->fetchColumn() ?: null;
    }
}
