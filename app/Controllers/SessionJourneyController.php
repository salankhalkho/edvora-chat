<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

class SessionJourneyController
{
    /**
     * GET /v1/analytics/session-journeys
     * College-Admin endpoint for session journeys, dwell analytics, and conversion tracking.
     */
    /**
     * Resolve and validate tenant organization ID.
     */
    private function resolveOrgId(Request $request): int
    {
        $orgId = (int)(
            $GLOBALS['organization_id']
            ?? $GLOBALS['auth_user']['organization_id']
            ?? $request->get('organization_id')
            ?? $request->get('tenant_id')
            ?? $request->get('org_id')
            ?? 0
        );

        $user = $GLOBALS['auth_user'] ?? null;
        if (!empty($user['role']) && ($user['role'] === 'superadmin' || $user['role'] === 'super_admin')) {
            $reqOrg = (int)($request->get('organization_id') ?: $request->get('tenant_id') ?: $request->get('org_id'));
            if ($reqOrg > 0) {
                $orgId = $reqOrg;
            }
        }

        return $orgId;
    }

    /**
     * GET /v1/analytics/session-journeys
     * College-Admin endpoint for session journeys, dwell analytics, and conversion tracking.
     */
    public function listTenantSessions(Request $request): void
    {
        $report = $request->get('report');
        if ($report === 'top_visited_pages') {
            $this->getTopVisitedPagesReport($request);
            return;
        }
        if ($report === 'top_exit_pages') {
            $this->getTopExitPagesReport($request);
            return;
        }
        if ($report === 'campaign_sources') {
            $this->getCampaignSourcesReport($request);
            return;
        }

        $orgId = $this->resolveOrgId($request);
        if ($orgId <= 0) {
            Response::error('Unauthorized organization context.', 403);
            return;
        }

        $this->processSessionQuery($request, $orgId);
    }

    /**
     * GET /v1/analytics/session-journeys/{sessionId}/steps
     * College-Admin endpoint to fetch chronological step-by-step page views for a specific session.
     */
    public function getSessionSteps(Request $request, array $params): void
    {
        $orgId = (int)(
            $GLOBALS['organization_id']
            ?? $GLOBALS['auth_user']['organization_id']
            ?? $request->get('organization_id')
            ?? $request->get('tenant_id')
            ?? $request->get('org_id')
            ?? 0
        );

        $user = $GLOBALS['auth_user'] ?? null;
        if (!empty($user['role']) && ($user['role'] === 'superadmin' || $user['role'] === 'super_admin')) {
            $reqOrg = (int)($request->get('organization_id') ?: $request->get('tenant_id') ?: $request->get('org_id'));
            if ($reqOrg > 0) {
                $orgId = $reqOrg;
            }
        }

        if ($orgId <= 0) {
            Response::error('Unauthorized organization context.', 403);
            return;
        }

        $sessionId = trim($params['sessionId'] ?? '');
        if (empty($sessionId)) {
            Response::error('Session ID is required.', 400);
            return;
        }

        $this->processStepsQuery($sessionId, $orgId);
    }

    /**
     * GET /v1/superadmin/session-journeys
     * Superadmin endpoint for cross-organization or tenant-filtered session analytics.
     */
    public function listSuperAdminSessions(Request $request): void
    {
        $orgFilter = $request->get('organization_id') ?? $request->get('org_id');
        $orgId = ($orgFilter && $orgFilter !== 'all') ? (int)$orgFilter : null;

        $this->processSessionQuery($request, $orgId, true);
    }

    /**
     * GET /v1/superadmin/session-journeys/{sessionId}/steps
     * Superadmin endpoint for inspecting session steps across any organization.
     */
    public function getSuperAdminSessionSteps(Request $request, array $params): void
    {
        $sessionId = trim($params['sessionId'] ?? '');
        if (empty($sessionId)) {
            Response::error('Session ID is required.', 400);
            return;
        }

        $this->processStepsQuery($sessionId, null, true);
    }

    /**
     * Internal helper to build metrics, top lists, and paginated sessions.
     */
    private function processSessionQuery(Request $request, ?int $orgId, bool $isSuperAdmin = false): void
    {
        try {
            $db = Database::getConnection();

            // 1. Time range filtering (30, 60, 90 days - default 30)
            $daysParam = (int)($request->get('days') ?? 30);
            if (!in_array($daysParam, [7, 30, 60, 90, 180, 365])) {
                $daysParam = 30;
            }

            $statusFilter = trim($request->get('status') ?? 'all');
            $search = trim($request->get('search') ?? '');
            $page = max(1, (int)($request->get('page') ?? 1));
            $limit = min(100, max(10, (int)($request->get('limit') ?? 50)));
            $offset = ($page - 1) * $limit;

            // Base WHERE conditions
            $where = ["vs.started_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"];
            $params = [':days' => $daysParam];

            if ($orgId !== null) {
                $where[] = "vs.organization_id = :org_id";
                $params[':org_id'] = $orgId;
            }

            if ($statusFilter !== 'all' && in_array($statusFilter, ['browsing', 'chat_engaged', 'lead_converted'])) {
                $where[] = "vs.conversion_status = :status";
                $params[':status'] = $statusFilter;
            }

            if (!empty($search)) {
                $where[] = "(vs.session_id LIKE :search OR vs.visitor_id LIKE :search OR vs.utm_source LIKE :search OR vs.entry_page LIKE :search OR vs.exit_page LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            // 2. Aggregate KPI Summary
            $summaryStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_sessions,
                    COALESCE(AVG(total_dwell_seconds), 0) as avg_dwell_seconds,
                    COALESCE(AVG(total_pages), 0) as avg_pages_per_session,
                    COALESCE(SUM(CASE WHEN conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as count_converted,
                    COALESCE(SUM(CASE WHEN conversion_status = 'chat_engaged' THEN 1 ELSE 0 END), 0) as count_chat_engaged,
                    COALESCE(SUM(CASE WHEN conversion_status = 'browsing' THEN 1 ELSE 0 END), 0) as count_browsing
                FROM visitor_sessions vs
                WHERE {$whereSql}
            ");
            $summaryStmt->execute($params);
            $summaryRaw = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $totalSessions = (int)($summaryRaw['total_sessions'] ?? 0);
            $countConverted = (int)($summaryRaw['count_converted'] ?? 0);
            $conversionRate = $totalSessions > 0 ? round(($countConverted / $totalSessions) * 100, 1) : 0;

            $summary = [
                'total_sessions' => $totalSessions,
                'avg_dwell_seconds' => round((float)($summaryRaw['avg_dwell_seconds'] ?? 0)),
                'avg_dwell_formatted' => $this->formatDuration((int)($summaryRaw['avg_dwell_seconds'] ?? 0)),
                'avg_pages_per_session' => round((float)($summaryRaw['avg_pages_per_session'] ?? 0), 1),
                'count_lead_converted' => $countConverted,
                'count_chat_engaged' => (int)($summaryRaw['count_chat_engaged'] ?? 0),
                'count_browsing' => (int)($summaryRaw['count_browsing'] ?? 0),
                'conversion_rate' => $conversionRate,
                'days' => $daysParam
            ];

            // 3. Top 5 Most Visited Pages
            $topPagesWhere = ["vpv.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"];
            $topPagesParams = [':days' => $daysParam];
            if ($orgId !== null) {
                $topPagesWhere[] = "vpv.organization_id = :org_id";
                $topPagesParams[':org_id'] = $orgId;
            }
            $topPagesSql = implode(' AND ', $topPagesWhere);

            $topPagesStmt = $db->prepare("
                SELECT 
                    vpv.url, 
                    MAX(vpv.page_title) as page_title,
                    COUNT(*) as visit_count,
                    COALESCE(AVG(vpv.time_spent_seconds), 0) as avg_dwell_seconds
                FROM visitor_page_views vpv
                WHERE {$topPagesSql}
                GROUP BY vpv.url
                ORDER BY visit_count DESC
                LIMIT 5
            ");
            $topPagesStmt->execute($topPagesParams);
            $topPages = array_map(function($row) {
                return [
                    'url' => $row['url'],
                    'page_title' => $row['page_title'] ?: $row['url'],
                    'visit_count' => (int)$row['visit_count'],
                    'avg_dwell_seconds' => round((float)$row['avg_dwell_seconds']),
                    'avg_dwell_formatted' => $this->formatDuration((int)$row['avg_dwell_seconds'])
                ];
            }, $topPagesStmt->fetchAll(PDO::FETCH_ASSOC));

            // 4. Top 5 Drop-off / Exit Pages
            $exitStmt = $db->prepare("
                SELECT 
                    vs.exit_page as url,
                    COUNT(*) as exit_count
                FROM visitor_sessions vs
                WHERE {$whereSql} AND vs.exit_page IS NOT NULL AND vs.exit_page != ''
                GROUP BY vs.exit_page
                ORDER BY exit_count DESC
                LIMIT 5
            ");
            $exitStmt->execute($params);
            $topExitPages = array_map(function($row) {
                return [
                    'url' => $row['url'],
                    'exit_count' => (int)$row['exit_count']
                ];
            }, $exitStmt->fetchAll(PDO::FETCH_ASSOC));

            // 5. Top UTM Sources
            $utmStmt = $db->prepare("
                SELECT 
                    COALESCE(NULLIF(vs.utm_source, ''), 'Direct / Organic') as source,
                    COUNT(*) as session_count,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as conversions
                FROM visitor_sessions vs
                WHERE {$whereSql}
                GROUP BY source
                ORDER BY session_count DESC
                LIMIT 5
            ");
            $utmStmt->execute($params);
            $topSources = $utmStmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Paginated Session Rows
            $selectFields = "
                vs.id, vs.organization_id, vs.session_id, vs.visitor_id,
                vs.referrer, vs.utm_source, vs.utm_medium, vs.utm_campaign,
                vs.entry_page, vs.exit_page, vs.total_pages, vs.total_dwell_seconds,
                vs.conversion_status, vs.converted_at, vs.started_at, vs.last_active_at
            ";

            if ($isSuperAdmin) {
                $selectFields .= ", o.name as org_name, o.slug as org_slug";
            }

            // Left join with leads to bring forward contact details if converted
            $selectFields .= ", l.name as lead_name, l.email as lead_email, l.phone as lead_phone, l.program_interest as lead_program";

            $joinSql = "LEFT JOIN leads l ON (l.session_id = vs.session_id)";
            if ($isSuperAdmin) {
                $joinSql .= " LEFT JOIN organizations o ON vs.organization_id = o.id";
            }

            $sessionsSql = "
                SELECT {$selectFields}
                FROM visitor_sessions vs
                {$joinSql}
                WHERE {$whereSql}
                GROUP BY vs.id
                ORDER BY vs.started_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $sessStmt = $db->prepare($sessionsSql);
            foreach ($params as $k => $v) {
                $sessStmt->bindValue($k, $v);
            }
            $sessStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $sessStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $sessStmt->execute();
            $sessionsRaw = $sessStmt->fetchAll(PDO::FETCH_ASSOC);

            $sessions = array_map(function($row) {
                $row['total_dwell_formatted'] = $this->formatDuration((int)$row['total_dwell_seconds']);
                $row['has_lead'] = !empty($row['lead_name']) || !empty($row['lead_email']) || !empty($row['lead_phone']);
                return $row;
            }, $sessionsRaw);

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => $summary,
                    'top_pages' => $topPages,
                    'top_exit_pages' => $topExitPages,
                    'top_sources' => $topSources,
                    'sessions' => $sessions,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_records' => $totalSessions,
                        'total_pages' => ceil($totalSessions / $limit)
                    ]
                ]
            ], 200);
        } catch (Throwable $e) {
            error_log('[SessionJourneyController] processSessionQuery error: ' . $e->getMessage());
            Response::error('Failed to load session journey analytics.', 500);
        }
    }

    /**
     * Internal helper to fetch step-by-step chronological page views for a specific session.
     */
    private function processStepsQuery(string $sessionId, ?int $orgId, bool $isSuperAdmin = false): void
    {
        try {
            $db = Database::getConnection();

            // 1. Fetch Session Record
            $sessSql = "
                SELECT vs.*, o.name as org_name,
                       l.name as lead_name, l.email as lead_email, l.phone as lead_phone, l.program_interest as lead_program
                FROM visitor_sessions vs
                LEFT JOIN organizations o ON vs.organization_id = o.id
                LEFT JOIN leads l ON (l.session_id = vs.session_id)
                WHERE vs.session_id = :sid
            ";
            if ($orgId !== null && !$isSuperAdmin) {
                $sessSql .= " AND vs.organization_id = :oid";
            }
            $sessSql .= " LIMIT 1";

            $sessStmt = $db->prepare($sessSql);
            $sessParams = [':sid' => $sessionId];
            if ($orgId !== null && !$isSuperAdmin) {
                $sessParams[':oid'] = $orgId;
            }
            $sessStmt->execute($sessParams);
            $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                Response::error('Session not found.', 404);
                return;
            }

            $session['total_dwell_formatted'] = $this->formatDuration((int)$session['total_dwell_seconds']);

            // 2. Fetch Chronological Page Views
            $stepsStmt = $db->prepare("
                SELECT id, url, page_title, time_spent_seconds, view_order, created_at, updated_at
                FROM visitor_page_views
                WHERE session_id = :sid
                ORDER BY view_order ASC, created_at ASC
            ");
            $stepsStmt->execute([':sid' => $sessionId]);
            $stepsRaw = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

            $steps = array_map(function($step) {
                $step['dwell_formatted'] = $this->formatDuration((int)$step['time_spent_seconds']);
                return $step;
            }, $stepsRaw);

            // 3. Fetch any conversation messages if visitor engaged in chat
            $convStmt = $db->prepare("
                SELECT c.id as conversation_id, c.started_at, c.page_url,
                       (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) as message_count
                FROM conversations c
                WHERE (c.session_id = :sid OR c.visitor_id = :vid)
                  AND c.organization_id = :oid
                ORDER BY c.started_at DESC LIMIT 1
            ");
            $convStmt->execute([
                ':sid' => $sessionId,
                ':vid' => $session['visitor_id'],
                ':oid' => $session['organization_id']
            ]);
            $conversation = $convStmt->fetch(PDO::FETCH_ASSOC);

            Response::json([
                'status' => 'success',
                'data' => [
                    'session' => $session,
                    'steps' => $steps,
                    'conversation' => $conversation ?: null
                ]
            ], 200);
        } catch (Throwable $e) {
            error_log('[SessionJourneyController] processStepsQuery error: ' . $e->getMessage());
            Response::error('Failed to load session steps.', 500);
        }
    }

    /**
     * GET /v1/analytics/session-journeys/top-visited-pages
     * Full paginated report of top visited pages with average dwell times.
     */
    public function getTopVisitedPagesReport(Request $request): void
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId <= 0) {
            Response::error('Unauthorized organization context.', 403);
            return;
        }

        try {
            $db = Database::getConnection();

            $daysParam = (int)($request->get('days') ?? 30);
            if (!in_array($daysParam, [7, 30, 60, 90, 180, 365])) {
                $daysParam = 30;
            }

            $search = trim($request->get('search') ?? '');
            $sortBy = trim($request->get('sort_by') ?? 'visits');
            $sortDir = strtolower(trim($request->get('sort_dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $page = max(1, (int)($request->get('page') ?? 1));
            $limit = min(100, max(10, (int)($request->get('limit') ?? 25)));
            $offset = ($page - 1) * $limit;

            $where = [
                "vpv.organization_id = :org_id",
                "vpv.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"
            ];
            $params = [
                ':org_id' => $orgId,
                ':days' => $daysParam
            ];

            if (!empty($search)) {
                $where[] = "(vpv.url LIKE :search OR vpv.page_title LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            // Total count of distinct pages matching filter
            $countStmt = $db->prepare("
                SELECT COUNT(DISTINCT vpv.url) as total_distinct
                FROM visitor_page_views vpv
                WHERE {$whereSql}
            ");
            $countStmt->execute($params);
            $totalRecords = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total_distinct'] ?? 0);

            // Overall KPI summary for this timeframe
            $summaryStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_page_views,
                    COUNT(DISTINCT vpv.url) as unique_pages,
                    COUNT(DISTINCT vpv.visitor_id) as total_unique_visitors,
                    COALESCE(AVG(vpv.time_spent_seconds), 0) as avg_dwell_seconds,
                    COALESCE(SUM(vpv.time_spent_seconds), 0) as total_dwell_seconds
                FROM visitor_page_views vpv
                WHERE vpv.organization_id = :org_id 
                  AND vpv.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $summaryStmt->execute([':org_id' => $orgId, ':days' => $daysParam]);
            $sumRaw = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $totalPageViewsOverall = (int)($sumRaw['total_page_views'] ?? 0);

            $summary = [
                'total_page_views' => $totalPageViewsOverall,
                'unique_pages' => (int)($sumRaw['unique_pages'] ?? 0),
                'total_unique_visitors' => (int)($sumRaw['total_unique_visitors'] ?? 0),
                'avg_dwell_seconds' => round((float)($sumRaw['avg_dwell_seconds'] ?? 0)),
                'avg_dwell_formatted' => $this->formatDuration((int)($sumRaw['avg_dwell_seconds'] ?? 0)),
                'total_dwell_formatted' => $this->formatDuration((int)($sumRaw['total_dwell_seconds'] ?? 0)),
                'days' => $daysParam
            ];

            // Order clause
            $orderBy = 'visit_count DESC';
            if ($sortBy === 'dwell') {
                $orderBy = "avg_dwell_seconds {$sortDir}, visit_count DESC";
            } elseif ($sortBy === 'unique_visitors') {
                $orderBy = "unique_visitors {$sortDir}, visit_count DESC";
            } else {
                $orderBy = "visit_count {$sortDir}, avg_dwell_seconds DESC";
            }

            // Paginated dataset
            $dataStmt = $db->prepare("
                SELECT 
                    vpv.url,
                    MAX(vpv.page_title) as page_title,
                    COUNT(*) as visit_count,
                    COUNT(DISTINCT vpv.visitor_id) as unique_visitors,
                    COUNT(DISTINCT vpv.session_id) as unique_sessions,
                    COALESCE(AVG(vpv.time_spent_seconds), 0) as avg_dwell_seconds,
                    COALESCE(SUM(vpv.time_spent_seconds), 0) as total_dwell_seconds,
                    MIN(vpv.created_at) as first_seen,
                    MAX(vpv.created_at) as last_seen
                FROM visitor_page_views vpv
                WHERE {$whereSql}
                GROUP BY vpv.url
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) {
                $dataStmt->bindValue($k, $v);
            }
            $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $dataStmt->execute();
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $pages = array_map(function($row) use ($totalPageViewsOverall) {
                $visits = (int)$row['visit_count'];
                $trafficShare = $totalPageViewsOverall > 0 ? round(($visits / $totalPageViewsOverall) * 100, 1) : 0;
                return [
                    'url' => $row['url'],
                    'page_title' => $row['page_title'] ?: $row['url'],
                    'visit_count' => $visits,
                    'unique_visitors' => (int)$row['unique_visitors'],
                    'unique_sessions' => (int)$row['unique_sessions'],
                    'avg_dwell_seconds' => round((float)$row['avg_dwell_seconds']),
                    'avg_dwell_formatted' => $this->formatDuration((int)$row['avg_dwell_seconds']),
                    'total_dwell_seconds' => (int)$row['total_dwell_seconds'],
                    'total_dwell_formatted' => $this->formatDuration((int)$row['total_dwell_seconds']),
                    'traffic_share_percent' => $trafficShare,
                    'first_seen' => $row['first_seen'],
                    'last_seen' => $row['last_seen']
                ];
            }, $rows);

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => $summary,
                    'pages' => $pages,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_records' => $totalRecords,
                        'total_pages' => (int)ceil($totalRecords / $limit)
                    ]
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[SessionJourneyController] getTopVisitedPagesReport error: ' . $e->getMessage());
            Response::error('Failed to load visited pages report.', 500);
        }
    }

    /**
     * GET /v1/analytics/session-journeys/top-exit-pages
     * Full paginated report of top drop-off / exit pages.
     */
    public function getTopExitPagesReport(Request $request): void
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId <= 0) {
            Response::error('Unauthorized organization context.', 403);
            return;
        }

        try {
            $db = Database::getConnection();

            $daysParam = (int)($request->get('days') ?? 30);
            if (!in_array($daysParam, [7, 30, 60, 90, 180, 365])) {
                $daysParam = 30;
            }

            $search = trim($request->get('search') ?? '');
            $status = trim($request->get('status') ?? 'all');
            $sortBy = trim($request->get('sort_by') ?? 'exits');
            $sortDir = strtolower(trim($request->get('sort_dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $page = max(1, (int)($request->get('page') ?? 1));
            $limit = min(100, max(10, (int)($request->get('limit') ?? 25)));
            $offset = ($page - 1) * $limit;

            $where = [
                "vs.organization_id = :org_id",
                "vs.started_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
                "vs.exit_page IS NOT NULL",
                "vs.exit_page != ''"
            ];
            $params = [
                ':org_id' => $orgId,
                ':days' => $daysParam
            ];

            if ($status !== 'all' && in_array($status, ['browsing', 'chat_engaged', 'lead_converted'])) {
                $where[] = "vs.conversion_status = :status";
                $params[':status'] = $status;
            }

            if (!empty($search)) {
                $where[] = "vs.exit_page LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            // Total count of distinct exit pages
            $countStmt = $db->prepare("
                SELECT COUNT(DISTINCT vs.exit_page) as total_distinct
                FROM visitor_sessions vs
                WHERE {$whereSql}
            ");
            $countStmt->execute($params);
            $totalRecords = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total_distinct'] ?? 0);

            // KPI summary
            $summaryStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_dropoffs,
                    COUNT(DISTINCT vs.exit_page) as unique_exit_pages,
                    COUNT(DISTINCT vs.visitor_id) as unique_exit_visitors,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as count_converted,
                    COALESCE(AVG(vs.total_dwell_seconds), 0) as avg_dwell_seconds
                FROM visitor_sessions vs
                WHERE vs.organization_id = :org_id 
                  AND vs.started_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                  AND vs.exit_page IS NOT NULL AND vs.exit_page != ''
            ");
            $summaryStmt->execute([':org_id' => $orgId, ':days' => $daysParam]);
            $sumRaw = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $totalDropoffsOverall = (int)($sumRaw['total_dropoffs'] ?? 0);

            $summary = [
                'total_dropoffs' => $totalDropoffsOverall,
                'unique_exit_pages' => (int)($sumRaw['unique_exit_pages'] ?? 0),
                'unique_exit_visitors' => (int)($sumRaw['unique_exit_visitors'] ?? 0),
                'converted_exits' => (int)($sumRaw['count_converted'] ?? 0),
                'avg_dwell_formatted' => $this->formatDuration((int)($sumRaw['avg_dwell_seconds'] ?? 0)),
                'days' => $daysParam
            ];

            // Order clause
            $orderBy = 'exit_count DESC';
            if ($sortBy === 'dwell') {
                $orderBy = "avg_dwell_seconds {$sortDir}, exit_count DESC";
            } elseif ($sortBy === 'conversions') {
                $orderBy = "count_converted {$sortDir}, exit_count DESC";
            } else {
                $orderBy = "exit_count {$sortDir}";
            }

            // Paginated dataset
            $dataStmt = $db->prepare("
                SELECT 
                    vs.exit_page as url,
                    COUNT(*) as exit_count,
                    COUNT(DISTINCT vs.visitor_id) as unique_visitors,
                    COALESCE(AVG(vs.total_dwell_seconds), 0) as avg_dwell_seconds,
                    COALESCE(AVG(vs.total_pages), 0) as avg_pages,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as count_converted,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'chat_engaged' THEN 1 ELSE 0 END), 0) as count_chat_engaged,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'browsing' THEN 1 ELSE 0 END), 0) as count_browsing,
                    MAX(vs.started_at) as last_exit_at
                FROM visitor_sessions vs
                WHERE {$whereSql}
                GROUP BY vs.exit_page
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) {
                $dataStmt->bindValue($k, $v);
            }
            $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $dataStmt->execute();
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $exits = array_map(function($row) use ($totalDropoffsOverall) {
                $exitsCount = (int)$row['exit_count'];
                $dropoffShare = $totalDropoffsOverall > 0 ? round(($exitsCount / $totalDropoffsOverall) * 100, 1) : 0;
                return [
                    'url' => $row['url'],
                    'exit_count' => $exitsCount,
                    'unique_visitors' => (int)$row['unique_visitors'],
                    'avg_dwell_seconds' => round((float)$row['avg_dwell_seconds']),
                    'avg_dwell_formatted' => $this->formatDuration((int)$row['avg_dwell_seconds']),
                    'avg_pages' => round((float)$row['avg_pages'], 1),
                    'count_converted' => (int)$row['count_converted'],
                    'count_chat_engaged' => (int)$row['count_chat_engaged'],
                    'count_browsing' => (int)$row['count_browsing'],
                    'dropoff_share_percent' => $dropoffShare,
                    'last_exit_at' => $row['last_exit_at']
                ];
            }, $rows);

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => $summary,
                    'exit_pages' => $exits,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_records' => $totalRecords,
                        'total_pages' => (int)ceil($totalRecords / $limit)
                    ]
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[SessionJourneyController] getTopExitPagesReport error: ' . $e->getMessage());
            Response::error('Failed to load exit pages report.', 500);
        }
    }

    /**
     * GET /v1/analytics/session-journeys/campaign-sources
     * Full paginated report of UTM and referrer traffic sources.
     */
    public function getCampaignSourcesReport(Request $request): void
    {
        $orgId = $this->resolveOrgId($request);
        if ($orgId <= 0) {
            Response::error('Unauthorized organization context.', 403);
            return;
        }

        try {
            $db = Database::getConnection();

            $daysParam = (int)($request->get('days') ?? 30);
            if (!in_array($daysParam, [7, 30, 60, 90, 180, 365])) {
                $daysParam = 30;
            }

            $search = trim($request->get('search') ?? '');
            $channel = trim($request->get('channel') ?? 'all');
            $status = trim($request->get('status') ?? 'all');
            $sortBy = trim($request->get('sort_by') ?? 'sessions');
            $sortDir = strtolower(trim($request->get('sort_dir') ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
            $page = max(1, (int)($request->get('page') ?? 1));
            $limit = min(100, max(10, (int)($request->get('limit') ?? 25)));
            $offset = ($page - 1) * $limit;

            $where = [
                "vs.organization_id = :org_id",
                "vs.started_at >= DATE_SUB(NOW(), INTERVAL :days DAY)"
            ];
            $params = [
                ':org_id' => $orgId,
                ':days' => $daysParam
            ];

            if ($status !== 'all' && in_array($status, ['browsing', 'chat_engaged', 'lead_converted'])) {
                $where[] = "vs.conversion_status = :status";
                $params[':status'] = $status;
            }

            if ($channel === 'direct') {
                $where[] = "(vs.utm_source IS NULL OR vs.utm_source = '') AND (vs.referrer IS NULL OR vs.referrer = '')";
            } elseif ($channel === 'referral') {
                $where[] = "(vs.utm_source IS NULL OR vs.utm_source = '') AND (vs.referrer IS NOT NULL AND vs.referrer != '')";
            } elseif ($channel === 'paid') {
                $where[] = "(vs.utm_medium IN ('cpc', 'ppc', 'paid', 'ad', 'ads') OR vs.utm_source LIKE '%google%' OR vs.utm_source LIKE '%facebook%' OR vs.utm_source LIKE '%instagram%' OR vs.utm_source LIKE '%linkedin%')";
            } elseif ($channel === 'organic') {
                $where[] = "(vs.utm_medium = 'organic' OR (vs.referrer LIKE '%google%' AND (vs.utm_medium IS NULL OR vs.utm_medium = '')))";
            }

            if (!empty($search)) {
                $where[] = "(vs.utm_source LIKE :search OR vs.utm_medium LIKE :search OR vs.utm_campaign LIKE :search OR vs.referrer LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            $whereSql = implode(' AND ', $where);

            // Total count of distinct source/medium/campaign combos
            $countStmt = $db->prepare("
                SELECT COUNT(*) as total_distinct FROM (
                    SELECT 1
                    FROM visitor_sessions vs
                    WHERE {$whereSql}
                    GROUP BY 
                        COALESCE(NULLIF(vs.utm_source, ''), CASE WHEN vs.referrer IS NOT NULL AND vs.referrer != '' THEN 'Referral' ELSE 'Direct / Organic' END),
                        COALESCE(NULLIF(vs.utm_medium, ''), '-'),
                        COALESCE(NULLIF(vs.utm_campaign, ''), '-')
                ) as subq
            ");
            $countStmt->execute($params);
            $totalRecords = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total_distinct'] ?? 0);

            // KPI summary
            $summaryStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_sessions,
                    COUNT(DISTINCT vs.visitor_id) as total_unique_visitors,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as total_conversions,
                    COALESCE(AVG(vs.total_dwell_seconds), 0) as avg_dwell_seconds
                FROM visitor_sessions vs
                WHERE vs.organization_id = :org_id 
                  AND vs.started_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $summaryStmt->execute([':org_id' => $orgId, ':days' => $daysParam]);
            $sumRaw = $summaryStmt->fetch(PDO::FETCH_ASSOC);

            $totalSessOverall = (int)($sumRaw['total_sessions'] ?? 0);
            $totalConvOverall = (int)($sumRaw['total_conversions'] ?? 0);
            $convRateOverall = $totalSessOverall > 0 ? round(($totalConvOverall / $totalSessOverall) * 100, 1) : 0;

            $summary = [
                'total_sessions' => $totalSessOverall,
                'total_unique_visitors' => (int)($sumRaw['total_unique_visitors'] ?? 0),
                'total_conversions' => $totalConvOverall,
                'overall_conversion_rate' => $convRateOverall,
                'avg_dwell_formatted' => $this->formatDuration((int)($sumRaw['avg_dwell_seconds'] ?? 0)),
                'days' => $daysParam
            ];

            // Order clause
            $orderBy = 'session_count DESC';
            if ($sortBy === 'conversions') {
                $orderBy = "conversions {$sortDir}, session_count DESC";
            } elseif ($sortBy === 'dwell') {
                $orderBy = "avg_dwell_seconds {$sortDir}, session_count DESC";
            } else {
                $orderBy = "session_count {$sortDir}";
            }

            // Paginated dataset
            $dataStmt = $db->prepare("
                SELECT 
                    COALESCE(NULLIF(vs.utm_source, ''), CASE WHEN vs.referrer IS NOT NULL AND vs.referrer != '' THEN 'Referral' ELSE 'Direct / Organic' END) as source,
                    COALESCE(NULLIF(vs.utm_medium, ''), '-') as medium,
                    COALESCE(NULLIF(vs.utm_campaign, ''), '-') as campaign,
                    COUNT(*) as session_count,
                    COUNT(DISTINCT vs.visitor_id) as unique_visitors,
                    COALESCE(AVG(vs.total_dwell_seconds), 0) as avg_dwell_seconds,
                    COALESCE(AVG(vs.total_pages), 0) as avg_pages_per_session,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'lead_converted' THEN 1 ELSE 0 END), 0) as conversions,
                    COALESCE(SUM(CASE WHEN vs.conversion_status = 'chat_engaged' THEN 1 ELSE 0 END), 0) as chat_engaged,
                    MAX(vs.started_at) as last_session_at
                FROM visitor_sessions vs
                WHERE {$whereSql}
                GROUP BY source, medium, campaign
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset
            ");
            foreach ($params as $k => $v) {
                $dataStmt->bindValue($k, $v);
            }
            $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $dataStmt->execute();
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $sources = array_map(function($row) {
                $sess = (int)$row['session_count'];
                $conv = (int)$row['conversions'];
                $rate = $sess > 0 ? round(($conv / $sess) * 100, 1) : 0;
                return [
                    'source' => $row['source'],
                    'medium' => $row['medium'],
                    'campaign' => $row['campaign'],
                    'session_count' => $sess,
                    'unique_visitors' => (int)$row['unique_visitors'],
                    'avg_dwell_seconds' => round((float)$row['avg_dwell_seconds']),
                    'avg_dwell_formatted' => $this->formatDuration((int)$row['avg_dwell_seconds']),
                    'avg_pages_per_session' => round((float)$row['avg_pages_per_session'], 1),
                    'conversions' => $conv,
                    'chat_engaged' => (int)$row['chat_engaged'],
                    'conversion_rate' => $rate,
                    'last_session_at' => $row['last_session_at']
                ];
            }, $rows);

            Response::json([
                'status' => 'success',
                'data' => [
                    'summary' => $summary,
                    'sources' => $sources,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_records' => $totalRecords,
                        'total_pages' => (int)ceil($totalRecords / $limit)
                    ]
                ]
            ]);
        } catch (Throwable $e) {
            error_log('[SessionJourneyController] getCampaignSourcesReport error: ' . $e->getMessage());
            Response::error('Failed to load campaign sources report.', 500);
        }
    }

    /**
     * Format seconds into human readable format (e.g. 45s, 2m 15s, 1h 10m).
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $minutes = floor($seconds / 60);
        $remSec = $seconds % 60;
        if ($minutes < 60) {
            return $remSec > 0 ? "{$minutes}m {$remSec}s" : "{$minutes}m";
        }
        $hours = floor($minutes / 60);
        $remMin = $minutes % 60;
        return $remMin > 0 ? "{$hours}h {$remMin}m" : "{$hours}h";
    }
}
