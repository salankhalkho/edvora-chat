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
    public static function complete(string $systemPrompt, string $userMessage, array $conversationHistory = [], array $context = []): array
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
                $result = self::executeProvider($primary, $systemPrompt, $userMessage, $conversationHistory, $context);
            } catch (Exception $e) {
                error_log("[LlmService] Primary LLM Provider failed: " . $e->getMessage() . ". Switching to fallback...");
            }
        }

        // Attempt Fallback Provider
        if (!$result && $fallback) {
            try {
                $fallbackContext = $context;
                $fallbackContext['is_fallback'] = true;
                $result = self::executeProvider($fallback, $systemPrompt, $userMessage, $conversationHistory, $fallbackContext);
            } catch (Exception $e) {
                error_log("[LlmService] Fallback LLM Provider failed: " . $e->getMessage());
            }
        }

        // Fallback to Environment Variables (OpenAI / Gemini) if DB providers fail or not set
        if (!$result) {
            $result = self::executeEnvFallback($systemPrompt, $userMessage, $conversationHistory, $context);
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
    /**
     * Test a provider directly for health check / latency validation
     */
    public static function testProviderDirect(array $providerConfig): array
    {
        return self::executeProvider($providerConfig, "You are a ping test assistant.", "Reply with: PONG", [], [
            'activity_type' => 'system_test',
            'description' => 'Superadmin LLM Provider health ping test'
        ]);
    }

    /**
     * Dispatch completion call to provider API
     */
    private static function executeProvider(array $providerConfig, string $systemPrompt, string $userMessage, array $history, array $context = []): array
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

        $startTime = microtime(true);

        try {
            switch ($providerType) {
                case 'openai':
                case 'groq':
                    $res = self::callOpenAiCompatible($providerConfig['api_base_url'] ?? ($providerType === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1'), $apiKey, $model, $systemPrompt, $userMessage, $history, $temperature, $maxTokens, $timeout);
                    break;
                case 'gemini':
                    $res = self::callGemini($apiKey, $model, $systemPrompt, $userMessage, $history, $temperature, $maxTokens, $timeout);
                    break;
                default:
                    throw new Exception("Unsupported provider: {$providerType}");
            }

            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            $promptTokens = (int)($res['prompt_tokens'] ?? 0);
            $completionTokens = (int)($res['completion_tokens'] ?? 0);
            $totalTokens = (int)($res['tokens_used'] ?? ($promptTokens + $completionTokens));

            LlmUsageLogger::log([
                'organization_id' => $context['organization_id'] ?? null,
                'chatbot_id' => $context['chatbot_id'] ?? null,
                'activity_type' => $context['activity_type'] ?? 'chat_completion',
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'description' => $context['description'] ?? 'Chat completion turn',
                'llm_provider_id' => $providerConfig['id'] ?? null,
                'provider' => $providerConfig['provider'] ?? 'unknown',
                'model_name' => $res['model'] ?? $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'cost_per_1m_input_tokens' => $providerConfig['cost_per_1m_input_tokens'] ?? null,
                'cost_per_1m_output_tokens' => $providerConfig['cost_per_1m_output_tokens'] ?? null,
                'latency_ms' => $latencyMs,
                'status' => !empty($context['is_fallback']) ? 'fallback' : 'success',
            ]);

            return $res;
        } catch (Exception $e) {
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            LlmUsageLogger::log([
                'organization_id' => $context['organization_id'] ?? null,
                'chatbot_id' => $context['chatbot_id'] ?? null,
                'activity_type' => $context['activity_type'] ?? 'chat_completion',
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'description' => $context['description'] ?? 'Chat completion turn',
                'llm_provider_id' => $providerConfig['id'] ?? null,
                'provider' => $providerConfig['provider'] ?? 'unknown',
                'model_name' => $model,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'latency_ms' => $latencyMs,
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            throw $e;
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
        $promptTokens = (int)($json['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int)($json['usage']['completion_tokens'] ?? 0);
        $tokens = (int)($json['usage']['total_tokens'] ?? ($promptTokens + $completionTokens));

        return [
            'text' => trim($content),
            'tokens_used' => $tokens,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
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
        $promptTokens = (int)($json['usageMetadata']['promptTokenCount'] ?? 0);
        $completionTokens = (int)($json['usageMetadata']['candidatesTokenCount'] ?? 0);
        $tokens = (int)($json['usageMetadata']['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        return [
            'text' => trim($text),
            'tokens_used' => $tokens,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'model' => $model
        ];
    }

    /**
     * Fallback to environment variables if no DB provider exists
     */
    private static function executeEnvFallback(string $systemPrompt, string $userMessage, array $history, array $context = []): array
    {
        $openAiKey = Env::get('OPENAI_API_KEY');
        if (!empty($openAiKey)) {
            $startTime = microtime(true);
            $res = self::callOpenAiCompatible('https://api.openai.com/v1', $openAiKey, 'gpt-4o-mini', $systemPrompt, $userMessage, $history, 0.3, 400, 20);
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            LlmUsageLogger::log([
                'organization_id' => $context['organization_id'] ?? null,
                'chatbot_id' => $context['chatbot_id'] ?? null,
                'activity_type' => $context['activity_type'] ?? 'chat_completion',
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'description' => $context['description'] ?? 'Chat completion turn (env fallback)',
                'provider' => 'openai',
                'model_name' => 'gpt-4o-mini',
                'prompt_tokens' => (int)($res['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($res['completion_tokens'] ?? 0),
                'total_tokens' => (int)($res['tokens_used'] ?? 0),
                'latency_ms' => $latencyMs,
                'status' => 'success',
            ]);
            return $res;
        }

        $geminiKey = Env::get('GEMINI_API_KEY');
        if (!empty($geminiKey)) {
            $startTime = microtime(true);
            $res = self::callGemini($geminiKey, 'gemini-1.5-flash', $systemPrompt, $userMessage, $history, 0.3, 400, 20);
            $latencyMs = (int)round((microtime(true) - $startTime) * 1000);
            LlmUsageLogger::log([
                'organization_id' => $context['organization_id'] ?? null,
                'chatbot_id' => $context['chatbot_id'] ?? null,
                'activity_type' => $context['activity_type'] ?? 'chat_completion',
                'reference_type' => $context['reference_type'] ?? null,
                'reference_id' => $context['reference_id'] ?? null,
                'description' => $context['description'] ?? 'Chat completion turn (gemini env fallback)',
                'provider' => 'gemini',
                'model_name' => 'gemini-1.5-flash',
                'prompt_tokens' => (int)($res['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($res['completion_tokens'] ?? 0),
                'total_tokens' => (int)($res['tokens_used'] ?? 0),
                'latency_ms' => $latencyMs,
                'status' => 'success',
            ]);
            return $res;
        }

        // Default neutral greeting if no external API keys configured yet
        return [
            'text' => "Hello! I am your AI Admissions Assistant. How can I help you with courses, admissions, or campus information today?",
            'tokens_used' => 20,
            'model' => 'system_fallback'
        ];
    }

    /**
     * Generate text embedding using Super Admin managed LLM Provider with role 'embedding' (or env fallbacks)
     */
    public static function embed(string $text): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM llm_providers
            WHERE is_active = 1 AND role = 'embedding'
            LIMIT 1
        ");
        $stmt->execute();
        $provider = $stmt->fetch();

        if ($provider) {
            $apiKey = !empty($provider['api_key_encrypted']) ? self::decryptKey($provider['api_key_encrypted']) : '';
            $providerType = strtolower($provider['provider']);
            $model = $provider['model_name'];
            $baseUrl = !empty($provider['api_base_url']) ? rtrim($provider['api_base_url'], '/') : null;
            $timeout = (int)($provider['timeout_seconds'] ?? 30);

            if ($providerType === 'openai') {
                return self::callOpenAiEmbedding($baseUrl ?: 'https://api.openai.com/v1', $apiKey, $model ?: 'text-embedding-3-small', $text, $timeout);
            } elseif ($providerType === 'gemini') {
                return self::callGeminiEmbedding($apiKey, $model ?: 'text-embedding-004', $text, $timeout);
            }
        }

        // Fallback to Env keys if no DB provider configured
        $openAiKey = Env::get('OPENAI_API_KEY');
        if (!empty($openAiKey)) {
            return self::callOpenAiEmbedding('https://api.openai.com/v1', $openAiKey, 'text-embedding-3-small', $text, 30);
        }

        $geminiKey = Env::get('GEMINI_API_KEY');
        if (!empty($geminiKey)) {
            return self::callGeminiEmbedding($geminiKey, 'text-embedding-004', $text, 30);
        }

        throw new Exception("No embedding LLM provider configured in database or environment variables.");
    }

    /**
     * Call OpenAI Embedding API
     */
    private static function callOpenAiEmbedding(string $baseUrl, string $apiKey, string $model, string $text, int $timeout): array
    {
        $url = $baseUrl . '/embeddings';
        $payload = [
            'input' => $text,
            'model' => $model
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            throw new Exception("OpenAI Embedding API HTTP {$code}: {$err} Response: {$res}");
        }

        $json = json_decode($res, true);
        $embedding = $json['data'][0]['embedding'] ?? [];
        $tokens = $json['usage']['total_tokens'] ?? 0;

        return [
            'embedding' => $embedding,
            'tokens_used' => $tokens,
            'model' => $model
        ];
    }

    /**
     * Call Gemini Embedding API
     */
    private static function callGeminiEmbedding(string $apiKey, string $model, string $text, int $timeout): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key={$apiKey}";
        $payload = [
            'model' => "models/{$model}",
            'content' => [
                'parts' => [['text' => $text]]
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
            throw new Exception("Gemini Embedding API HTTP {$code}: {$res}");
        }

        $json = json_decode($res, true);
        $embedding = $json['embedding']['values'] ?? [];

        return [
            'embedding' => $embedding,
            'tokens_used' => 0,
            'model' => $model
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
