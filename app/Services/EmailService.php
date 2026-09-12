<?php

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use Throwable;

class EmailService
{
    /**
     * Send email notification to college admissions team when a new lead is captured
     */
    public static function sendNewLeadNotification(string $counselorEmail, array $leadData, string $collegeName): bool
    {
        $subject = "New Student Lead Captured: " . $leadData['name'] . " — " . $collegeName;

        $body = "Hello Admissions Team,\n\n";
        $body .= "A new prospective student lead has just been captured by your AI Admissions Assistant on edvora.chat!\n\n";
        $body .= "--- STUDENT LEAD DETAILS ---\n";
        $body .= "Name: " . $leadData['name'] . "\n";
        $body .= "Email: " . ($leadData['email'] ?? 'N/A') . "\n";
        $body .= "Phone: " . ($leadData['phone'] ?? 'N/A') . "\n";
        $body .= "Lead Type: " . ucfirst($leadData['lead_type'] ?? 'general') . "\n";
        $body .= "Program Interest: " . ($leadData['program_interest'] ?? 'General Inquiry') . "\n";
        $body .= "Date Captured: " . date('Y-m-d H:i:s') . "\n\n";
        $body .= "Log in to your College Dashboard at https://edvora.chat/app to view the full conversation transcript and manage this lead.\n\n";
        $body .= "Best regards,\nedvora.chat Admissions Engine";

        return self::sendMail($counselorEmail, $subject, $body);
    }

    /**
     * Send requested asset (brochure, fee PDF, scholarship guide) directly to student email
     */
    public static function sendAssetDelivery(string $toEmail, array $asset, string $collegeName, string $studentName = ''): bool
    {
        $salutation = !empty($studentName) ? "Dear " . htmlspecialchars($studentName) . "," : "Hello,";
        $assetTitle = $asset['title'] ?? 'Official Information Guide';
        $downloadUrl = "https://edvora.chat/v1/assets/" . ($asset['id'] ?? 0) . "/download";

        $subject = "Your Requested " . $assetTitle . " — " . $collegeName;

        $body = "{$salutation}\n\n";
        $body .= "Thank you for exploring {$collegeName}!\n\n";
        $body .= "As requested from our AI Admissions Assistant, here is your copy of {$assetTitle}.\n\n";
        $body .= "📥 Download Document: {$downloadUrl}\n\n";
        if (!empty($asset['description'])) {
            $body .= "Overview: " . $asset['description'] . "\n\n";
        }
        $body .= "If you have any questions regarding admission criteria, scholarships, or upcoming batches, our admissions team is here to assist.\n\n";
        $body .= "Warm regards,\nAdmissions Office, {$collegeName}\nPowered by edvora.chat";

        return self::sendMail($toEmail, $subject, $body);
    }

    /**
     * Send campus tour confirmation and admin alert
     */
    public static function sendCampusTourNotification(string $adminEmail, array $tourData, string $collegeName): bool
    {
        $subject = "New Campus Tour Booking: " . ($tourData['student_name'] ?? 'Student') . " — " . $collegeName;

        $body = "Hello Admissions Team,\n\n";
        $body .= "A new campus tour has been scheduled via edvora.chat!\n\n";
        $body .= "--- TOUR BOOKING DETAILS ---\n";
        $body .= "Student Name: " . ($tourData['student_name'] ?? 'N/A') . "\n";
        $body .= "Email: " . ($tourData['student_email'] ?? 'N/A') . "\n";
        $body .= "Phone: " . ($tourData['student_phone'] ?? 'N/A') . "\n";
        $body .= "Preferred Date: " . ($tourData['preferred_date'] ?? 'Flexible / TBD') . "\n";
        $body .= "Preferred Time: " . ($tourData['preferred_time'] ?? 'Flexible') . "\n";
        $body .= "Program Interest: " . ($tourData['program_interest'] ?? 'General') . "\n";
        $body .= "Group Size: " . ($tourData['group_size'] ?? 1) . "\n\n";
        $body .= "Log in to your College Dashboard at https://edvora.chat/app/#campus-tours to confirm and assign a campus tour coordinator.\n\n";
        $body .= "Best regards,\nedvora.chat Tour Dispatcher";

        return self::sendMail($adminEmail, $subject, $body);
    }

    /**
     * Send post-tour visit feedback survey email to student
     */
    public static function sendCampusTourFeedbackSurvey(string $studentEmail, array $tourData, string $collegeName): bool
    {
        $studentName = $tourData['student_name'] ?? 'Student';
        $subject = "How was your campus visit to {$collegeName}? We'd love your feedback!";

        $body = "Dear {$studentName},\n\n";
        $body .= "Thank you for visiting {$collegeName} for your campus tour!\n\n";
        $body .= "We hope you had a great experience discovering our campus, academic facilities, and student life.\n\n";
        $body .= "To help us continuously enhance the campus tour experience for future students, please take a moment to share your quick thoughts and suggestions.\n\n";
        $body .= "If you have any remaining questions about the admission process, scholarships, or next steps, feel free to reply directly to this email or chat with our admissions team at https://edvora.chat.\n\n";
        $body .= "Warm regards,\nCampus Visit & Admissions Team\n{$collegeName}";

        return self::sendMail($studentEmail, $subject, $body);
    }

    /**
     * Send personalized student delivery / confirmation email for demo lead capture
     */
    public static function sendDemoStudentDelivery(
        string $studentEmail,
        string $studentName,
        string $requestType,
        string $programName,
        string $institutionName,
        string $domain
    ): bool {
        $studentName = !empty($studentName) ? htmlspecialchars($studentName) : 'Prospective Student';
        $programName = !empty($programName) ? htmlspecialchars($programName) : 'Academic Program';
        $institutionName = !empty($institutionName) ? htmlspecialchars($institutionName) : 'the University';
        $domain = htmlspecialchars($domain);

        $titles = [
            'prospectus'  => ['subject' => "Your Requested Program Prospectus: {$programName} — {$institutionName}", 'heading' => "Your Program Prospectus is Ready", 'badge' => 'Official Prospectus & Curriculum Guide'],
            'tour'        => ['subject' => "Campus Tour Reservation Request — {$institutionName}", 'heading' => "Campus Tour Request Received", 'badge' => 'Campus Tour Booking Confirmation'],
            'scholarship' => ['subject' => "Scholarship & Aid Evaluation Request — {$institutionName}", 'heading' => "Scholarship Evaluation Received", 'badge' => 'Financial Aid & Scholarship Assessment'],
            'counselor'   => ['subject' => "Admissions Advisor Callback Requested — {$institutionName}", 'heading' => "Admissions Advisor Session Confirmed", 'badge' => 'Admissions Advisory Connection']
        ];
        $meta = $titles[$requestType] ?? $titles['counselor'];

        $refId = 'EDV-' . strtoupper(substr(md5($studentEmail . microtime()), 0, 8));

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$meta['subject']}</title>
</head>
<body style="margin: 0; padding: 24px 10px; background-color: #F4F7F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.55;">
  <div style="max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #E2ECE7; overflow: hidden; box-shadow: 0 4px 20px rgba(6, 61, 59, 0.05);">
    
    <!-- Top Header -->
    <div style="background: #063D3B; padding: 28px 28px 24px 28px; text-align: left;">
      <div style="display: inline-block; font-size: 11px; font-weight: 700; color: #063D3B; background: #C8FF63; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 12px;">
        {$meta['badge']}
      </div>
      <h1 style="color: #ffffff; font-size: 22px; font-weight: 800; margin: 0 0 6px 0; letter-spacing: -0.02em;">
        {$meta['heading']}
      </h1>
      <p style="color: #A3D4BE; font-size: 13px; margin: 0;">
        {$institutionName} &bull; Admissions Concierge
      </p>
    </div>

    <!-- Main Content Body -->
    <div style="padding: 28px;">
      <p style="font-size: 15px; color: #063D3B; font-weight: 600; margin-top: 0;">
        Dear {$studentName},
      </p>
      <p style="font-size: 14px; color: #475569; margin-bottom: 20px;">
        Thank you for your interest in <strong>{$institutionName}</strong>! As requested through our interactive admissions assistant, here is your requested information overview.
      </p>

      <!-- Program Highlight Card -->
      <div style="background: #F8FBF9; border: 1.5px solid #DCE8E2; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px;">
        <div style="font-size: 11px; font-weight: 700; color: #7E9490; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px;">Selected Program of Interest</div>
        <div style="font-size: 17px; font-weight: 800; color: #063D3B; margin-bottom: 12px;">🎓 {$programName}</div>
        
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
          <tr>
            <td style="color: #64748b; padding: 4px 0;">Institution:</td>
            <td style="color: #063D3B; font-weight: 600; text-align: right; padding: 4px 0;">{$institutionName}</td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 4px 0;">Website:</td>
            <td style="color: #063D3B; font-weight: 600; text-align: right; padding: 4px 0;"><a href="https://{$domain}" style="color: #047857; text-decoration: none;">{$domain}</a></td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 4px 0;">Reference ID:</td>
            <td style="color: #063D3B; font-family: monospace; font-weight: 600; text-align: right; padding: 4px 0;">{$refId}</td>
          </tr>
        </table>
      </div>

      <!-- What to Expect Next -->
      <div style="background: #E8F5EE; border-left: 4px solid #047857; border-radius: 6px; padding: 14px 16px; margin-bottom: 24px;">
        <div style="font-size: 13px; font-weight: 700; color: #047857; margin-bottom: 4px;">What Happens Next?</div>
        <div style="font-size: 13px; color: #2D5A46; line-height: 1.5;">
          An admissions advisor specializing in <strong>{$programName}</strong> will review your inquiry. You'll receive comprehensive curriculum guides, prerequisite checklists, and tuition breakdown shortly.
        </div>
      </div>

      <div style="text-align: center; margin: 28px 0 10px 0;">
        <a href="https://{$domain}" style="display: inline-block; background: #063D3B; color: #ffffff; font-size: 14px; font-weight: 700; padding: 12px 26px; border-radius: 8px; text-decoration: none;">
          Explore {$institutionName} Website &rarr;
        </a>
      </div>
    </div>

    <!-- Footer -->
    <div style="background: #F8FBF9; border-top: 1px solid #E2ECE7; padding: 18px 28px; text-align: center; font-size: 12px; color: #7E9490;">
      Sent automatically via the <strong>edvora.chat</strong> AI Admissions Engine.<br>
      &copy; 2026 {$institutionName} &bull; All rights reserved.
    </div>
  </div>
</body>
</html>
HTML;

        return self::sendMail($studentEmail, $meta['subject'], $html, true);
    }

    /**
     * Send immediate lead capture alert to university administration work email
     */
    public static function sendDemoLeadNotificationToAdmin(
        string $adminEmail,
        array $leadData,
        string $institutionName,
        array $conversationLog = []
    ): bool {
        $studentName = htmlspecialchars($leadData['name'] ?? 'Prospective Student');
        $studentContact = htmlspecialchars($leadData['contact'] ?? 'N/A');
        $programName = htmlspecialchars($leadData['program_interest'] ?? 'General Inquiry');
        $opportunityType = htmlspecialchars($leadData['opportunity'] ?? 'Admissions Inquiry');
        $institutionName = htmlspecialchars($institutionName);
        $dateStr = date('Y-m-d H:i:s T');

        $rawContact = $leadData['contact'] ?? '';
        $digitsOnly = preg_replace('/[^0-9]/', '', $rawContact);
        $isPhoneOrWa = (strlen($digitsOnly) >= 7 && !str_contains($rawContact, '@'));
        $waLink = '';
        $waButtonHtml = '';
        $contactDisplay = $studentContact;

        if ($isPhoneOrWa) {
            $prefilledMsg = "Hi {$studentName}, this is the admissions team from {$institutionName}. We received your inquiry regarding {$programName} on our website — how can we assist you today?";
            $waLink = "https://wa.me/{$digitsOnly}?text=" . urlencode($prefilledMsg);
            $contactDisplay = "<a href=\"{$waLink}\" target=\"_blank\" style=\"color: #047857; text-decoration: underline; font-weight: 700;\">{$studentContact}</a> <span style=\"font-size: 11px; font-weight: 700; color: #ffffff; background: #25D366; padding: 2px 7px; border-radius: 10px; margin-left: 4px;\">WhatsApp</span>";
            $waButtonHtml = <<<WA_BTN
            <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #DCE8E2; text-align: left;">
              <a href="{$waLink}" target="_blank" style="display: inline-block; background: #25D366; color: #ffffff; font-size: 13.5px; font-weight: 700; padding: 11px 22px; border-radius: 8px; text-decoration: none; box-shadow: 0 2px 8px rgba(37, 211, 102, 0.3);">
                💬 Chat with {$studentName} on WhatsApp &rarr;
              </a>
              <span style="font-size: 11.5px; color: #64748b; margin-left: 10px; display: inline-block; margin-top: 6px;">1-click direct admissions connect</span>
            </div>
WA_BTN;
        }

        $subject = "🔔 [New Lead Captured] {$studentName} requested {$opportunityType} for {$programName}";

        // Format recent conversation transcript (last 6 messages)
        $transcriptHtml = '';
        if (!empty($conversationLog)) {
            $recent = array_slice($conversationLog, -6);
            foreach ($recent as $msg) {
                $role = ($msg['role'] ?? '') === 'assistant' ? 'Edvora AI' : $studentName;
                $isAssistant = ($msg['role'] ?? '') === 'assistant';
                $bg = $isAssistant ? '#F4FAF7' : '#EFF6FF';
                $border = $isAssistant ? '#A3D4BE' : '#BFDBFE';
                $color = $isAssistant ? '#063D3B' : '#1E3A8A';
                $content = nl2br(htmlspecialchars($msg['content'] ?? ''));

                $transcriptHtml .= <<<ROW
                <div style="margin-bottom: 10px; padding: 10px 14px; background: {$bg}; border: 1px solid {$border}; border-radius: 8px;">
                  <div style="font-size: 11px; font-weight: 700; color: {$color}; margin-bottom: 4px;">{$role}:</div>
                  <div style="font-size: 13px; color: #334155; line-height: 1.45;">{$content}</div>
                </div>
ROW;
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$subject}</title>
</head>
<body style="margin: 0; padding: 24px 10px; background-color: #F4F7F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.55;">
  <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; border: 1px solid #E2ECE7; overflow: hidden; box-shadow: 0 4px 20px rgba(6, 61, 59, 0.05);">
    
    <!-- Header -->
    <div style="background: #063D3B; padding: 26px 28px; text-align: left;">
      <div style="display: inline-block; font-size: 11px; font-weight: 700; color: #063D3B; background: #C8FF63; padding: 3px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 10px;">
        Instant Lead Captured &bull; 24/7 AI Engine
      </div>
      <h1 style="color: #ffffff; font-size: 20px; font-weight: 800; margin: 0 0 4px 0;">
        New Prospective Student Lead
      </h1>
      <p style="color: #A3D4BE; font-size: 13px; margin: 0;">
        {$institutionName} &bull; edvora.chat Admissions Concierge
      </p>
    </div>

    <!-- Lead Info Grid -->
    <div style="padding: 24px 28px;">
      <div style="background: #F8FBF9; border: 1.5px solid #DCE8E2; border-radius: 12px; padding: 18px 20px; margin-bottom: 22px;">
        <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
          <tr>
            <td style="color: #64748b; padding: 6px 0; width: 140px;">Student Name:</td>
            <td style="color: #063D3B; font-weight: 700; padding: 6px 0;">{$studentName}</td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 6px 0;">Contact Details:</td>
            <td style="padding: 6px 0;">{$contactDisplay}</td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 6px 0;">Program Interest:</td>
            <td style="color: #063D3B; font-weight: 700; padding: 6px 0;">🎓 {$programName}</td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 6px 0;">Opportunity Type:</td>
            <td style="color: #063D3B; font-weight: 700; padding: 6px 0;">📋 {$opportunityType}</td>
          </tr>
          <tr>
            <td style="color: #64748b; padding: 6px 0;">Timestamp:</td>
            <td style="color: #64748b; padding: 6px 0;">{$dateStr}</td>
          </tr>
        </table>
        {$waButtonHtml}
      </div>

      <!-- Transcript Section -->
      <div style="margin-bottom: 24px;">
        <div style="font-size: 13px; font-weight: 700; color: #063D3B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 12px;">
          💬 Recent Conversation Transcript
        </div>
        {$transcriptHtml}
      </div>

      <!-- CTA -->
      <div style="text-align: center; margin: 24px 0 10px 0;">
        <a href="https://edvora.chat/register" style="display: inline-block; background: #C8FF63; color: #063D3B; font-size: 14px; font-weight: 800; padding: 13px 28px; border-radius: 8px; text-decoration: none; border: 1.5px solid #b3ec48;">
          Deploy Edvora on Your Campus &rarr;
        </a>
      </div>
    </div>

    <!-- Footer -->
    <div style="background: #F8FBF9; border-top: 1px solid #E2ECE7; padding: 16px 28px; text-align: center; font-size: 12px; color: #7E9490;">
      This email is an automated demonstration from <strong>edvora.chat</strong>.<br>
      You are receiving this because your work email was entered into the preview environment.
    </div>
  </div>
</body>
</html>
HTML;

        return self::sendMail($adminEmail, $subject, $html, true);
    }

    /**
     * Send a live test email and return diagnostic logs for Super Admin
     */
    public static function sendTestMail(string $to, ?array $overrideConfig = null): array
    {
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'Please provide a valid recipient email address.',
                'transcript' => ['[ERROR] Invalid recipient email address: ' . $to]
            ];
        }

        $config = $overrideConfig ?? self::getSmtpConfig();
        if (empty($config) || empty($config['host'])) {
            return [
                'success' => false,
                'message' => 'SMTP Host is not configured.',
                'transcript' => ['[ERROR] SMTP Host is missing. Please provide a valid SMTP server host.']
            ];
        }

        $subject = "edvora.chat SMTP Test Transmission — " . date('Y-m-d H:i:s');
        $fromEmail = !empty($config['from_email']) ? $config['from_email'] : 'no-reply@edvora.chat';
        $fromName = !empty($config['from_name']) ? $config['from_name'] : 'edvora.chat System';
        $replyTo = !empty($config['reply_to']) ? $config['reply_to'] : '';

        $body = "Hello,\n\n"
              . "This is an automated test message from the edvora.chat Super Admin Control Center.\n\n"
              . "If you are reading this email, your SMTP server settings are correctly configured and live email dispatch is fully operational!\n\n"
              . "--- TEST TRANSMISSION METRICS ---\n"
              . "SMTP Host: " . $config['host'] . "\n"
              . "SMTP Port: " . ($config['port'] ?? 587) . "\n"
              . "Encryption: " . strtoupper($config['encryption'] ?? 'TLS') . "\n"
              . "From Address: {$fromName} <{$fromEmail}>\n"
              . "Dispatched At: " . date('r') . "\n\n"
              . "Best regards,\nedvora.chat Platform Engineering";

        $password = $config['password'] ?? '';
        // If password was passed encrypted and not already decrypted:
        if (empty($password) && !empty($config['password_encrypted'])) {
            $password = self::decryptKey($config['password_encrypted']);
        }

        try {
            $client = new SmtpClient(
                $config['host'],
                (int)($config['port'] ?? 587),
                $config['encryption'] ?? 'tls',
                $config['username'] ?? '',
                $password,
                15
            );

            $sent = $client->send($to, $subject, $body, $fromEmail, $fromName, $replyTo, false);
            return [
                'success' => $sent,
                'message' => 'Test email transmitted successfully to ' . $to,
                'transcript' => $client->getTranscript()
            ];
        } catch (Throwable $e) {
            $transcript = isset($client) ? $client->getTranscript() : [];
            $transcript[] = "[EXCEPTION] " . $e->getMessage();
            return [
                'success' => false,
                'message' => 'SMTP Test Delivery Failed: ' . $e->getMessage(),
                'transcript' => $transcript
            ];
        }
    }

    /**
     * Send transactional email using configured SMTP socket client with graceful fallback
     */
    public static function sendMail(
        string $to,
        string $subject,
        string $body,
        bool $isHtml = false,
        string $customReplyTo = ''
    ): bool {
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $config = self::getSmtpConfig();

        // 1. If SMTP is configured and active, deliver via SmtpClient
        if (!empty($config) && !empty($config['is_active']) && !empty($config['host'])) {
            try {
                $password = !empty($config['password']) ? $config['password'] : self::decryptKey($config['password_encrypted'] ?? '');
                $fromEmail = !empty($config['from_email']) ? $config['from_email'] : 'no-reply@edvora.chat';
                $fromName = !empty($config['from_name']) ? $config['from_name'] : 'edvora.chat Admissions Alert';
                $replyTo = !empty($customReplyTo) ? $customReplyTo : ($config['reply_to'] ?? '');

                $client = new SmtpClient(
                    $config['host'],
                    (int)($config['port'] ?? 587),
                    $config['encryption'] ?? 'tls',
                    $config['username'] ?? '',
                    $password,
                    12
                );

                return $client->send($to, $subject, $body, $fromEmail, $fromName, $replyTo, $isHtml);
            } catch (Throwable $e) {
                error_log("[EmailService] SMTP delivery failed to {$to}: " . $e->getMessage() . ". Attempting fallback mail()...");
            }
        }

        // 2. Fallback: PHP native mail()
        $fromEmail = !empty($config['from_email']) ? $config['from_email'] : 'no-reply@edvora.chat';
        $fromName = !empty($config['from_name']) ? $config['from_name'] : 'edvora.chat Admissions Alert';

        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Reply-To: ' . (!empty($customReplyTo) ? $customReplyTo : $fromEmail),
            'X-Mailer: PHP/' . PHP_VERSION,
            'Content-Type: ' . ($isHtml ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8')
        ];

        try {
            return @mail($to, $subject, $body, implode("\r\n", $headers));
        } catch (Throwable $e) {
            error_log("[EmailService] Fallback mail() failed to send email to {$to}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieve SMTP configuration from platform_config
     */
    public static function getSmtpConfig(): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT value_text FROM platform_config WHERE key_name = 'smtp_settings' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch();

            if ($row && !empty($row['value_text'])) {
                $decoded = json_decode($row['value_text'], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (Throwable $e) {
            error_log("[EmailService] Error fetching smtp_settings: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Decrypt AES-256-CBC encrypted credential string
     */
    public static function decryptKey(string $hex): string
    {
        if (empty($hex)) {
            return '';
        }
        $raw = @hex2bin($hex);
        if ($raw === false) {
            return '';
        }
        $secret = Env::get('LLM_ENCRYPTION_KEY', 'EdvoraLLM_SecretEncryptionKey2026!');
        $ivLen = openssl_cipher_iv_length('AES-256-CBC');
        if (strlen($raw) <= $ivLen) {
            return '';
        }
        $iv = substr($raw, 0, $ivLen);
        $ciphertext = substr($raw, $ivLen);
        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', md5($secret), 0, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Encrypt credential string with AES-256-CBC
     */
    public static function encryptKey(string $key): string
    {
        if (empty($key)) {
            return '';
        }
        $secret = Env::get('LLM_ENCRYPTION_KEY', 'EdvoraLLM_SecretEncryptionKey2026!');
        $ivLen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = openssl_random_pseudo_bytes($ivLen);
        $encrypted = openssl_encrypt($key, 'AES-256-CBC', md5($secret), 0, $iv);
        return bin2hex($iv . $encrypted);
    }
}
