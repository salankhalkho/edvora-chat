<?php

namespace App\Controllers;

use App\Config\Database;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\AuditLogger;
use App\Helpers\Validator;
use PDO;

class ChatbotController
{
    /**
     * GET /v1/chatbots — List chatbots for organization
     */
    public function index(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT id, name, welcome_message, primary_color, secondary_color, bot_token, is_active, 
                   system_prompt_override, lead_capture_enabled, widget_style, theme_mode,
                   header_subtitle, launcher_icon, launcher_text, border_radius, avatar_icon, quick_chips,
                   created_at, updated_at
            FROM chatbots
            WHERE organization_id = :org_id
            ORDER BY id ASC
        ");
        $stmt->execute([':org_id' => $orgId]);
        $bots = $stmt->fetchAll();

        Response::success($bots);
    }

    /**
     * PUT /v1/chatbots/{id} — Update chatbot configuration
     */
    public function update(Request $request, array $params = []): void
    {
        $orgId = $GLOBALS['organization_id'] ?? null;
        $id = (int)($params['id'] ?? 0);
        $data = $request->all();

        $errors = Validator::validate($data, [
            'name' => 'required|max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation failed', 422, $errors);
        }

        $db = Database::getConnection();
        $stmtCheck = $db->prepare("SELECT id FROM chatbots WHERE id = :id AND organization_id = :org_id");
        $stmtCheck->execute([':id' => $id, ':org_id' => $orgId]);
        if (!$stmtCheck->fetch()) {
            Response::error('Chatbot not found.', 404);
        }

        $name = trim($data['name']);
        $welcomeMessage = $data['welcome_message'] ?? null;
        $primaryColor = $data['primary_color'] ?? '#6366F1';
        $secondaryColor = $data['secondary_color'] ?? '#38BDF8';
        $systemPromptOverride = $data['system_prompt_override'] ?? null;
        $leadCaptureEnabled = isset($data['lead_capture_enabled']) ? (int)(bool)$data['lead_capture_enabled'] : 1;

        $widgetStyle = $data['widget_style'] ?? 'glassmorphism';
        $themeMode = $data['theme_mode'] ?? 'dark';
        $headerSubtitle = $data['header_subtitle'] ?? 'Online • Replies instantly';
        $launcherIcon = $data['launcher_icon'] ?? 'chat';
        $launcherText = $data['launcher_text'] ?? 'Ask AI';
        $borderRadius = $data['border_radius'] ?? 'curved';
        $avatarIcon = $data['avatar_icon'] ?? '🤖';
        $quickChips = is_array($data['quick_chips'] ?? null) ? json_encode($data['quick_chips']) : ($data['quick_chips'] ?? null);

        $stmtUpdate = $db->prepare("
            UPDATE chatbots
            SET name = :name, welcome_message = :welcome, primary_color = :color, secondary_color = :sec_color,
                system_prompt_override = :override, lead_capture_enabled = :lead_toggle,
                widget_style = :widget_style, theme_mode = :theme_mode, header_subtitle = :header_subtitle,
                launcher_icon = :launcher_icon, launcher_text = :launcher_text, border_radius = :border_radius,
                avatar_icon = :avatar_icon, quick_chips = :quick_chips, updated_at = NOW()
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmtUpdate->execute([
            ':name' => $name,
            ':welcome' => $welcomeMessage,
            ':color' => $primaryColor,
            ':sec_color' => $secondaryColor,
            ':override' => $systemPromptOverride,
            ':lead_toggle' => $leadCaptureEnabled,
            ':widget_style' => $widgetStyle,
            ':theme_mode' => $themeMode,
            ':header_subtitle' => $headerSubtitle,
            ':launcher_icon' => $launcherIcon,
            ':launcher_text' => $launcherText,
            ':border_radius' => $borderRadius,
            ':avatar_icon' => $avatarIcon,
            ':quick_chips' => $quickChips,
            ':id' => $id,
            ':org_id' => $orgId
        ]);

        AuditLogger::log('chatbot_updated', 'chatbot', $id, ['name' => $name]);

        Response::success([
            'id' => $id,
            'name' => $name,
            'welcome_message' => $welcomeMessage,
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'lead_capture_enabled' => (bool)$leadCaptureEnabled,
            'widget_style' => $widgetStyle,
            'theme_mode' => $themeMode,
            'header_subtitle' => $headerSubtitle,
            'launcher_icon' => $launcherIcon,
            'launcher_text' => $launcherText,
            'border_radius' => $borderRadius,
            'avatar_icon' => $avatarIcon,
            'quick_chips' => $quickChips
        ], 'Chatbot configuration updated successfully');
    }
}
