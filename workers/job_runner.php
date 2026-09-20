<?php

// Background Job Worker Runner for edvora.chat

spl_autoload_register(function ($class) {
    $prefix  = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';
    $len     = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file          = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Config\Database;
use App\Config\Env;
use App\Services\ContentCompactor;
use App\Services\DocumentParser;
use App\Services\EmbeddingService;
use App\Services\KnowledgeChunker;
use App\Services\ProgramTextGenerator;
use App\Services\UrlScraper;

Env::load();

echo "[" . date('Y-m-d H:i:s') . "] Starting edvora.chat Supervisor Worker Process...\n";

// Retry schedule for enrich_keywords jobs (indexed by attempt number that just failed):
// After attempt 1 → wait 30 min, after attempt 2 → wait 60 min, after attempt 3 → wait 5 hr, attempt 4 → permanently fail
const ENRICH_RETRY_DELAYS = [
    1 => 1800,   // 30 minutes
    2 => 3600,   // 60 minutes
    3 => 18000,  // 5 hours
];
const ENRICH_MAX_ATTEMPTS = 4;

while (true) {
    try {
        $db = Database::getConnection();

        // Fetch pending job with SELECT ... FOR UPDATE
        $stmt = $db->query("
            SELECT * FROM jobs
            WHERE status = 'pending' AND run_at <= NOW()
            ORDER BY id ASC
            LIMIT 1
        ");
        $job = $stmt->fetch();

        if ($job) {
            $jobId   = (int)$job['id'];
            $type    = $job['type'];
            $payload = json_decode($job['payload'], true) ?? [];

            // Mark job as running and increment attempt counter
            $db->exec("UPDATE jobs SET status = 'running', attempts = attempts + 1 WHERE id = {$jobId}");

            // Current attempt number (after the increment above)
            $attemptNumber = (int)$job['attempts'] + 1;

            echo "[" . date('Y-m-d H:i:s') . "] Processing Job #{$jobId} ({$type}), attempt #{$attemptNumber}...\n";

            try {
                if ($type === 'FetchUrlContent') {
                    $sourceId = (int)($payload['knowledge_source_id'] ?? 0);
                    $url      = $payload['url'] ?? '';

                    $scraped  = UrlScraper::scrape($url);
                    $compacted = ContentCompactor::process($scraped['raw_content'], $scraped['title']);

                    $stmtUpdate = $db->prepare("
                        UPDATE knowledge_sources
                        SET raw_content = :raw, processed_content = :processed, keywords = :keywords, content_hash = :hash, status = 'active', last_fetched_at = NOW(), updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtUpdate->execute([
                        ':raw'       => $scraped['raw_content'],
                        ':processed' => $compacted['processed_content'],
                        ':keywords'  => $compacted['keywords'],
                        ':hash'      => $scraped['content_hash'],
                        ':id'        => $sourceId
                    ]);

                } elseif ($type === 'ProcessDocument') {
                    $sourceId = (int)($payload['knowledge_source_id'] ?? 0);
                    $filePath = dirname(__DIR__) . '/' . ($payload['file_path'] ?? '');
                    $filename = $payload['filename'] ?? 'document.pdf';

                    $rawText   = DocumentParser::parse($filePath, $filename);
                    $compacted = ContentCompactor::process($rawText);

                    $stmtUpdate = $db->prepare("
                        UPDATE knowledge_sources
                        SET raw_content = :raw, processed_content = :processed, keywords = :keywords, status = 'active', updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtUpdate->execute([
                        ':raw'       => $rawText,
                        ':processed' => $compacted['processed_content'],
                        ':keywords'  => $compacted['keywords'],
                        ':id'        => $sourceId
                    ]);


                } elseif ($type === 'embed_program') {
                    // ──────────────────────────────────────────────────────────
                    // embed_program: Generate TXT from programs row, chunk it,
                    // embed each chunk via OpenAI, and save to knowledge_items.
                    // If re_embed=true, delete existing knowledge_items first.
                    // ──────────────────────────────────────────────────────────
                    $programId = (int)($payload['program_id'] ?? 0);
                    $orgId     = (int)($payload['organization_id'] ?? 0);
                    $reEmbed   = (bool)($payload['re_embed'] ?? false);

                    if (!$programId || !$orgId) {
                        throw new \Exception("embed_program: missing program_id or organization_id in payload.");
                    }

                    // 1. Load the program row
                    $stmtProg = $db->prepare("SELECT * FROM programs WHERE id = ? AND organization_id = ?");
                    $stmtProg->execute([$programId, $orgId]);
                    $program = $stmtProg->fetch(\PDO::FETCH_ASSOC);

                    if (!$program) {
                        throw new \Exception("embed_program: program #{$programId} not found for org #{$orgId}.");
                    }

                    // 2. Generate embedding-friendly sentences
                    $txtContent = ProgramTextGenerator::generateTxt($program);

                    // 3. Write TXT file
                    $txtDir  = dirname(__DIR__) . "/storage/programs/{$orgId}";
                    if (!is_dir($txtDir)) {
                        @mkdir($txtDir, 0775, true);
                    }
                    $txtPath = "{$txtDir}/program_{$programId}.txt";
                    file_put_contents($txtPath, $txtContent);

                    // 4. Upsert knowledge_sources row for this program TXT
                    $stmtSrc = $db->prepare("
                        SELECT id FROM knowledge_sources
                        WHERE program_id = ? AND organization_id = ? AND type = 'program_txt'
                        LIMIT 1
                    ");
                    $stmtSrc->execute([$programId, $orgId]);
                    $existingSrc = $stmtSrc->fetch(\PDO::FETCH_ASSOC);

                    $relPath = "storage/programs/{$orgId}/program_{$programId}.txt";

                    if ($existingSrc) {
                        $sourceId = (int)$existingSrc['id'];
                        $db->prepare("
                            UPDATE knowledge_sources
                            SET raw_content = ?, processed_content = ?, file_path = ?, status = 'active', updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$txtContent, $txtContent, $relPath, $sourceId]);
                    } else {
                        $db->prepare("
                            INSERT INTO knowledge_sources
                                (organization_id, program_id, type, title, raw_content, processed_content, file_path, status)
                            VALUES (?, ?, 'program_txt', ?, ?, ?, ?, 'active')
                        ")->execute([
                            $orgId,
                            $programId,
                            ($program['course_name'] ?? 'Program') . ' — Auto-generated Knowledge',
                            $txtContent,
                            $txtContent,
                            $relPath,
                        ]);
                        $sourceId = (int)$db->lastInsertId();
                    }

                    // 5. If re-embedding, delete existing knowledge_items for this source
                    if ($reEmbed || $existingSrc) {
                        $db->prepare("DELETE FROM knowledge_items WHERE source_id = ? AND organization_id = ?")
                           ->execute([$sourceId, $orgId]);
                    }

                    // 6. Chunk the TXT content
                    $chunks = KnowledgeChunker::chunkText($txtContent);

                    if (empty($chunks)) {
                        throw new \Exception("embed_program: no chunks generated for program #{$programId}.");
                    }

                    // 7. Embed each chunk and insert into knowledge_items
                    $stmtInsert = $db->prepare("
                        INSERT INTO knowledge_items
                            (organization_id, source_id, program_id, content, page, embedding, created_at, updated_at)
                        VALUES (?, ?, ?, ?, NULL, ?, NOW(), NOW())
                    ");

                    foreach ($chunks as $chunk) {
                        $vector    = EmbeddingService::embed($chunk);
                        $embedding = json_encode($vector);
                        $stmtInsert->execute([$orgId, $sourceId, $programId, $chunk, $embedding]);
                    }

                    echo "[" . date('Y-m-d H:i:s') . "] embed_program #{$programId}: " . count($chunks) . " chunks embedded and saved to knowledge_items.\n";

                } elseif ($type === 'evaluate_content_health') {
                    // ──────────────────────────────────────────────────────────
                    // Document Validity & Content Health Evaluation
                    // Auto-transitions expired/expiring_soon statuses across orgs
                    // ──────────────────────────────────────────────────────────
                    $orgId = (int)($payload['organization_id'] ?? 0);
                    $orgFilter = $orgId > 0 ? "AND organization_id = {$orgId}" : "";

                    // 1. Mark expired sources (expires_on < today and not already archived/expired)
                    $db->exec("
                        UPDATE knowledge_sources
                        SET status = 'expired', updated_at = NOW()
                        WHERE expires_on IS NOT NULL
                          AND expires_on < CURDATE()
                          AND status NOT IN ('archived', 'expired')
                          {$orgFilter}
                    ");

                    // 2. Mark sources expiring within 30 days
                    $db->exec("
                        UPDATE knowledge_sources
                        SET status = 'expiring_soon', updated_at = NOW()
                        WHERE expires_on IS NOT NULL
                          AND expires_on >= CURDATE()
                          AND expires_on <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                          AND status = 'active'
                          {$orgFilter}
                    ");

                    echo "[" . date('Y-m-d H:i:s') . "] Completed evaluate_content_health for org: " . ($orgId ?: 'ALL') . "\n";
                }

                // Mark job as done
                $db->exec("UPDATE jobs SET status = 'done' WHERE id = {$jobId}");
                echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} finished successfully.\n";

            } catch (Throwable $e) {
                $errorMsg = substr($e->getMessage(), 0, 500);

                if ($type === 'enrich_keywords') {
                    // ──────────────────────────────────────────────────────────
                    // Retry schedule for enrichment failures.
                    // The knowledge source itself stays 'active' — content is
                    // valid and accessible via Tier 2 fallback retrieval.
                    // Only the job is rescheduled or permanently failed.
                    // ──────────────────────────────────────────────────────────
                    if ($attemptNumber < ENRICH_MAX_ATTEMPTS) {
                        $delay = ENRICH_RETRY_DELAYS[$attemptNumber] ?? 18000;
                        $runAt = date('Y-m-d H:i:s', time() + $delay);
                        $db->prepare("UPDATE jobs SET status = 'pending', run_at = :run_at, error_message = :err WHERE id = :id")
                           ->execute([':run_at' => $runAt, ':err' => $errorMsg, ':id' => $jobId]);
                        echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} (enrich_keywords) attempt #{$attemptNumber} failed. Retry scheduled at {$runAt}. Error: {$errorMsg}\n";
                    } else {
                        // All retries exhausted — permanently fail the job only
                        $db->prepare("UPDATE jobs SET status = 'failed', error_message = :err WHERE id = :id")
                           ->execute([':err' => $errorMsg, ':id' => $jobId]);
                        echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} (enrich_keywords) permanently failed after " . ENRICH_MAX_ATTEMPTS . " attempts. Error: {$errorMsg}\n";
                    }
                } else {
                    // Non-enrichment jobs: fail immediately and flag the knowledge source
                    $db->prepare("UPDATE jobs SET status = 'failed', error_message = :err WHERE id = :id")
                       ->execute([':err' => $errorMsg, ':id' => $jobId]);

                    if (!empty($payload['knowledge_source_id'])) {
                        $db->exec("UPDATE knowledge_sources SET status = 'failed' WHERE id = " . (int)$payload['knowledge_source_id']);
                    }

                    echo "[" . date('Y-m-d H:i:s') . "] Job #{$jobId} FAILED: {$errorMsg}\n";
                }
            }
        } else {
            // Idle sleep if no pending jobs
            sleep(3);
        }
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Worker Error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
