<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Jwt;
use App\Core\Request;
use App\Core\Response;
use App\Services\SmartOnboardingService;
use Throwable;

class SmartOnboardingController
{
    /**
     * GET /v1/onboarding/scrape-stream
     *
     * Server-Sent Events endpoint. Authentication via Bearer token in Authorization header
     * OR ?auth_token=<jwt> query param (browser EventSource doesn't support custom headers).
     * Streams real-time onboarding progress while writing to production DB tables.
     */
    public function scrapeStream(Request $request, array $params = []): void
    {
        // Immediately establish SSE headers and disable proxy buffering
        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-transform');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Auth: accept token from query param or Authorization header
        $token = null;
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $hm)) {
            $token = $hm[1];
        } elseif (!empty($_GET['auth_token'])) {
            $token = $_GET['auth_token'];
        }

        if (!$token) {
            http_response_code(401);
            echo "data: " . json_encode(['event' => 'error', 'message' => 'Unauthorized — missing auth token.', 'pct' => 100]) . "\n\n";
            flush();
            return;
        }

        $payload = Jwt::decode($token);
        if (!$payload || ($payload['type'] ?? '') !== 'access') {
            http_response_code(401);
            echo "data: " . json_encode(['event' => 'error', 'message' => 'Invalid or expired authentication token.', 'pct' => 100]) . "\n\n";
            flush();
            return;
        }

        $orgId = (int)($payload['organization_id'] ?? 0);
        if (!$orgId) {
            http_response_code(403);
            echo "data: " . json_encode(['event' => 'error', 'message' => 'No organization context found in session.', 'pct' => 100]) . "\n\n";
            flush();
            return;
        }

        // Fetch org + chatbot info
        $db = Database::getConnection();
        $orgStmt = $db->prepare("SELECT id, website_url, onboarding_completed FROM organizations WHERE id = :id LIMIT 1");
        $orgStmt->execute([':id' => $orgId]);
        $org = $orgStmt->fetch();

        if (!$org) {
            http_response_code(404);
            echo "data: " . json_encode(['event' => 'error', 'message' => 'Organization not found.', 'pct' => 100]) . "\n\n";
            flush();
            return;
        }

        // If already completed, check if bot token exists and return
        if (!empty($org['onboarding_completed'])) {
            $botStmt = $db->prepare("SELECT bot_token FROM chatbots WHERE organization_id = :oid AND is_active = 1 LIMIT 1");
            $botStmt->execute([':oid' => $orgId]);
            $botToken = $botStmt->fetchColumn();
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            echo "data: " . json_encode(['event' => 'already_complete', 'message' => 'Onboarding already completed.', 'pct' => 100, 'bot_token' => $botToken]) . "\n\n";
            flush();
            return;
        }

        $websiteUrl = trim((string)($org['website_url'] ?? ''));
        if (empty($websiteUrl)) {
            http_response_code(400);
            echo "data: " . json_encode(['event' => 'error', 'message' => 'No website URL on record for this organization.', 'pct' => 100]) . "\n\n";
            flush();
            return;
        }

        $botStmt = $db->prepare("SELECT id FROM chatbots WHERE organization_id = :oid AND is_active = 1 LIMIT 1");
        $botStmt->execute([':oid' => $orgId]);
        $chatbotId = (int)($botStmt->fetchColumn() ?: 0);

        set_time_limit(150);
        ignore_user_abort(true);

        SmartOnboardingService::streamProgress($orgId, $chatbotId, $websiteUrl);
    }

    /**
     * POST /v1/onboarding/smart-complete
     *
     * Synchronous fallback for environments where SSE is blocked.
     */
    public function smartComplete(Request $request, array $params = []): void
    {
        $orgId = (int)($GLOBALS['organization_id'] ?? 0);
        if (!$orgId) {
            Response::error('Organization context missing.', 403);
            return;
        }

        $db = Database::getConnection();
        $orgStmt = $db->prepare("SELECT id, website_url FROM organizations WHERE id = :id LIMIT 1");
        $orgStmt->execute([':id' => $orgId]);
        $org = $orgStmt->fetch();

        if (!$org || empty($org['website_url'])) {
            Response::error('No website URL configured for this organization.', 400);
            return;
        }

        $botStmt = $db->prepare("SELECT id FROM chatbots WHERE organization_id = :oid AND is_active = 1 LIMIT 1");
        $botStmt->execute([':oid' => $orgId]);
        $chatbotId = (int)($botStmt->fetchColumn() ?: 0);

        set_time_limit(150);

        ob_start();
        SmartOnboardingService::streamProgress($orgId, $chatbotId, $org['website_url']);
        $sseOutput = ob_get_clean();

        $lines = explode("\n", (string)$sseOutput);
        $lastData = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $json = trim(substr($line, 5));
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $lastData = $decoded;
                }
            }
        }

        Response::success($lastData ?? ['completed' => true], 'Smart onboarding complete.');
    }
}
