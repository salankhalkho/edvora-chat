<?php

// Front Controller for edvora.chat

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/app/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use App\Config\Env;
use App\Controllers\AnalyticsController;
use App\Controllers\AssetController;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\CallbackController;
use App\Controllers\CampusController;
use App\Controllers\CampusTourController;
use App\Controllers\CampusTourSchedulingController;
use App\Controllers\ChatbotController;
use App\Controllers\ChatController;
use App\Controllers\ConversionEngineController;
use App\Controllers\CourseController;
use App\Controllers\DatabaseManagerController;
use App\Controllers\DemoPreviewController;
use App\Controllers\DepartmentController;
use App\Controllers\KnowledgeController;
use App\Controllers\LeadController;
use App\Controllers\OnboardingController;
use App\Controllers\OrganizationController;
use App\Controllers\ProgramController;
use App\Controllers\ScholarshipController;
use App\Controllers\SmartOnboardingController;
use App\Controllers\SuperAdminController;
use App\Controllers\WidgetCustomizationController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SuperAdminMiddleware;
use App\Middleware\TenantMiddleware;

// Load environment variables
Env::load();

// Enforce SSL/HTTPS Redirection (Defense in Depth)
if (php_sapi_name() !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost');

    if (!$isLocalhost) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off');

        if (!$isHttps && !empty($host)) {
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
    }
}

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', Env::get('APP_DEBUG') === 'true' ? '1' : '0');

set_exception_handler(function (Throwable $e) {
    error_log("EDVORA_UNCAUGHT_EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());
    Response::error(
        Env::get('APP_DEBUG') === 'true' ? $e->getMessage() : 'An internal server error occurred.',
        500
    );
});

// Initialize Request & Router
$request = new Request();
$router = new Router();

// Base Landing Page Route
$router->get('/', function (Request $req) {
    require dirname(__DIR__) . '/public/landing.html';
});

$router->get('/landing-copy', function (Request $req) {
    require dirname(__DIR__) . '/public/landing-copy.html';
});

$router->get('/landing-copy.html', function (Request $req) {
    require dirname(__DIR__) . '/public/landing-copy.html';
});

// Admissions Command Center Dashboard Template Route
$router->get('/dashboard', function (Request $req) {
    require dirname(__DIR__) . '/public/dashboard.html';
});

$router->get('/dashboard.html', function (Request $req) {
    require dirname(__DIR__) . '/public/dashboard.html';
});

// Central Knowledge Hub Dashboard Template Route
$router->get('/knowledge-hub', function (Request $req) {
    require dirname(__DIR__) . '/public/knowledge-hub.html';
});

$router->get('/knowledge-hub.html', function (Request $req) {
    require dirname(__DIR__) . '/public/knowledge-hub.html';
});

// Dedicated Pricing Page Route
$router->get('/pricing', function (Request $req) {
    require dirname(__DIR__) . '/app/Views/pricing.php';
});

// Institutional Compliance & Legal Routes
$router->get('/privacy', function (Request $req) {
    require dirname(__DIR__) . '/app/Views/privacy.php';
});
$router->get('/terms', function (Request $req) {
    require dirname(__DIR__) . '/app/Views/terms.php';
});
$router->get('/contact', function (Request $req) {
    require dirname(__DIR__) . '/public/landing.html';
});

// Personalized Interactive Demo Builder Tool Routes
$router->get('/try', function (Request $req) {
    require dirname(__DIR__) . '/public/preview.html';
});
$router->get('/preview', function (Request $req) {
    require dirname(__DIR__) . '/public/preview.html';
});

$router->get('/v1/health', function (Request $req) {
    Response::success([
        'status' => 'healthy',
        'php' => PHP_VERSION,
        'time' => time()
    ]);
});

// College Admin Dashboard & Registration SPA Routes
$router->get('/app', function (Request $req) {
    require dirname(__DIR__) . '/public/app/index.html';
});
$router->get('/register', function (Request $req) {
    header('Location: /app/#signup', true, 302);
    exit;
});
$router->get('/signup', function (Request $req) {
    header('Location: /app/#signup', true, 302);
    exit;
});
$router->get('/login', function (Request $req) {
    header('Location: /app/#login', true, 302);
    exit;
});

// Super Admin Dashboard SPA Route
$router->get('/superadmin', function (Request $req) {
    require dirname(__DIR__) . '/public/superadmin/index.html';
});

// Standalone Public Chatbot Testing & Mobile Webview Route (Shareable without login)
$router->get('/test/{bot_token}', function (Request $req, array $params = []) {
    require dirname(__DIR__) . '/public/test_chat.html';
});
$router->get('/test', function (Request $req, array $params = []) {
    require dirname(__DIR__) . '/public/test_chat.html';
});
$router->get('/widget/{bot_token}', function (Request $req, array $params = []) {
    require dirname(__DIR__) . '/public/test_chat.html';
});

// Dedicated Mobile App Integration Resource Route
$router->get('/mobile-integration', function (Request $req) {
    require dirname(__DIR__) . '/public/mobile_integration.html';
});
$router->get('/docs/mobile-integration', function (Request $req) {
    require dirname(__DIR__) . '/public/mobile_integration.html';
});

// Mobile Event Toast Visualizer Test Route
$router->get('/mobile-toast-preview', function (Request $req) {
    require dirname(__DIR__) . '/public/mobile-toast-preview.html';
});

// Standalone Static Template Preview Route
$router->get('/template', function (Request $req) {
    require dirname(__DIR__) . '/public/template/index.html';
});
$router->get('/template/index.html', function (Request $req) {
    require dirname(__DIR__) . '/public/template/index.html';
});
$router->get('/template/index2.html', function (Request $req) {
    require dirname(__DIR__) . '/public/template/index2.html';
});
$router->get('/template/index2', function (Request $req) {
    require dirname(__DIR__) . '/public/template/index2.html';
});

// Auth Routes (Public)
$router->post('/v1/auth/signup', [AuthController::class, 'signup']);
$router->post('/v1/auth/login', [AuthController::class, 'login']);
$router->post('/v1/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/v1/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/v1/auth/reset-password', [AuthController::class, 'resetPassword']);

// Auth Routes (Protected)
$router->get('/v1/auth/me', [AuthController::class, 'me'], [AuthMiddleware::class]);
$router->put('/v1/auth/profile', [AuthController::class, 'updateProfile'], [AuthMiddleware::class]);

// Onboarding Engine Routes (Protected + Tenant Context)
$router->get('/v1/onboarding/scrape-stream', [SmartOnboardingController::class, 'scrapeStream']);
$router->post('/v1/onboarding/smart-complete', [SmartOnboardingController::class, 'smartComplete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/onboarding/status', [OnboardingController::class, 'getStatus'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/onboarding/step', [OnboardingController::class, 'saveStep'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/onboarding/complete', [OnboardingController::class, 'complete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/onboarding/upload-document', [OnboardingController::class, 'uploadDocument'], [AuthMiddleware::class, TenantMiddleware::class]);

// Organization Profile & Team Routes (Protected + Tenant Context)
$router->get('/v1/organization/profile', [OrganizationController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/profile', [OrganizationController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/organization/languages', [OrganizationController::class, 'getLanguages'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/languages', [OrganizationController::class, 'updateLanguages'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/organization/operating-hours', [OrganizationController::class, 'getOperatingHours'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/operating-hours', [OrganizationController::class, 'updateOperatingHours'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/organization/escalation-rules', [OrganizationController::class, 'getEscalationRules'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/escalation-rules', [OrganizationController::class, 'updateEscalationRules'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/organization/staff', [OrganizationController::class, 'indexStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/organization/staff', [OrganizationController::class, 'createStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/staff/{id}', [OrganizationController::class, 'updateStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/organization/staff/{id}', [OrganizationController::class, 'deleteStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/organization/staff/{id}/password', [OrganizationController::class, 'updateStaffPassword'], [AuthMiddleware::class, TenantMiddleware::class]);

// Campuses Management Routes (Protected + Tenant Context)
$router->get('/v1/campuses', [CampusController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/campuses/{id}', [CampusController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/campuses/{id}/courses', [CampusController::class, 'courses'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/campuses/{id}/courses', [CampusController::class, 'syncCourses'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/campuses', [CampusController::class, 'store'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/campuses/{id}', [CampusController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/campuses/{id}', [CampusController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);

// Knowledge Base Routes (Protected + Tenant Context)
$router->get('/v1/knowledge/health-summary', [KnowledgeController::class, 'healthSummary'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/knowledge', [KnowledgeController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/upload', [KnowledgeController::class, 'upload'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/paste', [KnowledgeController::class, 'paste'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/url', [KnowledgeController::class, 'addUrl'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/search', [KnowledgeController::class, 'search'], [AuthMiddleware::class, TenantMiddleware::class]);

$router->get('/v1/knowledge/{id}', [KnowledgeController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/knowledge/{id}/download', [KnowledgeController::class, 'download'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/knowledge/{id}', [KnowledgeController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}', [KnowledgeController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}/replace', [KnowledgeController::class, 'replaceVersion'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}/extend', [KnowledgeController::class, 'extendValidity'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}/review', [KnowledgeController::class, 'markReviewed'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}/archive', [KnowledgeController::class, 'archive'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/knowledge/{id}', [KnowledgeController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/knowledge/{id}/refresh', [KnowledgeController::class, 'refresh'], [AuthMiddleware::class, TenantMiddleware::class]);

// Lead Assets Routes (Protected + Tenant Context & Public Download)
$router->get('/v1/assets', [AssetController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/assets/upload', [AssetController::class, 'upload'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/assets/{id}', [AssetController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/assets/{id}', [AssetController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/assets/{id}', [AssetController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/assets/{id}/download', [AssetController::class, 'download']);
$router->post('/v1/assets/{id}/deliver', [AssetController::class, 'deliver']);

// Lead Routes
$router->post('/v1/leads', [LeadController::class, 'store']);
$router->get('/v1/leads', [LeadController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/leads/export', [LeadController::class, 'export'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/leads/{id}', [LeadController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/leads/{id}', [LeadController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/leads/{id}', [LeadController::class, 'destroy'], [AuthMiddleware::class, TenantMiddleware::class]);

// Counselor Callback Routes
$router->post('/v1/callbacks', [CallbackController::class, 'store']);
$router->get('/v1/callbacks', [CallbackController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/callbacks/export', [CallbackController::class, 'export'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/callbacks/{id}', [CallbackController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/callbacks/{id}', [CallbackController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);

// Campus Tour Booking Routes
$router->post('/v1/campus-tours', [CampusTourController::class, 'store']);
$router->get('/v1/campus-tours', [CampusTourController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/campus-tours/export', [CampusTourController::class, 'export'], [AuthMiddleware::class, TenantMiddleware::class]);

// Campus Tour Scheduling & Rules Routes — must be BEFORE the {id} wildcard
$router->get('/v1/campus-tours/slots', [CampusTourSchedulingController::class, 'indexSlots']);
$router->post('/v1/campus-tours/slots', [CampusTourSchedulingController::class, 'storeSlot'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/campus-tours/slots/{id}', [CampusTourSchedulingController::class, 'deleteSlot'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/campus-tours/settings', [CampusTourSchedulingController::class, 'getSettings'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/campus-tours/settings', [CampusTourSchedulingController::class, 'saveSettings'], [AuthMiddleware::class, TenantMiddleware::class]);

// Campus Tour Booking Wildcard Routes (must come AFTER specific paths above)
$router->get('/v1/campus-tours/{id}', [CampusTourController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/campus-tours/{id}', [CampusTourController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/campus-tours/{id}/feedback', [CampusTourController::class, 'triggerFeedback'], [AuthMiddleware::class, TenantMiddleware::class]);

// Conversion Engine Routes (Protected + Tenant Context)
$router->get('/v1/conversion-engine/overview', [ConversionEngineController::class, 'overview'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/conversion-engine/pipeline', [ConversionEngineController::class, 'pipeline'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/conversion-engine/leads/{id}/stage', [ConversionEngineController::class, 'updateStage'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/conversion-engine/leads/{id}/intelligence', [ConversionEngineController::class, 'intelligence'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/conversion-engine/follow-ups', [ConversionEngineController::class, 'followUps'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/conversion-engine/follow-ups/{id}/complete', [ConversionEngineController::class, 'completeFollowUp'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/conversion-engine/pulse', [ConversionEngineController::class, 'pulse'], [AuthMiddleware::class, TenantMiddleware::class]);

// Analytics Routes (Protected + Tenant Context)
$router->get('/v1/analytics/summary', [AnalyticsController::class, 'summary'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/analytics/overview', [AnalyticsController::class, 'overview'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/analytics/gaps', [AnalyticsController::class, 'gapsBreakup'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/analytics/gaps/resolve', [AnalyticsController::class, 'resolveGap'], [AuthMiddleware::class, TenantMiddleware::class]);

// Chatbot Management Routes (Protected + Tenant Context)
$router->get('/v1/chatbots', [ChatbotController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/chatbots/{id}', [ChatbotController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);

// Widget Customization Routes (Protected + Tenant Context)
$router->get('/v1/widget/customization', [WidgetCustomizationController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/widget/customization', [WidgetCustomizationController::class, 'upsert'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/widget/avatar/upload', [WidgetCustomizationController::class, 'uploadImage'], [AuthMiddleware::class, TenantMiddleware::class]);

// Serve uploaded avatar/logo images (Public)
$router->get('/v1/uploads/avatars/{org_id}/{filename}', [WidgetCustomizationController::class, 'serveImage']);

// Billing & Subscription Routes (Protected + Tenant Context)
$router->get('/v1/billing/subscription', [BillingController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/billing/create-subscription', [BillingController::class, 'createSubscription'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/billing/webhook', [BillingController::class, 'webhook']);

// Public Plans & Pricing API Route
$router->get('/v1/public/plans', function (Request $req) {
    $db = \App\Config\Database::getConnection();
    $stmt = $db->query("SELECT id, name, description, price_monthly_paise, price_yearly_paise, price_monthly_usd_cents, price_yearly_usd_cents, is_default, sort_order FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $plans = $stmt->fetchAll();

    foreach ($plans as &$plan) {
        $stmtQ = $db->prepare("SELECT quota_key, quota_label, quota_value, quota_period FROM plan_quotas WHERE plan_id = :pid ORDER BY id ASC");
        $stmtQ->execute([':pid' => $plan['id']]);
        $plan['quotas'] = $stmtQ->fetchAll();

        $stmtF = $db->prepare("SELECT feature_key, feature_label, is_enabled FROM plan_features WHERE plan_id = :pid ORDER BY id ASC");
        $stmtF->execute([':pid' => $plan['id']]);
        $plan['features'] = $stmtF->fetchAll();
    }

    Response::success($plans);
});

// Super Admin API Routes (Protected + Super Admin Role)
$router->get('/v1/superadmin/prompt', [SuperAdminController::class, 'getMasterPrompt'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/prompt', [SuperAdminController::class, 'updateMasterPrompt'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/llm-providers', [SuperAdminController::class, 'getLlmProviders'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/v1/superadmin/llm-providers', [SuperAdminController::class, 'createLlmProvider'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/llm-providers/{id}', [SuperAdminController::class, 'updateLlmProvider'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->delete('/v1/superadmin/llm-providers/{id}', [SuperAdminController::class, 'deleteLlmProvider'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/v1/superadmin/llm-providers/{id}/test', [SuperAdminController::class, 'testLlmProvider'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/organizations', [SuperAdminController::class, 'getOrganizations'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/organizations/{id}/status', [SuperAdminController::class, 'updateOrgStatus'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->delete('/v1/superadmin/organizations/{id}', [SuperAdminController::class, 'deleteOrganization'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/plans', [SuperAdminController::class, 'getPlans'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/plans/{id}', [SuperAdminController::class, 'updatePlan'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/data-governance', [SuperAdminController::class, 'getDataGovernance'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/smtp', [SuperAdminController::class, 'getSmtpSettings'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/smtp', [SuperAdminController::class, 'updateSmtpSettings'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/v1/superadmin/smtp/test', [SuperAdminController::class, 'testSmtpConnection'], [AuthMiddleware::class, SuperAdminMiddleware::class]);

// Super Admin Demo Intelligence Routes (Protected + Super Admin Role)
$router->get('/v1/superadmin/demos', [SuperAdminController::class, 'listDemos'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/demos/export', [SuperAdminController::class, 'exportDemosCsv'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/demos/{id}/conversation', [SuperAdminController::class, 'getDemoConversation'], [AuthMiddleware::class, SuperAdminMiddleware::class]);

// Demo Preview Engine API Routes (Public)
$router->post('/v1/preview/analyze', [DemoPreviewController::class, 'analyze']);
$router->post('/v1/preview/chat', [DemoPreviewController::class, 'chat']);
$router->post('/v1/preview/capture-email', [DemoPreviewController::class, 'captureEmail']);
$router->post('/v1/preview/counselor-request', [DemoPreviewController::class, 'counselorRequest']);

// Super Admin Database Management Routes (Protected + Super Admin Role)
$router->get('/v1/superadmin/database/tables', [DatabaseManagerController::class, 'getTables'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/database/tables/{table}', [DatabaseManagerController::class, 'getTableData'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->post('/v1/superadmin/database/tables/{table}/rows', [DatabaseManagerController::class, 'insertRow'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->put('/v1/superadmin/database/tables/{table}/rows', [DatabaseManagerController::class, 'updateRow'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->delete('/v1/superadmin/database/tables/{table}/rows', [DatabaseManagerController::class, 'deleteRow'], [AuthMiddleware::class, SuperAdminMiddleware::class]);
$router->get('/v1/superadmin/database/tables/{table}/export', [DatabaseManagerController::class, 'exportCsv'], [AuthMiddleware::class, SuperAdminMiddleware::class]);

// Public DB Schema Info Routes (No Auth — Shareable)
$router->get('/db-info', function (Request $req) {
    require dirname(__DIR__) . '/public/db-info.html';
});
$router->get('/v1/public/db-info', [DatabaseManagerController::class, 'getDbInfo']);

// Department & Team Management Routes (Protected + Tenant Context)
$router->get('/v1/departments', [DepartmentController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/departments/presets', [DepartmentController::class, 'presets'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments/preset-import', [DepartmentController::class, 'importPresets'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments', [DepartmentController::class, 'create'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/departments/{id}', [DepartmentController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/departments/{id}', [DepartmentController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments/{id}/staff', [DepartmentController::class, 'syncStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments/{id}/knowledge', [DepartmentController::class, 'syncKnowledge'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments/{id}/faqs', [DepartmentController::class, 'manageFaqs'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/departments/{id}/courses', [DepartmentController::class, 'manageCourses'], [AuthMiddleware::class, TenantMiddleware::class]);

// Academic Programs Management Routes (Clean /v1/programs Endpoints)
$router->get('/v1/programs', [ProgramController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/programs/{id}', [ProgramController::class, 'show'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/programs', [ProgramController::class, 'store'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/programs/{id}', [ProgramController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/programs/{id}', [ProgramController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/programs/{id}/staff', [ProgramController::class, 'getStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/programs/{id}/staff', [ProgramController::class, 'syncStaff'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->get('/v1/programs/{id}/lead-magnet', [ProgramController::class, 'getLeadMagnet'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/programs/{id}/lead-magnet', [ProgramController::class, 'setLeadMagnet'], [AuthMiddleware::class, TenantMiddleware::class]);

// Academic Programs & Courses Management Routes (Legacy Alias)
$router->get('/v1/courses', [CourseController::class, 'index'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/courses', [CourseController::class, 'store'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/courses/{id}', [CourseController::class, 'update'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/courses/{id}', [CourseController::class, 'delete'], [AuthMiddleware::class, TenantMiddleware::class]);

// Scholarship & Fee Rules Routes (Protected + Tenant Context)
$router->get('/v1/scholarships/config', [ScholarshipController::class, 'getConfig'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->put('/v1/scholarships/config', [ScholarshipController::class, 'updateConfig'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->post('/v1/scholarships/courses', [ScholarshipController::class, 'saveCourse'], [AuthMiddleware::class, TenantMiddleware::class]);
$router->delete('/v1/scholarships/courses/{id}', [ScholarshipController::class, 'deleteCourse'], [AuthMiddleware::class, TenantMiddleware::class]);

// Widget Config, Scholarships & Chat Completion Routes (Public Widget & Mobile REST API)
$router->get('/v1/widget/config/{bot_token}', [ChatController::class, 'widgetConfig']);
$router->get('/v1/widget/departments/{bot_token}', [DepartmentController::class, 'publicList']);
$router->get('/v1/public/dashboard/departments/{bot_token}', [DepartmentController::class, 'publicDashboard']);
$router->get('/v1/widget/scholarship/courses/{bot_token}', [ScholarshipController::class, 'publicCourses']);
$router->post('/v1/widget/scholarship/evaluate', [ScholarshipController::class, 'evaluate']);
$router->post('/v1/chat/completions', [ChatController::class, 'complete']);
$router->post('/v1/chat/message', [ChatController::class, 'complete']);
$router->post('/api/chat/message', [ChatController::class, 'complete']);
$router->post('/api/chat/completions', [ChatController::class, 'complete']);

// Dispatch request
$router->dispatch($request);
