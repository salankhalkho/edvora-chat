<?php

namespace App\Services;

use App\Config\Database;
use Throwable;

class LlmUsageLogger
{
    /**
     * Log an LLM usage event and calculate cost based on token counts and provider rates.
     *
     * @param array $data
     */
    public static function log(array $data): void
    {
        try {
            $db = Database::getConnection();

            $providerId = !empty($data['llm_provider_id']) ? (int)$data['llm_provider_id'] : null;
            $inputRate = isset($data['cost_per_1m_input_tokens']) ? (float)$data['cost_per_1m_input_tokens'] : null;
            $outputRate = isset($data['cost_per_1m_output_tokens']) ? (float)$data['cost_per_1m_output_tokens'] : null;

            // If rates not explicitly provided, attempt to look them up from providerId or provider/model
            if ($inputRate === null || $outputRate === null) {
                if ($providerId) {
                    $stmtP = $db->prepare("SELECT cost_per_1m_input_tokens, cost_per_1m_output_tokens FROM llm_providers WHERE id = :id LIMIT 1");
                    $stmtP->execute([':id' => $providerId]);
                    $pRow = $stmtP->fetch();
                    if ($pRow) {
                        $inputRate = (float)($pRow['cost_per_1m_input_tokens'] ?? 0);
                        $outputRate = (float)($pRow['cost_per_1m_output_tokens'] ?? 0);
                    }
                } elseif (!empty($data['model_name'])) {
                    $stmtP = $db->prepare("SELECT id, cost_per_1m_input_tokens, cost_per_1m_output_tokens FROM llm_providers WHERE model_name = :model LIMIT 1");
                    $stmtP->execute([':model' => $data['model_name']]);
                    $pRow = $stmtP->fetch();
                    if ($pRow) {
                        $providerId = $providerId ?: (int)$pRow['id'];
                        $inputRate = (float)($pRow['cost_per_1m_input_tokens'] ?? 0);
                        $outputRate = (float)($pRow['cost_per_1m_output_tokens'] ?? 0);
                    }
                }
            }

            $inputRate = $inputRate ?? 0.0;
            $outputRate = $outputRate ?? 0.0;

            $promptTokens = max(0, (int)($data['prompt_tokens'] ?? 0));
            $completionTokens = max(0, (int)($data['completion_tokens'] ?? 0));
            $totalTokens = $promptTokens + $completionTokens;
            if ($totalTokens === 0 && isset($data['total_tokens'])) {
                $totalTokens = max(0, (int)$data['total_tokens']);
                if ($promptTokens === 0 && $completionTokens === 0) {
                    $promptTokens = $totalTokens; // e.g. for embeddings where only input tokens are reported
                }
            }

            // Calculate cost: (tokens * rate_per_1m) / 1,000,000
            $costPrompt = round(($promptTokens * $inputRate) / 1000000.0, 8);
            $costCompletion = round(($completionTokens * $outputRate) / 1000000.0, 8);
            $costTotal = round($costPrompt + $costCompletion, 8);

            $stmt = $db->prepare("
                INSERT INTO llm_usage_logs (
                    organization_id, chatbot_id, activity_type,
                    reference_type, reference_id, description,
                    llm_provider_id, provider, model_name,
                    prompt_tokens, completion_tokens, total_tokens,
                    cost_prompt, cost_completion, cost_total,
                    latency_ms, status, error_message, created_at
                ) VALUES (
                    :org_id, :bot_id, :activity_type,
                    :ref_type, :ref_id, :description,
                    :provider_id, :provider, :model_name,
                    :prompt_tokens, :completion_tokens, :total_tokens,
                    :cost_prompt, :cost_completion, :cost_total,
                    :latency_ms, :status, :error_message, NOW()
                )
            ");

            $stmt->execute([
                ':org_id' => !empty($data['organization_id']) ? (int)$data['organization_id'] : null,
                ':bot_id' => !empty($data['chatbot_id']) ? (int)$data['chatbot_id'] : null,
                ':activity_type' => $data['activity_type'] ?? 'other',
                ':ref_type' => !empty($data['reference_type']) ? substr($data['reference_type'], 0, 64) : null,
                ':ref_id' => !empty($data['reference_id']) ? (int)$data['reference_id'] : null,
                ':description' => !empty($data['description']) ? substr($data['description'], 0, 500) : null,
                ':provider_id' => $providerId,
                ':provider' => substr($data['provider'] ?? 'unknown', 0, 50),
                ':model_name' => substr($data['model_name'] ?? 'unknown', 0, 100),
                ':prompt_tokens' => $promptTokens,
                ':completion_tokens' => $completionTokens,
                ':total_tokens' => $totalTokens,
                ':cost_prompt' => $costPrompt,
                ':cost_completion' => $costCompletion,
                ':cost_total' => $costTotal,
                ':latency_ms' => isset($data['latency_ms']) ? (int)$data['latency_ms'] : null,
                ':status' => in_array($data['status'] ?? 'success', ['success', 'failed', 'fallback']) ? $data['status'] : 'success',
                ':error_message' => !empty($data['error_message']) ? $data['error_message'] : null,
            ]);
        } catch (Throwable $e) {
            // Fail silently so logging never halts business operations
            error_log('[LlmUsageLogger] Failed to log usage: ' . $e->getMessage());
        }
    }
}
