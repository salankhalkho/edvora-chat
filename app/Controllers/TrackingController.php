<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

class TrackingController
{
    /**
     * POST /v1/tracking/beacon
     * High-speed, lightweight beacon ingestion endpoint for visitor page dwell time & session flow.
     */
    public function handleBeacon(Request $request): void
    {
        $body = $request->getBody();
        $botToken = trim($body['bot_token'] ?? $request->getBotToken() ?? '');
        $sessionId = trim($body['session_id'] ?? '');
        $visitorId = trim($body['visitor_id'] ?? '');
        $url = trim($body['url'] ?? '');
        $pageTitle = trim($body['page_title'] ?? '');
        $dwellSeconds = (int)($body['dwell_seconds'] ?? 0);
        $isHeartbeat = !empty($body['is_heartbeat']);

        // Attribution fields (VVIP)
        $referrer = trim($body['referrer'] ?? '');
        $utmSource = trim($body['utm_source'] ?? '');
        $utmMedium = trim($body['utm_medium'] ?? '');
        $utmCampaign = trim($body['utm_campaign'] ?? '');
        $utmTerm = trim($body['utm_term'] ?? '');
        $utmContent = trim($body['utm_content'] ?? '');

        if (empty($botToken) || empty($sessionId) || empty($visitorId) || empty($url)) {
            Response::error('Missing required tracking parameters.', 400);
            return;
        }

        // Minimum Dwell Filter: Require at least 5 seconds dwell time
        // Unless it's an ongoing incremental heartbeat (which is already tracking a validated session)
        if ($dwellSeconds < 5 && !$isHeartbeat) {
            Response::json(['status' => 'ignored', 'reason' => 'dwell_time_below_threshold'], 200);
            return;
        }

        // Clean & truncate fields for safety
        $url = substr($url, 0, 500);
        $pageTitle = substr($pageTitle, 0, 255);
        $referrer = !empty($referrer) ? substr($referrer, 0, 500) : null;
        $utmSource = !empty($utmSource) ? substr($utmSource, 0, 100) : null;
        $utmMedium = !empty($utmMedium) ? substr($utmMedium, 0, 100) : null;
        $utmCampaign = !empty($utmCampaign) ? substr($utmCampaign, 0, 100) : null;
        $utmTerm = !empty($utmTerm) ? substr($utmTerm, 0, 100) : null;
        $utmContent = !empty($utmContent) ? substr($utmContent, 0, 100) : null;

        try {
            $db = Database::getConnection();

            // 1. Resolve bot and organization
            $botStmt = $db->prepare("
                SELECT c.id as chatbot_id, c.organization_id
                FROM chatbots c
                WHERE c.bot_token = :token AND c.is_active = 1
                LIMIT 1
            ");
            $botStmt->execute([':token' => $botToken]);
            $bot = $botStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bot) {
                Response::error('Invalid or inactive bot token.', 404);
                return;
            }

            $orgId = (int)$bot['organization_id'];

            // 2. Upsert visitor_sessions
            $checkSessStmt = $db->prepare("SELECT id, entry_page, conversion_status FROM visitor_sessions WHERE session_id = :sid LIMIT 1");
            $checkSessStmt->execute([':sid' => $sessionId]);
            $existingSession = $checkSessStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingSession) {
                // Check if this visitor already converted in another context (or chat/lead exists)
                $convCheck = $db->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM leads WHERE visitor_id = :vid AND organization_id = :oid) as lead_count,
                        (SELECT COUNT(*) FROM conversations WHERE visitor_id = :vid2 AND organization_id = :oid2) as chat_count
                ");
                $convCheck->execute([
                    ':vid' => $visitorId,
                    ':oid' => $orgId,
                    ':vid2' => $visitorId,
                    ':oid2' => $orgId
                ]);
                $counts = $convCheck->fetch(PDO::FETCH_ASSOC);
                $status = 'browsing';
                if (!empty($counts['lead_count'])) {
                    $status = 'lead_converted';
                } elseif (!empty($counts['chat_count'])) {
                    $status = 'chat_engaged';
                }

                $insSess = $db->prepare("
                    INSERT INTO visitor_sessions (
                        organization_id, session_id, visitor_id, referrer,
                        utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                        entry_page, exit_page, total_pages, total_dwell_seconds, conversion_status, started_at
                    ) VALUES (
                        :oid, :sid, :vid, :ref,
                        :utm_s, :utm_m, :utm_c, :utm_t, :utm_co,
                        :entry, :exit, 1, :dwell, :status, NOW()
                    )
                ");
                $insSess->execute([
                    ':oid' => $orgId,
                    ':sid' => $sessionId,
                    ':vid' => $visitorId,
                    ':ref' => $referrer,
                    ':utm_s' => $utmSource,
                    ':utm_m' => $utmMedium,
                    ':utm_c' => $utmCampaign,
                    ':utm_t' => $utmTerm,
                    ':utm_co' => $utmContent,
                    ':entry' => $url,
                    ':exit' => $url,
                    ':dwell' => $dwellSeconds,
                    ':status' => $status
                ]);
            } else {
                // Update existing session's exit page & activity timestamp
                $upSess = $db->prepare("
                    UPDATE visitor_sessions
                    SET exit_page = :exit,
                        last_active_at = NOW()
                    WHERE session_id = :sid
                ");
                $upSess->execute([
                    ':exit' => $url,
                    ':sid' => $sessionId
                ]);
            }

            // 3. Upsert visitor_page_views
            $checkPageStmt = $db->prepare("
                SELECT id, time_spent_seconds, view_order 
                FROM visitor_page_views 
                WHERE session_id = :sid AND url = :url 
                ORDER BY id DESC LIMIT 1
            ");
            $checkPageStmt->execute([':sid' => $sessionId, ':url' => $url]);
            $existingPage = $checkPageStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingPage) {
                // Update time spent to the higher dwell time recorded
                $newDwell = max((int)$existingPage['time_spent_seconds'], $dwellSeconds);
                $upPage = $db->prepare("
                    UPDATE visitor_page_views 
                    SET time_spent_seconds = :dwell,
                        page_title = COALESCE(NULLIF(:title, ''), page_title),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $upPage->execute([
                    ':dwell' => $newDwell,
                    ':title' => $pageTitle,
                    ':id' => $existingPage['id']
                ]);
            } else {
                // Compute next view order in this session
                $orderStmt = $db->prepare("SELECT COALESCE(MAX(view_order), 0) + 1 as next_order FROM visitor_page_views WHERE session_id = :sid");
                $orderStmt->execute([':sid' => $sessionId]);
                $nextOrder = (int)$orderStmt->fetchColumn();

                $insPage = $db->prepare("
                    INSERT INTO visitor_page_views (
                        organization_id, session_id, visitor_id, url, page_title, time_spent_seconds, view_order, created_at
                    ) VALUES (
                        :oid, :sid, :vid, :url, :title, :dwell, :order, NOW()
                    )
                ");
                $insPage->execute([
                    ':oid' => $orgId,
                    ':sid' => $sessionId,
                    ':vid' => $visitorId,
                    ':url' => $url,
                    ':title' => $pageTitle,
                    ':dwell' => $dwellSeconds,
                    ':order' => $nextOrder
                ]);
            }

            // 4. Recalculate session aggregates (total_pages & total_dwell_seconds)
            $aggStmt = $db->prepare("
                UPDATE visitor_sessions
                SET total_pages = (SELECT COUNT(DISTINCT url) FROM visitor_page_views WHERE session_id = :sid1),
                    total_dwell_seconds = (SELECT COALESCE(SUM(time_spent_seconds), 0) FROM visitor_page_views WHERE session_id = :sid2)
                WHERE session_id = :sid3
            ");
            $aggStmt->execute([
                ':sid1' => $sessionId,
                ':sid2' => $sessionId,
                ':sid3' => $sessionId
            ]);

            Response::json(['status' => 'success', 'tracked' => true], 200);
        } catch (Throwable $e) {
            error_log('[TrackingController] handleBeacon error: ' . $e->getMessage());
            Response::error('Failed to log tracking beacon.', 500);
        }
    }
}
