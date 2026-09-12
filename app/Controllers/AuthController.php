<?php

namespace App\Controllers;

use App\Config\Database;
use App\Config\Env;
use App\Core\Jwt;
use App\Core\RedisClient;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;
use Throwable;

class AuthController
{
    /**
     * POST /v1/auth/signup
     */
    public function signup(Request $request, array $params = []): void
    {
        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'college_name' => 'required|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|min:8',
            'website_url' => 'required|max:500'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $email = strtolower(trim($data['email']));
        $db = Database::getConnection();

        // Check email uniqueness
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            Response::error('An account with this email address already exists.', 409);
        }

        // Authoritative college name provided by user
        $collegeName = trim($data['college_name']);

        // Normalize website URL and extract domain
        $rawWebsite = trim($data['website_url']);
        if (!preg_match('#^https?://#i', $rawWebsite)) {
            $rawWebsite = 'https://' . $rawWebsite;
        }
        $websiteParts = parse_url($rawWebsite);
        $domain = strtolower($websiteParts['host'] ?? 'university.edu');
        $domain = preg_replace('/^www\./', '', $domain);
        $normalizedWebsite = ($websiteParts['scheme'] ?? 'https') . '://' . ($websiteParts['host'] ?? $domain) . (isset($websiteParts['path']) ? rtrim($websiteParts['path'], '/') : '');

        // Generate college slug
        $slugInput = $data['college_slug'] ?? $collegeName;
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $slugInput), '-'));
        if (empty($slug)) {
            $slug = 'college-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        // Ensure slug uniqueness
        $stmtSlug = $db->prepare("SELECT id FROM organizations WHERE slug = :slug");
        $stmtSlug->execute([':slug' => $slug]);
        if ($stmtSlug->fetch()) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        // Fetch default plan (Starter)
        $stmtPlan = $db->query("SELECT id FROM plans WHERE is_default = 1 LIMIT 1");
        $defaultPlan = $stmtPlan->fetch();
        $planId = $defaultPlan ? (int)$defaultPlan['id'] : 1;

        try {
            $db->beginTransaction();

            // 1. Create Organization with website_url
            $stmtOrg = $db->prepare("
                INSERT INTO organizations (name, slug, website_url, primary_color, plan_id, subscription_status)
                VALUES (:name, :slug, :website_url, '#2563EB', :plan_id, 'active')
            ");
            $stmtOrg->execute([
                ':name' => $collegeName,
                ':slug' => $slug,
                ':website_url' => $normalizedWebsite,
                ':plan_id' => $planId
            ]);
            $orgId = (int)$db->lastInsertId();

            // 2. Create User (Owner)
            $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtUser = $db->prepare("
                INSERT INTO users (organization_id, name, email, password_hash, role, email_verified_at)
                VALUES (:org_id, :name, :email, :pass_hash, 'owner', NOW())
            ");
            $stmtUser->execute([
                ':org_id' => $orgId,
                ':name' => trim($data['name']),
                ':email' => $email,
                ':pass_hash' => $passwordHash
            ]);
            $userId = (int)$db->lastInsertId();

            // 3. Create Default Chatbot
            $botToken = bin2hex(random_bytes(16)); // 32 chars
            $welcomeMsg = "Hi there! 👋 Welcome to {$collegeName}. How can I assist you with admissions, programs, or fees today?";

            $stmtBot = $db->prepare("
                INSERT INTO chatbots (organization_id, name, welcome_message, primary_color, bot_token, is_active, lead_capture_enabled)
                VALUES (:org_id, 'AI Admissions Assistant', :welcome_msg, '#2563EB', :token, 1, 1)
            ");
            $stmtBot->execute([
                ':org_id' => $orgId,
                ':welcome_msg' => $welcomeMsg,
                ':token' => $botToken
            ]);
            $chatbotId = (int)$db->lastInsertId();

            // 4. Create Active Subscription Row
            $stmtSub = $db->prepare("
                INSERT INTO subscriptions (organization_id, plan_id, billing_cycle, status, current_period_start, current_period_end)
                VALUES (:org_id, :plan_id, 'monthly', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH))
            ");
            $stmtSub->execute([
                ':org_id' => $orgId,
                ':plan_id' => $planId
            ]);

            // 5. Create Monthly Usage Log
            $period = date('Y-m');
            $stmtUsage = $db->prepare("
                INSERT INTO usage_logs (organization_id, period, chatbots_count)
                VALUES (:org_id, :period, 1)
            ");
            $stmtUsage->execute([
                ':org_id' => $orgId,
                ':period' => $period
            ]);

            $db->commit();

            // Generate JWT Tokens
            $accessTokenPayload = [
                'user_id' => $userId,
                'organization_id' => $orgId,
                'role' => 'owner',
                'email' => $email,
                'type' => 'access'
            ];
            $refreshTokenPayload = [
                'user_id' => $userId,
                'organization_id' => $orgId,
                'type' => 'refresh'
            ];

            $accessToken = Jwt::encode($accessTokenPayload, 900); // 15 mins
            $refreshToken = Jwt::encode($refreshTokenPayload, 2592000); // 30 days

            // Audit log
            $GLOBALS['auth_user'] = $accessTokenPayload;
            AuditLogger::log('signup_success', 'user', $userId, ['organization_name' => $collegeName]);

            Response::success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer',
                'expires_in' => 900,
                'user' => [
                    'id' => $userId,
                    'name' => trim($data['name']),
                    'email' => $email,
                    'role' => 'owner'
                ],
                'organization' => [
                    'id' => $orgId,
                    'name' => $collegeName,
                    'slug' => $slug,
                    'website_url' => $normalizedWebsite,
                    'onboarding_completed' => 0,
                    'onboarding_step' => 1
                ],
                'chatbot' => [
                    'id' => $chatbotId,
                    'name' => 'AI Admissions Assistant',
                    'bot_token' => $botToken
                ],
                'onboarding_required' => true,
                'onboarding_mode' => 'smart_scrape'
            ], 'Account created successfully', 201);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error('Failed to create college account: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /v1/auth/login
     */
    public function login(Request $request, array $params = []): void
    {
        $data = $request->all();

        $errors = Validator::validate($data, [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $ip = $request->getClientIp();
        $rateKey = "login_attempts:" . md5($ip);
        $attempts = (int) RedisClient::get($rateKey);

        if ($attempts >= 5) {
            Response::error('Too many failed login attempts. Please try again after 15 minutes.', 429);
        }

        $email = strtolower(trim($data['email']));
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT u.*, o.name as org_name, o.slug as org_slug, o.subscription_status,
                   COALESCE(o.onboarding_completed, 0) as onboarding_completed,
                   COALESCE(o.onboarding_step, 1) as onboarding_step
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            WHERE u.email = :email
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            RedisClient::set($rateKey, $attempts + 1, 900); // 15 mins lock window
            AuditLogger::log('login_failed', 'user', null, ['email' => $email, 'ip' => $ip]);
            Response::error('Invalid email address or password.', 401);
        }

        // Clear failed attempts on successful login
        RedisClient::delete($rateKey);

        // Fetch primary chatbot for tenant
        $botToken = null;
        if ($user['organization_id']) {
            $stmtBot = $db->prepare("SELECT bot_token FROM chatbots WHERE organization_id = :org_id AND is_active = 1 LIMIT 1");
            $stmtBot->execute([':org_id' => $user['organization_id']]);
            $bot = $stmtBot->fetch();
            $botToken = $bot['bot_token'] ?? null;
        }

        $accessTokenPayload = [
            'user_id' => (int)$user['id'],
            'organization_id' => $user['organization_id'] ? (int)$user['organization_id'] : null,
            'role' => $user['role'],
            'email' => $user['email'],
            'type' => 'access'
        ];
        $refreshTokenPayload = [
            'user_id' => (int)$user['id'],
            'organization_id' => $user['organization_id'] ? (int)$user['organization_id'] : null,
            'type' => 'refresh'
        ];

        $ttl = ($user['role'] === 'superadmin') ? 86400 : 7200; // 24h for superadmin, 2h for users
        $accessToken = Jwt::encode($accessTokenPayload, $ttl);
        $refreshToken = Jwt::encode($refreshTokenPayload, 2592000);

        $GLOBALS['auth_user'] = $accessTokenPayload;
        AuditLogger::log('login_success', 'user', (int)$user['id']);

        $onboardingRequired = ($user['role'] !== 'superadmin' && $user['organization_id'] && (int)$user['onboarding_completed'] === 0);

        // Fetch assigned departments for user
        $stmtDepts = $db->prepare("
            SELECT d.id, d.name, d.icon, ds.role as dept_role
            FROM department_staff ds
            JOIN departments d ON ds.department_id = d.id
            WHERE ds.user_id = :user_id
        ");
        $stmtDepts->execute([':user_id' => (int)$user['id']]);
        $userDepartments = $stmtDepts->fetchAll();

        Response::success([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'can_manage_structure' => (int)($user['can_manage_structure'] ?? 1),
                'departments' => $userDepartments
            ],
            'organization' => $user['organization_id'] ? [
                'id' => (int)$user['organization_id'],
                'name' => $user['org_name'],
                'slug' => $user['org_slug'],
                'onboarding_completed' => (int)$user['onboarding_completed'],
                'onboarding_step' => (int)$user['onboarding_step']
            ] : null,
            'bot_token' => $botToken,
            'onboarding_required' => $onboardingRequired
        ], 'Login successful');
    }

    /**
     * GET /v1/auth/me
     */
    public function me(Request $request, array $params = []): void
    {
        $authUser = $GLOBALS['auth_user'] ?? null;
        if (!$authUser) {
            Response::error('Unauthorized', 401);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, COALESCE(u.can_manage_structure, 1) as can_manage_structure, u.email_verified_at, u.created_at, u.organization_id,
                   o.name as org_name, o.slug as org_slug, o.logo_url, o.website_url, o.primary_color, o.subscription_status,
                   COALESCE(o.onboarding_completed, 0) as onboarding_completed,
                   COALESCE(o.onboarding_step, 1) as onboarding_step,
                   p.name as plan_name
            FROM users u
            LEFT JOIN organizations o ON u.organization_id = o.id
            LEFT JOIN plans p ON o.plan_id = p.id
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $authUser['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 444);
        }

        // Fetch primary chatbot token
        $botToken = null;
        if ($user['organization_id']) {
            $stmtBot = $db->prepare("SELECT bot_token FROM chatbots WHERE organization_id = :org_id AND is_active = 1 LIMIT 1");
            $stmtBot->execute([':org_id' => $user['organization_id']]);
            $bot = $stmtBot->fetch();
            $botToken = $bot['bot_token'] ?? null;
        }

        // Fetch assigned departments for user
        $stmtDepts = $db->prepare("
            SELECT d.id, d.name, d.icon, ds.role as dept_role
            FROM department_staff ds
            JOIN departments d ON ds.department_id = d.id
            WHERE ds.user_id = :user_id
        ");
        $stmtDepts->execute([':user_id' => (int)$user['id']]);
        $userDepartments = $stmtDepts->fetchAll();

        Response::success([
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'can_manage_structure' => (int)$user['can_manage_structure'],
                'email_verified' => !empty($user['email_verified_at']),
                'created_at' => $user['created_at'] ?? null,
                'departments' => $userDepartments
            ],
            'organization' => $user['organization_id'] ? [
                'id' => (int)$user['organization_id'],
                'name' => $user['org_name'],
                'slug' => $user['org_slug'],
                'logo_url' => $user['logo_url'],
                'website_url' => $user['website_url'] ?? null,
                'primary_color' => $user['primary_color'],
                'subscription_status' => $user['subscription_status'],
                'onboarding_completed' => (int)$user['onboarding_completed'],
                'onboarding_step' => (int)$user['onboarding_step'],
                'plan_name' => $user['plan_name'] ?? 'Starter'
            ] : null,
            'bot_token' => $botToken,
            'onboarding_required' => $onboardingRequired
        ]);
    }

    /**
     * POST /v1/auth/refresh
     */
    public function refresh(Request $request, array $params = []): void
    {
        $refreshToken = $request->get('refresh_token');
        if (!$refreshToken) {
            Response::error('Refresh token required.', 400);
        }

        $payload = Jwt::decode($refreshToken);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            Response::error('Invalid or expired refresh token.', 401);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, organization_id, email, role FROM users WHERE id = :id");
        $stmt->execute([':id' => $payload['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User no longer exists.', 401);
        }

        $newAccessPayload = [
            'user_id' => (int)$user['id'],
            'organization_id' => $user['organization_id'] ? (int)$user['organization_id'] : null,
            'role' => $user['role'],
            'email' => $user['email'],
            'type' => 'access'
        ];

        $ttl = ($user['role'] === 'superadmin') ? 86400 : 7200;
        $newAccessToken = Jwt::encode($newAccessPayload, $ttl);

        Response::success([
            'access_token' => $newAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl
        ], 'Token refreshed');
    }

    /**
     * POST /v1/auth/forgot-password
     */
    public function forgotPassword(Request $request, array $params = []): void
    {
        $email = strtolower(trim((string)$request->get('email')));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Valid email address required.', 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            RedisClient::set("password_reset:{$token}", $user['id'], 3600); // 1 hour TTL
            AuditLogger::log('forgot_password_requested', 'user', (int)$user['id'], ['email' => $email]);
        }

        Response::success(null, 'If an account with that email exists, a password reset link has been sent.');
    }

    /**
     * POST /v1/auth/reset-password
     */
    public function resetPassword(Request $request, array $params = []): void
    {
        $token = $request->get('token');
        $newPassword = $request->get('new_password');

        $errors = Validator::validate(['token' => $token, 'new_password' => $newPassword], [
            'token' => 'required',
            'new_password' => 'required|min:8'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $userId = RedisClient::get("password_reset:{$token}");
        if (!$userId) {
            Response::error('Invalid or expired password reset token.', 400);
        }

        $passHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $stmt->execute([':hash' => $passHash, ':id' => $userId]);

        RedisClient::delete("password_reset:{$token}");
        AuditLogger::log('password_reset_success', 'user', (int)$userId);

        Response::success(null, 'Password reset successful. Please log in with your new password.');
    }

    /**
     * PUT /v1/auth/profile — Update user's name, email, and/or password
     */
    public function updateProfile(Request $request, array $params = []): void
    {
        $authUser = $GLOBALS['auth_user'] ?? null;
        if (!$authUser) {
            Response::error('Unauthorized', 401);
            return;
        }

        $userId = (int)($authUser['user_id'] ?? 0);
        if (!$userId) {
            Response::error('Invalid user session.', 401);
            return;
        }

        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
            return;
        }

        $name = trim($data['name']);
        $email = strtolower(trim($data['email']));
        $currentPassword = (string)($data['current_password'] ?? '');
        $newPassword = (string)($data['new_password'] ?? '');

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, organization_id, name, email, password_hash, role, can_manage_structure FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User account not found.', 404);
            return;
        }

        $isEmailChanging = (strtolower(trim($user['email'])) !== $email);
        $isPasswordChanging = !empty(trim($newPassword));

        // Enforce current password verification if modifying email OR password
        if ($isEmailChanging || $isPasswordChanging) {
            if (empty($currentPassword)) {
                Response::error('Current password is required to change your email or password.', 422);
                return;
            }

            if (!password_verify($currentPassword, $user['password_hash'])) {
                Response::error('Incorrect current password. Please verify and try again.', 422);
                return;
            }
        }

        // Verify email uniqueness if email is changing
        if ($isEmailChanging) {
            $stmtEmail = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
            $stmtEmail->execute([':email' => $email, ':id' => $userId]);
            if ($stmtEmail->fetch()) {
                Response::error('This email address is already in use by another account.', 409);
                return;
            }
        }

        // Validate password complexity if new password is provided
        $passwordHash = null;
        if ($isPasswordChanging) {
            if (strlen($newPassword) < 8) {
                Response::error('New password must be at least 8 characters.', 422);
                return;
            }
            if (!preg_match('/[0-9]/', $newPassword)) {
                Response::error('New password must contain at least 1 number.', 422);
                return;
            }
            if (!preg_match('/[a-z]/', $newPassword)) {
                Response::error('New password must contain at least 1 lowercase letter.', 422);
                return;
            }
            if (!preg_match('/[A-Z]/', $newPassword)) {
                Response::error('New password must contain at least 1 uppercase letter.', 422);
                return;
            }
            if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
                Response::error('New password must contain at least 1 special character.', 422);
                return;
            }

            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        // Update database
        if ($passwordHash !== null) {
            $stmtUp = $db->prepare("
                UPDATE users 
                SET name = :name, email = :email, password_hash = :hash, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUp->execute([
                ':name' => $name,
                ':email' => $email,
                ':hash' => $passwordHash,
                ':id' => $userId
            ]);
        } else {
            $stmtUp = $db->prepare("
                UPDATE users 
                SET name = :name, email = :email, updated_at = NOW() 
                WHERE id = :id
            ");
            $stmtUp->execute([
                ':name' => $name,
                ':email' => $email,
                ':id' => $userId
            ]);
        }

        // If email changed, issue a fresh JWT access token
        $newAccessToken = null;
        if ($isEmailChanging) {
            $accessTokenPayload = [
                'user_id' => (int)$user['id'],
                'organization_id' => $user['organization_id'] ? (int)$user['organization_id'] : null,
                'role' => $user['role'],
                'email' => $email,
                'type' => 'access'
            ];
            $ttl = ($user['role'] === 'superadmin') ? 86400 : 7200;
            $newAccessToken = Jwt::encode($accessTokenPayload, $ttl);
            $GLOBALS['auth_user'] = $accessTokenPayload;
        }

        AuditLogger::log('user_profile_updated', 'user', $userId, [
            'name' => $name,
            'email_changed' => $isEmailChanging,
            'password_changed' => $isPasswordChanging
        ]);

        Response::success([
            'user' => [
                'id' => $userId,
                'name' => $name,
                'email' => $email,
                'role' => $user['role']
            ],
            'access_token' => $newAccessToken
        ], 'Profile updated successfully.');
    }
}
