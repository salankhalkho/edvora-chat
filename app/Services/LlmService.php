<?php

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use Exception;

class LlmService
{
    /**
     * Complete prompt using Super Admin managed LLM Providers (with primary -> fallback failover)
     */
    public static function complete(string $systemPrompt, string $userMessage, array $conversationHistory = []): array
    {
        $db = Database::getConnection();

        // 1. Fetch active primary provider
        $stmtPrimary = $db->prepare("
            SELECT * FROM llm_providers
            WHERE is_active = 1 AND role = 'primary'
            LIMIT 1
        ");
        $stmtPrimary->execute();
        $primary = $stmtPrimary->fetch();

        // 2. Fetch active fallback provider
        $stmtFallback = $db->prepare("
            SELECT * FROM llm_providers
            WHERE is_active = 1 AND role = 'fallback'
            LIMIT 1
        ");
        $stmtFallback->execute();
        $fallback = $stmtFallback->fetch();

        $result = null;

        // Attempt Primary Provider
        if ($primary) {
            try {
                $result = self::executeProvider($primary, $systemPrompt, $userMessage, $conversationHistory);
            } catch (Exception $e) {
                error_log("[LlmService] Primary LLM Provider failed: " . $e->getMessage() . ". Switching to fallback...");
            }
        }

        // Attempt Fallback Provider
        if (!$result && $fallback) {
            try {
                $result = self::executeProvider($fallback, $systemPrompt, $userMessage, $conversationHistory);
            } catch (Exception $e) {
                error_log("[LlmService] Fallback LLM Provider failed: " . $e->getMessage());
            }
        }

        // Fallback to Environment Variables (OpenAI / Gemini) if DB providers fail or not set
        if (!$result) {
            $result = self::executeEnvFallback($systemPrompt, $userMessage, $conversationHistory);
        }

        // Record entry in recent logs JSON (strictly maximum 1 entry)
        try {
            self::recordDebugLog($systemPrompt, $userMessage, $conversationHistory, $result);
        } catch (Throwable $t) {
            error_log("[LlmService] Debug log recording error: " . $t->getMessage());
        }

        return $result;
    }

    /**
     * Record interaction to storage/logs/llm_debug_logs.json (max 1 entry, FIFO)
     */
    public static function recordDebugLog(string $systemPrompt, string $userMessage, array $conversationHistory, array $result): void
    {
        $logDir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $logFile = $logDir . '/llm_debug_logs.json';

        // Prepare conversation history snippet (prior dialogue turns before current message)
        $historySnippet = [];
        $totalMsgs = count($conversationHistory);
        foreach ($conversationHistory as $idx => $msg) {
            // Exclude current user message from history snippet if it's the last item
            if ($idx === ($totalMsgs - 1) && ($msg['role'] ?? '') === 'user' && ($msg['content'] ?? '') === $userMessage) {
                continue;
            }
            if (isset($msg['role'], $msg['content'])) {
                $historySnippet[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content']
                ];
            }
        }

        $entry = [
            'id' => 'req_' . bin2hex(random_bytes(4)),
            'timestamp' => date('Y-m-d H:i:s'),
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userMessage,
            'conversation_history' => $historySnippet,
            'llm_response' => $result['text'] ?? '',
            'model' => $result['model'] ?? 'unknown',
            'tokens_used' => $result['tokens_used'] ?? 0
        ];

        $logs = [];
        if (file_exists($logFile)) {
            $raw = @file_get_contents($logFile);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $logs = $decoded;
                }
            }
        }

        // Prepend newest entry to the top
        array_unshift($logs, $entry);

        // Keep maximum 1 entry (latest only)
        if (count($logs) > 1) {
            $logs = array_slice($logs, 0, 1);
        }

        @file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Test a provider directly for health check / latency validation
     */
    public static function testProviderDirect(array $providerConfig): array
    {
        return self::executeProvider($providerConfig, "You are a ping test assistant.", "Reply with: PONG", []);
    }

    /**
     * Dispatch completion call to provider API
     */
    private static function executeProvider(array $providerConfig, string $systemPrompt, string $userMessage, array $history): array
    {
        $providerType = strtolower($providerConfig['provider']);
        $model = $providerConfig['model_name'];
        $apiKey = !empty($providerConfig['api_key_encrypted']) ? self::decryptKey($providerConfig['api_key_encrypted']) : Env::get(strtoupper($providerType) . '_API_KEY');
        $temperature = (float)($providerConfig['temperature'] ?? 0.30);
        $maxTokens = min(400, (int)($providerConfig['max_tokens'] ?? 400));
        $timeout = (int)($providerConfig['timeout_seconds'] ?? 20);

        if (empty($apiKey)) {
            throw new Exception("API Key missing for provider {$providerType}");
        }

        switch ($providerType) {
            case 'openai':
            case 'groq':
                return self::callOpenAiCompatible($providerConfig['api_base_url'] ?? ($providerType === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1'), $apiKey, $model, $systemPrompt, $userMessage, $history, $temperature, $maxTokens, $timeout);
            case 'gemini':
                return self::callGemini($apiKey, $model, $systemPrompt, $userMessage, $history, $temperature, $maxTokens, $timeout);
            default:
                throw new Exception("Unsupported provider: {$providerType}");
        }
    }

    /**
     * Call OpenAI / Groq Compatible Chat API
     */
    private static function callOpenAiCompatible(string $baseUrl, string $apiKey, string $model, string $systemPrompt, string $userMessage, array $history, float $temperature, int $maxTokens, int $timeout): array
    {
        $url = rtrim($baseUrl, '/') . '/chat/completions';

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($history as $msg) {
            if (in_array($msg['role'], ['user', 'assistant'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$apiKey}",
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            throw new Exception("OpenAI API HTTP {$code}: {$err} Response: {$res}");
        }

        $json = json_decode($res, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        $tokens = $json['usage']['total_tokens'] ?? 0;

        return [
            'text' => trim($content),
            'tokens_used' => $tokens,
            'model' => $model
        ];
    }

    /**
     * Call Google Gemini API
     */
    private static function callGemini(string $apiKey, string $model, string $systemPrompt, string $userMessage, array $history, float $temperature, int $maxTokens, int $timeout): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $contents = [];
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => "SYSTEM INSTRUCTIONS:\n" . $systemPrompt]]
        ];
        $contents[] = [
            'role' => 'model',
            'parts' => [['text' => "Understood. I will strictly follow these instructions."]]
        ];

        foreach ($history as $msg) {
            $role = $msg['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]]
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            throw new Exception("Gemini API HTTP {$code}: {$res}");
        }

        $json = json_decode($res, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $tokens = $json['usageMetadata']['totalTokenCount'] ?? 0;

        return [
            'text' => trim($text),
            'tokens_used' => $tokens,
            'model' => $model
        ];
    }

    /**
     * Fallback to environment variables if no DB provider exists
     */
    private static function executeEnvFallback(string $systemPrompt, string $userMessage, array $history): array
    {
        $openAiKey = Env::get('OPENAI_API_KEY');
        if (!empty($openAiKey)) {
            return self::callOpenAiCompatible('https://api.openai.com/v1', $openAiKey, 'gpt-4o-mini', $systemPrompt, $userMessage, $history, 0.3, 400, 20);
        }

        $geminiKey = Env::get('GEMINI_API_KEY');
        if (!empty($geminiKey)) {
            return self::callGemini($geminiKey, 'gemini-1.5-flash', $systemPrompt, $userMessage, $history, 0.3, 400, 20);
        }

        // Default neutral greeting if no external API keys configured yet
        return [
            'text' => "Hello! I am your AI Admissions Assistant. How can I help you with courses, admissions, or campus information today?",
            'tokens_used' => 20,
            'model' => 'system_fallback'
        ];
    }

    /**
     * Decrypt AES-256 encrypted API key
     */
    public static function decryptKey(string $encryptedHex): string
    {
        $key = Env::get('LLM_ENCRYPTION_KEY', 'EdvoraLLM_SecretEncryptionKey2026!');
        $data = hex2bin($encryptedHex);
        $ivLen = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        return openssl_decrypt($encrypted, 'AES-256-CBC', md5($key), 0, $iv) ?: '';
    }
}
