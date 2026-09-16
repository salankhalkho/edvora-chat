<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;

class WidgetCustomizationController
{
    // Platform-level default configuration
    private static array $defaults = [
        'window_bg_color'      => '#ffffff',
        'window_border_color'  => 'rgba(0,0,0,0.08)',
        'window_border_width'  => 1,
        'window_border_radius' => 16,
        'window_width'         => 380,
        'window_height'        => 550,
        'window_shadow'        => 'soft',
        'header_bg'            => '#063D3B',
        'header_text_color'    => '#ffffff',
        'header_logo_url'      => null,
        'header_bot_name'      => null,
        'header_subtitle'      => 'Online Now',
        'welcome_message'      => null,
        'avatar_type'          => 'preset',
        'avatar_preset'        => 1,
        'avatar_url'           => null,
        'avatar_location'      => 'bubbles',
        'bot_bubble_bg'        => '#f1f5f9',
        'bot_bubble_text'      => '#1e293b',
        'bot_bubble_radius'    => 14,
        'user_bubble_bg'       => '#063D3B',
        'user_bubble_text'     => '#ffffff',
        'user_bubble_radius'   => 14,
        'message_area_bg'      => '#f9fafb',
        'message_font_size'    => 13,
        'input_bg'             => '#ffffff',
        'input_border_color'   => '#e2e8f0',
        'input_text_color'     => '#1e293b',
        'input_placeholder'    => 'Ask me anything...',
        'input_border_radius'  => 24,
        'send_btn_bg'          => '#063D3B',
        'send_btn_icon_color'  => '#ffffff',
        'launcher_style'       => 'circle',
        'launcher_bg'          => '#063D3B',
        'launcher_icon_color'  => '#ffffff',
        'launcher_icon'        => 'modern_chat',
        'launcher_text'        => 'Ask AI',
        'launcher_position'    => 'bottom-right',
        'launcher_size'        => 60,
        'show_branding'        => true,
        'chip_bg'              => 'transparent',
        'chip_border_color'    => '#063D3B',
        'chip_text_color'      => '#063D3B',
        'chip_border_radius'   => 20,
        'theme'                => 'light',
        'show_action_brochure' => true,
        'show_action_callback' => true,
        'show_action_tour'     => true,
        'quick_chips'          => '',
    ];

    /**
     * GET /v1/widget/customization?bot_id=X&scope=org|dept&dept_id=Y
     * Loads config for a given bot+scope. Falls back through cascade.
     */
    public function get(Request $request, array $params = []): void
    {
        $orgId   = $GLOBALS['organization_id'] ?? null;
        $botId   = (int)($request->get('bot_id') ?? 0);

        if (!$botId) {
            Response::error('bot_id is required.', 400);
        }

        $db = Database::getConnection();

        // Verify bot belongs to org
        $stmtBot = $db->prepare("SELECT id FROM chatbots WHERE id = :id AND organization_id = :org");
        $stmtBot->execute([':id' => $botId, ':org' => $orgId]);
        if (!$stmtBot->fetch()) {
            Response::error('Chatbot not found.', 404);
        }

        $config = $this->loadConfig($db, $botId, $orgId);

        // Fetch authoritative prerequisite status for lead capture action cards
        $hasAssets = (int)$db->query("SELECT COUNT(*) FROM lead_assets WHERE organization_id = " . (int)$orgId . " AND is_active = 1")->fetchColumn() > 0;
        $hasCampuses = (int)$db->query("SELECT COUNT(*) FROM campuses WHERE organization_id = " . (int)$orgId . " AND status = 'active'")->fetchColumn() > 0;
        $hasStaff = (int)$db->query("SELECT COUNT(*) FROM users WHERE organization_id = " . (int)$orgId . " AND role IN ('staff', 'counselor', 'agent', 'admin', 'org_admin')")->fetchColumn() > 0;
        
        $stmtBotLead = $db->prepare("SELECT lead_capture_enabled FROM chatbots WHERE id = :id");
        $stmtBotLead->execute([':id' => $botId]);
        $botLeadRow = $stmtBotLead->fetch(\PDO::FETCH_ASSOC);
        $leadCaptureEnabled = (bool)($botLeadRow['lead_capture_enabled'] ?? false);
        $hasCallbacks = $leadCaptureEnabled && $hasStaff;

        Response::success([
            'scope'   => 'org',
            'dept_id' => null,
            'config'  => $config,
            'is_override' => false,
            'prerequisites' => [
                'has_assets' => $hasAssets,
                'has_campuses' => $hasCampuses,
                'has_callbacks' => $hasCallbacks,
                'lead_capture_enabled' => $leadCaptureEnabled,
                'has_counselor_contact' => $hasStaff
            ]
        ]);
    }

    /**
     * PUT /v1/widget/customization
     * Upserts config for a given bot+scope.
     * Body: { bot_id, scope, dept_id, config: {...} }
     */
    public function upsert(Request $request, array $params = []): void
    {
        $orgId  = $GLOBALS['organization_id'] ?? null;
        $data   = $request->all();
        $botId  = (int)($data['bot_id'] ?? 0);
        $config = $data['config'] ?? [];

        if (!$botId) {
            Response::error('bot_id is required.', 400);
        }

        if (!is_array($config)) {
            Response::error('config must be an object.', 422);
        }

        $db = Database::getConnection();

        // Verify bot belongs to org
        $stmtBot = $db->prepare("SELECT id FROM chatbots WHERE id = :id AND organization_id = :org");
        $stmtBot->execute([':id' => $botId, ':org' => $orgId]);
        if (!$stmtBot->fetch()) {
            Response::error('Chatbot not found.', 404);
        }

        // Sanitize & merge with defaults
        $sanitized = $this->sanitizeConfig($config);
        $configJson = json_encode($sanitized, JSON_UNESCAPED_UNICODE);

        // Find existing record
        $checkStmt = $db->prepare("SELECT id FROM widget_customizations WHERE chatbot_id = :bot AND organization_id = :org ORDER BY id DESC LIMIT 1");
        $checkStmt->execute([':bot' => $botId, ':org' => $orgId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $db->prepare("UPDATE widget_customizations SET config = :cfg, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':cfg' => $configJson, ':id' => $existing['id']]);
            // Clean up any extraneous duplicate rows
            $delStmt = $db->prepare("DELETE FROM widget_customizations WHERE chatbot_id = :bot AND organization_id = :org AND id != :id");
            $delStmt->execute([':bot' => $botId, ':org' => $orgId, ':id' => $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO widget_customizations (chatbot_id, organization_id, config) VALUES (:bot, :org, :cfg)");
            $stmt->execute([
                ':bot'  => $botId,
                ':org'  => $orgId,
                ':cfg'  => $configJson,
            ]);
        }

        // Keep chatbots.quick_chips column synchronized with org-level prompt chips
        if ($scope === 'org' && !empty($sanitized['quick_chips'])) {
            $chipsArr = array_filter(array_map('trim', explode(',', $sanitized['quick_chips'])));
            $db->prepare("UPDATE chatbots SET quick_chips = :qc, updated_at = NOW() WHERE id = :bid AND organization_id = :oid")
               ->execute([':qc' => json_encode(array_values($chipsArr)), ':bid' => $botId, ':oid' => $orgId]);
        }

        Response::success([
            'saved' => true,
            'config' => $sanitized,
        ], 'Widget customization saved successfully.');
    }

    /**
     * POST /v1/widget/avatar/upload
     * Handles avatar (bot profile) or logo (header) image upload.
     * Optimizes to WebP via GD library.
     * Query param: ?type=avatar|logo
     */
    public function uploadImage(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $type  = in_array($request->get('type'), ['avatar', 'logo']) ? $request->get('type') : 'avatar';

        if (empty($_FILES['image'])) {
            Response::error('No image file uploaded.', 400);
        }

        $file = $_FILES['image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('Upload failed with error code: ' . $file['error'], 400);
        }

        // Max 2MB input
        if ($file['size'] > 2 * 1024 * 1024) {
            Response::error('Image must be under 2MB.', 422);
        }

        // Check MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];

        if (!in_array($mimeType, $allowedMimes)) {
            Response::error('Only JPG, PNG, GIF, WebP, or SVG images are allowed.', 422);
        }

        // SVG: save as-is (no resize needed)
        if ($mimeType === 'image/svg+xml') {
            $ext = 'svg';
            $uploadDir = "/var/www/edvora.chat/storage/uploads/avatars/{$orgId}/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }
            $filename = $type . '_' . uniqid() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
            $url = "/v1/uploads/avatars/{$orgId}/{$filename}";
            Response::success(['url' => $url]);
            return;
        }

        // Load image via GD
        $srcImage = null;
        switch ($mimeType) {
            case 'image/jpeg': $srcImage = imagecreatefromjpeg($file['tmp_name']); break;
            case 'image/png':  $srcImage = imagecreatefrompng($file['tmp_name']); break;
            case 'image/gif':  $srcImage = imagecreatefromgif($file['tmp_name']); break;
            case 'image/webp': $srcImage = imagecreatefromwebp($file['tmp_name']); break;
        }

        if (!$srcImage) {
            Response::error('Could not process image. Please use a valid JPG, PNG, or WebP.', 422);
        }

        // Target dimensions
        if ($type === 'logo') {
            $maxW = 400; $maxH = 80;
        } else {
            $maxW = 256; $maxH = 256;
        }

        $srcW = imagesx($srcImage);
        $srcH = imagesy($srcImage);

        // Calculate proportional resize
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1.0);
        $newW  = (int)round($srcW * $ratio);
        $newH  = (int)round($srcH * $ratio);

        // Create output canvas with transparency support
        $destImage = imagecreatetruecolor($newW, $newH);
        imagealphablending($destImage, false);
        imagesavealpha($destImage, true);
        $transparent = imagecolorallocatealpha($destImage, 0, 0, 0, 127);
        imagefill($destImage, 0, 0, $transparent);

        imagecopyresampled($destImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        imagedestroy($srcImage);

        // Save as WebP
        $uploadDir = "/var/www/edvora.chat/storage/uploads/avatars/{$orgId}/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $filename = $type . '_' . uniqid() . '.webp';
        $savePath = $uploadDir . $filename;
        $saved = imagewebp($destImage, $savePath, 90);
        imagedestroy($destImage);

        if (!$saved || !file_exists($savePath)) {
            Response::error('Failed to save uploaded image. Please check server permissions.', 500);
        }

        $url = "/v1/uploads/avatars/{$orgId}/{$filename}";
        Response::success(['url' => $url]);
    }

    /**
     * GET /v1/uploads/avatars/{org_id}/{filename}
     * Serves uploaded avatar/logo files.
     */
    public function serveImage(Request $request, array $params = []): void
    {
        $orgId    = (int)($params['org_id'] ?? 0);
        $filename = basename($params['filename'] ?? '');

        if (!$orgId || !$filename) {
            Response::error('Invalid request.', 400);
        }

        $filePath = "/var/www/edvora.chat/storage/uploads/avatars/{$orgId}/{$filename}";

        if (!file_exists($filePath)) {
            $defaultLogo = "/var/www/edvora.chat/public/default_logo.png";
            if (file_exists($defaultLogo)) {
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=86400');
                header('Content-Length: ' . filesize($defaultLogo));
                readfile($defaultLogo);
                exit;
            }
            Response::error('File not found.', 404);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeMap = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Public static helper — used by ChatController::widgetConfig to apply cascade
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Org-level customization lookup: org -> defaults.
     * Returns merged config array.
     */
    public static function cascadeLookup(PDO $db, int $botId, int $orgId): array
    {
        $defaults = self::$defaults;
        $stmtOrgInfo = $db->prepare("SELECT name, primary_color FROM organizations WHERE id = :org");
        $stmtOrgInfo->execute([':org' => $orgId]);
        $orgRowInfo = $stmtOrgInfo->fetch();
        if ($orgRowInfo) {
            if (!empty($orgRowInfo['name'])) {
                $defaults['header_bot_name'] = $orgRowInfo['name'];
            }
            if (!empty($orgRowInfo['primary_color'])) {
                $pColor = $orgRowInfo['primary_color'];
                $defaults['header_bg'] = $pColor;
                $defaults['user_bubble_bg'] = $pColor;
                $defaults['send_btn_bg'] = $pColor;
                $defaults['launcher_bg'] = $pColor;
                $defaults['chip_border_color'] = $pColor;
                $defaults['chip_text_color'] = $pColor;
            }
        }

        // Fetch org-level config
        $orgCfg = [];
        $stmtOrg = $db->prepare("SELECT config FROM widget_customizations WHERE chatbot_id = :b AND organization_id = :o ORDER BY id DESC LIMIT 1");
        $stmtOrg->execute([':b' => $botId, ':o' => $orgId]);
        $rowOrg = $stmtOrg->fetch();
        if ($rowOrg) {
            $parsedOrg = json_decode($rowOrg['config'], true);
            if (is_array($parsedOrg)) {
                $orgCfg = $parsedOrg;
            }
        }

        return array_merge($defaults, $orgCfg);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────

    private function loadConfig(PDO $db, int $botId, int $orgId): array
    {
        $defaults = self::$defaults;

        // Fetch organization name & primary_color to use as default header title/bot name and theme
        $stmtOrg = $db->prepare("SELECT name, primary_color FROM organizations WHERE id = :org");
        $stmtOrg->execute([':org' => $orgId]);
        $orgRow = $stmtOrg->fetch();
        if ($orgRow) {
            if (!empty($orgRow['name'])) {
                $defaults['header_bot_name'] = $orgRow['name'];
            }
            if (!empty($orgRow['primary_color'])) {
                $pColor = $orgRow['primary_color'];
                $defaults['header_bg'] = $pColor;
                $defaults['user_bubble_bg'] = $pColor;
                $defaults['send_btn_bg'] = $pColor;
                $defaults['launcher_bg'] = $pColor;
                $defaults['chip_border_color'] = $pColor;
                $defaults['chip_text_color'] = $pColor;
            }
        }

        // Fetch org-level config
        $orgCfg = [];
        $stmtOrgCfg = $db->prepare("SELECT config FROM widget_customizations WHERE chatbot_id = :b AND organization_id = :o ORDER BY id DESC LIMIT 1");
        $stmtOrgCfg->execute([':b' => $botId, ':o' => $orgId]);
        $rowOrgCfg = $stmtOrgCfg->fetch();
        if ($rowOrgCfg) {
            $parsedOrg = json_decode($rowOrgCfg['config'], true);
            if (is_array($parsedOrg)) {
                $orgCfg = $parsedOrg;
            }
        }

        return array_merge($defaults, $orgCfg);
    }

    private function sanitizeConfig(array $config): array
    {
        $d = self::$defaults;

        return [
            'window_bg_color'      => $this->sanitizeColor($config['window_bg_color'] ?? $d['window_bg_color']),
            'window_border_color'  => $this->sanitizeColor($config['window_border_color'] ?? $d['window_border_color']),
            'window_border_width'  => max(0, min(8, (int)($config['window_border_width'] ?? $d['window_border_width']))),
            'window_border_radius' => max(0, min(32, (int)($config['window_border_radius'] ?? $d['window_border_radius']))),
            'window_width'         => max(300, min(650, (int)($config['window_width'] ?? $d['window_width']))),
            'window_height'        => max(350, min(800, (int)($config['window_height'] ?? $d['window_height']))),
            'window_shadow'        => in_array($config['window_shadow'] ?? '', ['none','soft','medium','deep']) ? $config['window_shadow'] : $d['window_shadow'],
            'header_bg'            => $this->sanitizeColor($config['header_bg'] ?? $d['header_bg']),
            'header_text_color'    => $this->sanitizeColor($config['header_text_color'] ?? $d['header_text_color']),
            'header_logo_url'      => $this->sanitizeUrl($config['header_logo_url'] ?? null),
            'header_bot_name'      => substr(strip_tags($config['header_bot_name'] ?? $d['header_bot_name']), 0, 80),
            'header_subtitle'      => substr(strip_tags($config['header_subtitle'] ?? $d['header_subtitle']), 0, 80),
            'welcome_message'      => substr(strip_tags($config['welcome_message'] ?? $d['welcome_message']), 0, 1000),
            'avatar_type'          => in_array($config['avatar_type'] ?? '', ['preset','upload']) ? $config['avatar_type'] : $d['avatar_type'],
            'avatar_preset'        => max(1, min(11, (int)($config['avatar_preset'] ?? $d['avatar_preset']))),
            'avatar_url'           => $this->sanitizeUrl($config['avatar_url'] ?? null),
            'avatar_location'      => in_array($config['avatar_location'] ?? '', ['bubbles','header','both']) ? $config['avatar_location'] : $d['avatar_location'],
            'bot_bubble_bg'        => $this->sanitizeColor($config['bot_bubble_bg'] ?? $d['bot_bubble_bg']),
            'bot_bubble_text'      => $this->sanitizeColor($config['bot_bubble_text'] ?? $d['bot_bubble_text']),
            'bot_bubble_radius'    => max(0, min(28, (int)($config['bot_bubble_radius'] ?? $d['bot_bubble_radius']))),
            'user_bubble_bg'       => $this->sanitizeColor($config['user_bubble_bg'] ?? $d['user_bubble_bg']),
            'user_bubble_text'     => $this->sanitizeColor($config['user_bubble_text'] ?? $d['user_bubble_text']),
            'user_bubble_radius'   => max(0, min(28, (int)($config['user_bubble_radius'] ?? $d['user_bubble_radius']))),
            'message_area_bg'      => $this->sanitizeColor($config['message_area_bg'] ?? $d['message_area_bg']),
            'message_font_size'    => max(11, min(18, (int)($config['message_font_size'] ?? $d['message_font_size']))),
            'input_bg'             => $this->sanitizeColor($config['input_bg'] ?? $d['input_bg']),
            'input_border_color'   => $this->sanitizeColor($config['input_border_color'] ?? $d['input_border_color']),
            'input_text_color'     => $this->sanitizeColor($config['input_text_color'] ?? $d['input_text_color']),
            'input_placeholder'    => substr(strip_tags($config['input_placeholder'] ?? $d['input_placeholder']), 0, 100),
            'input_border_radius'  => max(0, min(32, (int)($config['input_border_radius'] ?? $d['input_border_radius']))),
            'send_btn_bg'          => $this->sanitizeColor($config['send_btn_bg'] ?? $d['send_btn_bg']),
            'send_btn_icon_color'  => $this->sanitizeColor($config['send_btn_icon_color'] ?? $d['send_btn_icon_color']),
            'launcher_style'       => in_array($config['launcher_style'] ?? '', ['circle','pill','square']) ? $config['launcher_style'] : $d['launcher_style'],
            'launcher_bg'          => $this->sanitizeColor($config['launcher_bg'] ?? $d['launcher_bg']),
            'launcher_icon_color'  => $this->sanitizeColor($config['launcher_icon_color'] ?? $d['launcher_icon_color']),
            'launcher_icon'        => in_array($config['launcher_icon'] ?? '', ['chat','modern_chat','robot','sparkles','headset','star']) ? $config['launcher_icon'] : $d['launcher_icon'],
            'launcher_text'        => substr(strip_tags($config['launcher_text'] ?? $d['launcher_text']), 0, 40),
            'launcher_position'    => in_array($config['launcher_position'] ?? '', ['bottom-right','bottom-left','top-right','top-left']) ? $config['launcher_position'] : $d['launcher_position'],
            'launcher_size'        => max(44, min(80, (int)($config['launcher_size'] ?? $d['launcher_size']))),
            'show_branding'        => (bool)($config['show_branding'] ?? $d['show_branding']),
            'chip_bg'              => $this->sanitizeColor($config['chip_bg'] ?? $d['chip_bg']),
            'chip_border_color'    => $this->sanitizeColor($config['chip_border_color'] ?? $d['chip_border_color']),
            'chip_text_color'      => $this->sanitizeColor($config['chip_text_color'] ?? $d['chip_text_color']),
            'chip_border_radius'   => max(0, min(28, (int)($config['chip_border_radius'] ?? $d['chip_border_radius']))),
            'theme'                => in_array($config['theme'] ?? '', ['light','dark','auto']) ? $config['theme'] : $d['theme'],
            'show_action_brochure' => isset($config['show_action_brochure']) ? (bool)$config['show_action_brochure'] : true,
            'show_action_callback' => isset($config['show_action_callback']) ? (bool)$config['show_action_callback'] : true,
            'show_action_tour'     => isset($config['show_action_tour']) ? (bool)$config['show_action_tour'] : true,
            'quick_chips'          => isset($config['quick_chips'])
                ? (is_array($config['quick_chips'])
                    ? implode(', ', array_filter(array_map('trim', $config['quick_chips'])))
                    : substr(strip_tags((string)$config['quick_chips']), 0, 500))
                : '',
        ];
    }

    private function sanitizeColor(string $val): string
    {
        $val = trim($val);
        // Allow hex, rgb(), rgba(), hsl(), named 'transparent'
        if (preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|hsla?\([^)]+\)|transparent)$/', $val)) {
            return $val;
        }
        return '#6366f1';
    }

    private function sanitizeUrl(?string $val): ?string
    {
        if (!$val) return null;
        $val = trim($val);
        if (str_starts_with($val, '/v1/uploads/avatars/')) return $val;
        return null;
    }
}
