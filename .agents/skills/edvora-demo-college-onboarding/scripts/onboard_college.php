<?php

/**
 * Automated Demo College Onboarding Runner for Edvora.chat
 *
 * Provisions a full demo college tenant, scrapes knowledge URLs,
 * creates academic programs, enqueues background worker jobs,
 * and waits for embeddings to become active.
 *
 * Usage:
 *   php onboard_college.php --config=/tmp/college_config.json
 */

spl_autoload_register(function ($class) {
    $prefix  = 'App\\';
    $baseDir = '/var/www/edvora.chat/app/';
    $len     = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file          = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require_once $file;
});

use App\Config\Database;
use App\Config\Env;
use App\Services\UrlScraper;
use App\Services\ContentCompactor;
use App\Services\KnowledgeFileStorage;

Env::load();

// 1. Parse Input Config
$options = getopt('', ['config:']);
$configFile = $options['config'] ?? null;

if (!$configFile || !file_exists($configFile)) {
    fwrite(STDERR, "Error: --config=<path_to_json> is required and must exist.\n");
    exit(1);
}

$rawJson = file_get_contents($configFile);
$config  = json_decode($rawJson, true);

if (!is_array($config)) {
    fwrite(STDERR, "Error: Invalid JSON payload in config file.\n");
    exit(1);
}

$collegeName = trim($config['name'] ?? '');
if (empty($collegeName)) {
    fwrite(STDERR, "Error: 'name' (College/University name) is required.\n");
    exit(1);
}

$websiteUrl   = trim($config['website_url'] ?? '');
$country      = trim($config['country'] ?? 'United States');
$planId       = (int)($config['plan_id'] ?? 3); // Default Pro plan
$adminEmail   = trim($config['admin_email'] ?? '');
$adminPass    = trim($config['admin_password'] ?? '');
$primaryColor = trim($config['primary_color'] ?? '#2563EB');
$urls         = is_array($config['urls'] ?? null) ? $config['urls'] : [];
$programs     = is_array($config['programs'] ?? null) ? $config['programs'] : [];

// Normalize Website URL
if (!empty($websiteUrl) && !preg_match('#^https?://#i', $websiteUrl)) {
    $websiteUrl = 'https://' . $websiteUrl;
}
$parts = parse_url($websiteUrl);
$domain = strtolower($parts['host'] ?? 'edvora.chat');
$domain = preg_replace('/^www\./', '', $domain);

// Auto-derive Admin Email & Password if not provided
$slugBase = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $collegeName), '-'));
if (empty($slugBase)) {
    $slugBase = 'demo-' . substr(bin2hex(random_bytes(4)), 0, 8);
}
$slug = $slugBase . '-demo';

if (empty($adminEmail)) {
    $adminEmail = "demo-{$slugBase}@edvora.chat";
}
if (empty($adminPass)) {
    $adminPass = 'EdvoraDemo' . bin2hex(random_bytes(3)) . '!';
}

$db = Database::getConnection();

echo "============================================================\n";
echo "Starting Demo College Provisioning for: {$collegeName}\n";
echo "Website: {$websiteUrl} (Domain: {$domain})\n";
echo "Admin Email: {$adminEmail}\n";
echo "Country: {$country} | Plan ID: {$planId}\n";
echo "============================================================\n\n";

// Ensure unique slug
$stmtSlug = $db->prepare("SELECT id FROM organizations WHERE slug = :slug LIMIT 1");
$stmtSlug->execute([':slug' => $slug]);
if ($stmtSlug->fetch()) {
    $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
}

// Ensure unique email
$stmtEmail = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
$stmtEmail->execute([':email' => $adminEmail]);
if ($stmtEmail->fetch()) {
    $adminEmail = "demo-{$slugBase}-" . substr(bin2hex(random_bytes(2)), 0, 4) . "@edvora.chat";
}

$db->beginTransaction();

try {
    // 1. Create Organization (is_demo=1, onboarding_completed=1, onboarding_step=1)
    $stmtOrg = $db->prepare("
        INSERT INTO organizations (
            name, slug, website_url, primary_color, plan_id,
            subscription_status, is_demo, onboarding_completed, onboarding_step,
            country, created_at, updated_at
        ) VALUES (
            :name, :slug, :url, :color, :plan_id,
            'active', 1, 1, 1,
            :country, NOW(), NOW()
        )
    ");
    $stmtOrg->execute([
        ':name'    => $collegeName,
        ':slug'    => $slug,
        ':url'     => $websiteUrl,
        ':color'   => $primaryColor,
        ':plan_id' => $planId,
        ':country' => $country
    ]);
    $orgId = (int)$db->lastInsertId();

    // Human-facing reference identifier (Rule 9)
    $instituteId = md5((string)$orgId);
    $db->prepare("UPDATE organizations SET institute_id = :inst_id WHERE id = :id")->execute([
        ':inst_id' => $instituteId,
        ':id'      => $orgId
    ]);
    echo "[1/4] Organization #{$orgId} created successfully (slug: {$slug}).\n";

    // 2. Create Owner User
    $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmtUser = $db->prepare("
        INSERT INTO users (
            organization_id, name, email, password_hash, role,
            email_verified_at, created_at, updated_at
        ) VALUES (
            :org_id, :name, :email, :pass, 'owner',
            NOW(), NOW(), NOW()
        )
    ");
    $stmtUser->execute([
        ':org_id' => $orgId,
        ':name'   => 'Demo Administrator',
        ':email'  => $adminEmail,
        ':pass'   => $passwordHash
    ]);
    $userId = (int)$db->lastInsertId();
    echo "      Owner User #{$userId} created ({$adminEmail}).\n";

    // 3. Create Chatbot with DEFAULT welcome message
    $botToken = bin2hex(random_bytes(16));
    $allowedDomains = json_encode(array_values(array_unique([$domain, 'edvora.chat', '*.edvora.chat', 'localhost'])));

    // Leave welcome_message as NULL or default platform message
    $stmtBot = $db->prepare("
        INSERT INTO chatbots (
            organization_id, name, welcome_message, primary_color,
            bot_token, allowed_domains, is_active, lead_capture_enabled,
            created_at, updated_at
        ) VALUES (
            :org_id, 'AI Admissions Assistant', NULL, :color,
            :token, :allowed, 1, 1,
            NOW(), NOW()
        )
    ");
    $stmtBot->execute([
        ':org_id'  => $orgId,
        ':color'   => $primaryColor,
        ':token'   => $botToken,
        ':allowed' => $allowedDomains
    ]);
    $chatbotId = (int)$db->lastInsertId();
    echo "      Chatbot #{$chatbotId} created with default welcome message (token: {$botToken}).\n";

    // 4. Create Active Subscription Row
    $stmtSub = $db->prepare("
        INSERT INTO subscriptions (
            organization_id, plan_id, billing_cycle, status,
            currency, current_period_start, current_period_end, created_at, updated_at
        ) VALUES (
            :org_id, :plan_id, 'monthly', 'active',
            'USD', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW(), NOW()
        )
    ");
    $stmtSub->execute([
        ':org_id'  => $orgId,
        ':plan_id' => $planId
    ]);

    // 5. Initialize Usage Log
    $db->prepare("
        INSERT INTO usage_logs (organization_id, period, chatbots_count)
        VALUES (:org_id, :period, 1)
    ")->execute([
        ':org_id' => $orgId,
        ':period' => date('Y-m')
    ]);

    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Error creating tenant: " . $e->getMessage() . "\n");
    exit(1);
}

// 6. Ingest Knowledge URLs
$createdSources = [];
if (!empty($urls)) {
    echo "\n[2/4] Ingesting " . count($urls) . " Knowledge URLs...\n";
    foreach ($urls as $idx => $url) {
        $url = trim($url);
        if (empty($url)) continue;
        echo "      [" . ($idx + 1) . "/" . count($urls) . "] Scraping: {$url}...\n";

        try {
            $scraped = UrlScraper::scrape($url);
            $title   = !empty($scraped['title']) ? $scraped['title'] : "{$collegeName} Page";
            $compact = ContentCompactor::process($scraped['raw_content'], $title);

            $stmt = $db->prepare("
                INSERT INTO knowledge_sources (
                    organization_id, chatbot_id, program_id, type, title, category,
                    academic_version, effective_from, expires_on, last_reviewed_at,
                    review_frequency_days, source_url, content_hash, status,
                    last_fetched_at, created_at, updated_at
                ) VALUES (
                    :org_id, :bot_id, NULL, 'url', :title, 'Programs & Admissions',
                    '2025-2026', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), CURDATE(),
                    180, :url, :hash, 'pending',
                    NOW(), NOW(), NOW()
                )
            ");
            $stmt->execute([
                ':org_id' => $orgId,
                ':bot_id' => $chatbotId,
                ':title'  => $title,
                ':url'    => $url,
                ':hash'   => $scraped['content_hash']
            ]);
            $sourceId = (int)$db->lastInsertId();

            $saveMeta = KnowledgeFileStorage::saveText($orgId, $sourceId, $compact['processed_content']);

            $db->prepare("
                UPDATE knowledge_sources
                SET file_path = :file_path,
                    file_size_bytes = :size_bytes,
                    token_count = :tokens,
                    checksum_sha256 = :sha256
                WHERE id = :id AND organization_id = :org_id
            ")->execute([
                ':file_path'  => $saveMeta['file_path'],
                ':size_bytes' => $saveMeta['file_size_bytes'],
                ':tokens'     => $saveMeta['token_count'],
                ':sha256'     => $saveMeta['checksum_sha256'],
                ':id'         => $sourceId,
                ':org_id'     => $orgId
            ]);

            // Queue background chunk & embed
            $db->prepare("
                INSERT INTO jobs (type, payload, status, run_at)
                VALUES ('chunk_and_embed', :p, 'pending', NOW())
            ")->execute([
                ':p' => json_encode([
                    'source_id'       => $sourceId,
                    'organization_id' => $orgId,
                    'program_id'      => null
                ])
            ]);

            $createdSources[] = [
                'id'    => $sourceId,
                'title' => $title,
                'url'   => $url
            ];
            echo "        -> Source #{$sourceId} created ({$saveMeta['token_count']} estimated tokens).\n";

        } catch (\Throwable $e) {
            echo "        -> ERROR scraping {$url}: " . $e->getMessage() . "\n";
        }
    }
}

// 7. Ingest Academic Programs
$createdPrograms = [];
if (!empty($programs)) {
    echo "\n[3/4] Ingesting " . count($programs) . " Academic Programs...\n";
    foreach ($programs as $idx => $p) {
        $pName     = trim($p['course_name'] ?? '');
        $pCode     = trim($p['course_code'] ?? '') ?: null;
        $pType     = trim($p['program_type'] ?? 'postgraduate');
        $pDuration = trim($p['duration'] ?? '2 Years');
        $pMode     = trim($p['mode'] ?? 'full_time');
        $pOpen     = isset($p['is_admissions_open']) ? (int)$p['is_admissions_open'] : 1;
        $pCurr     = trim($p['currency'] ?? 'USD');
        $pElig     = trim($p['eligibility'] ?? '') ?: null;
        $pDeadline = trim($p['application_deadline'] ?? '') ?: null;
        $pAppUrl   = trim($p['application_url'] ?? '') ?: null;
        $sourceUrl = trim($p['source_url'] ?? '');

        if (empty($pName)) continue;

        echo "      [" . ($idx + 1) . "/" . count($programs) . "] Creating Program: {$pName} ({$pCode})...\n";

        $stmtProg = $db->prepare("
            INSERT INTO programs (
                organization_id, course_name, course_code,
                program_type, duration, mode, is_admissions_open,
                currency, eligibility, application_deadline,
                application_url, sort_order, created_at, updated_at
            ) VALUES (
                :org_id, :name, :code,
                :type, :duration, :mode, :open,
                :currency, :eligibility, :deadline,
                :url, :sort, NOW(), NOW()
            )
        ");
        $stmtProg->execute([
            ':org_id'     => $orgId,
            ':name'       => $pName,
            ':code'       => $pCode,
            ':type'       => $pType,
            ':duration'   => $pDuration,
            ':mode'       => $pMode,
            ':open'       => $pOpen,
            ':currency'   => $pCurr,
            ':eligibility'=> $pElig,
            ':deadline'   => $pDeadline,
            ':url'        => $pAppUrl,
            ':sort'       => $idx + 1
        ]);
        $progId = (int)$db->lastInsertId();

        // If matched to a scraped URL source, link source and its items to program_id
        if (!empty($sourceUrl)) {
            $urlWithSlash = rtrim($sourceUrl, '/') . '/';
            $urlNoSlash   = rtrim($sourceUrl, '/');

            $stmtLink = $db->prepare("
                UPDATE knowledge_sources
                SET program_id = :prog_id, updated_at = NOW()
                WHERE (source_url = :url1 OR source_url = :url2) AND organization_id = :org_id
            ");
            $stmtLink->execute([
                ':prog_id' => $progId,
                ':url1'    => $urlNoSlash,
                ':url2'    => $urlWithSlash,
                ':org_id'  => $orgId
            ]);

            $db->prepare("
                UPDATE knowledge_items ki
                JOIN knowledge_sources ks ON ki.source_id = ks.id
                SET ki.program_id = :prog_id, ki.updated_at = NOW()
                WHERE (ks.source_url = :url1 OR ks.source_url = :url2) AND ki.organization_id = :org_id
            ")->execute([
                ':prog_id' => $progId,
                ':url1'    => $urlNoSlash,
                ':url2'    => $urlWithSlash,
                ':org_id'  => $orgId
            ]);
        }

        // Queue embed_program background job
        $db->prepare("
            INSERT INTO jobs (type, payload, status, run_at)
            VALUES ('embed_program', :p, 'pending', NOW())
        ")->execute([
            ':p' => json_encode([
                'program_id'      => $progId,
                'organization_id' => $orgId
            ])
        ]);

        $createdPrograms[] = [
            'id'   => $progId,
            'name' => $pName,
            'code' => $pCode
        ];
        echo "        -> Program #{$progId} created and embed job dispatched.\n";
    }
}

// 8. Wait for Supervisor Background Workers
echo "\n[4/4] Awaiting supervisor workers to complete chunking and embeddings...\n";
$startTime = time();
$timeout   = 180; // 3 minutes max

while (time() - $startTime < $timeout) {
    $stmtJobs = $db->prepare("
        SELECT status, COUNT(*) as cnt
        FROM jobs
        WHERE (type = 'chunk_and_embed' OR type = 'embed_program')
          AND payload LIKE :p
        GROUP BY status
    ");
    $stmtJobs->execute([':p' => "%\"organization_id\":{$orgId}%"]);
    $stats = $stmtJobs->fetchAll(\PDO::FETCH_KEY_PAIR);

    $pending = $stats['pending'] ?? 0;
    $running = $stats['running'] ?? 0;
    $done    = $stats['done'] ?? 0;
    $failed  = $stats['failed'] ?? 0;

    echo "      Worker status: {$done} completed, {$running} running, {$pending} pending, {$failed} failed.\n";

    if (($pending + $running) === 0 && ($done + $failed) > 0) {
        break;
    }
    sleep(3);
}

// 9. Post-worker sync: ensure knowledge_items linked to source inherit program_id
$db->prepare("
    UPDATE knowledge_items ki
    JOIN knowledge_sources ks ON ki.source_id = ks.id
    SET ki.program_id = ks.program_id
    WHERE ks.organization_id = :org_id AND ks.program_id IS NOT NULL AND ki.program_id IS NULL
")->execute([':org_id' => $orgId]);

// 10. Fetch Final Summary Metrics
$stmtTotalKi = $db->prepare("SELECT COUNT(*) FROM knowledge_items WHERE organization_id = ?");
$stmtTotalKi->execute([$orgId]);
$totalChunks = (int)$stmtTotalKi->fetchColumn();

$stmtTotalKs = $db->prepare("SELECT COUNT(*) FROM knowledge_sources WHERE organization_id = ? AND status = 'active'");
$stmtTotalKs->execute([$orgId]);
$activeSources = (int)$stmtTotalKs->fetchColumn();

$resultPayload = [
    'organization_id'   => $orgId,
    'college_name'      => $collegeName,
    'slug'              => $slug,
    'website_url'       => $websiteUrl,
    'country'           => $country,
    'admin_email'       => $adminEmail,
    'admin_password'    => $adminPass,
    'bot_token'         => $botToken,
    'widget_url'        => "https://edvora.chat/widget/{$botToken}",
    'console_url'       => "https://edvora.chat/app/#overview",
    'programs_url'      => "https://edvora.chat/app/#academic-programs",
    'total_sources'     => count($createdSources),
    'active_sources'    => $activeSources,
    'total_programs'    => count($createdPrograms),
    'total_chunks'      => $totalChunks
];

echo "\n============================================================\n";
echo "DEMO COLLEGE ONBOARDING COMPLETE!\n";
echo "============================================================\n";
echo "Organization ID:    {$resultPayload['organization_id']}\n";
echo "College Name:       {$resultPayload['college_name']}\n";
echo "Admin Login Email:  {$resultPayload['admin_email']}\n";
echo "Admin Password:     {$resultPayload['admin_password']}\n";
echo "Bot Token:          {$resultPayload['bot_token']}\n";
echo "Public Widget Link: {$resultPayload['widget_url']}\n";
echo "Console Dashboard:  {$resultPayload['console_url']}\n";
echo "Academic Programs:  {$resultPayload['total_programs']} programs created\n";
echo "Knowledge Embeds:   {$resultPayload['total_chunks']} chunks active across {$resultPayload['active_sources']} sources\n";
echo "============================================================\n\n";

// Write JSON output for caller
echo "RESULT_JSON:" . json_encode($resultPayload) . "\n";
