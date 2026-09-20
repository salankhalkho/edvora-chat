<?php

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use Exception;

/**
 * EmbeddingService
 *
 * Generates vector embeddings using the OpenAI text-embedding-3-small model.
 * The API key is loaded from the llm_providers table (model_name = 'text-embedding-3-small')
 * and decrypted using the same AES-256-CBC scheme as LlmService.
 *
 * Output vector: 1536-dimensional float array.
 *
 * Usage:
 *   $vector = EmbeddingService::embed("MBA Program — Duration: 2 years.");
 *   $vectors = EmbeddingService::embedBatch(["chunk 1", "chunk 2"]);
 */
class EmbeddingService
{
    private const MODEL        = 'text-embedding-3-small';
    private const ENDPOINT     = 'https://api.openai.com/v1/embeddings';
    private const DIMENSIONS   = 1536;
    private const TIMEOUT      = 30;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Embed a single string. Returns a 1536-dimensional float array.
     *
     * @throws Exception on API failure or empty input.
     */
    public static function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new Exception('[EmbeddingService] Cannot embed empty text.');
        }

        $apiKey = self::resolveApiKey();
        return self::callApi($apiKey, [$text])[0];
    }

    /**
     * Embed a batch of strings in a single API call.
     * Returns an array of float arrays, in the same order as the input.
     * Empty or whitespace-only strings are skipped and return an empty array [].
     *
     * @param  string[] $texts
     * @return array[]           indexed same as $texts
     * @throws Exception on API failure.
     */
    public static function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $apiKey     = self::resolveApiKey();
        $indexMap   = [];   // maps sanitized-batch index → original index
        $batch      = [];

        foreach ($texts as $origIdx => $text) {
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $indexMap[] = $origIdx;
            $batch[]    = $text;
        }

        if (empty($batch)) {
            return array_fill(0, count($texts), []);
        }

        $vectors = self::callApi($apiKey, $batch);

        // Re-map vectors back to original indices
        $result = array_fill(0, count($texts), []);
        foreach ($indexMap as $batchIdx => $origIdx) {
            $result[$origIdx] = $vectors[$batchIdx] ?? [];
        }

        return $result;
    }

    /**
     * Compute cosine similarity between two equal-length float vectors.
     * Returns a float in [0, 1] (1 = identical direction).
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $dot  += $a[$i] * $b[$i];
            $magA += $a[$i] * $a[$i];
            $magB += $b[$i] * $b[$i];
        }

        $denom = sqrt($magA) * sqrt($magB);
        if ($denom == 0.0) {
            return 0.0;
        }

        return (float)($dot / $denom);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Call OpenAI /v1/embeddings and return vectors in input order.
     *
     * @param  string   $apiKey
     * @param  string[] $inputs   Non-empty array of non-empty strings.
     * @return array[]            Float vectors, indexed 0 … n-1.
     * @throws Exception
     */
    private static function callApi(string $apiKey, array $inputs): array
    {
        $payload = json_encode([
            'model' => self::MODEL,
            'input' => $inputs,
        ]);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr) {
            throw new Exception('[EmbeddingService] cURL error: ' . $curlErr);
        }

        $decoded = json_decode($response, true);

        if ($httpCode !== 200) {
            $errMsg = $decoded['error']['message'] ?? $response;
            throw new Exception("[EmbeddingService] OpenAI API error (HTTP {$httpCode}): {$errMsg}");
        }

        if (empty($decoded['data'])) {
            throw new Exception('[EmbeddingService] OpenAI returned no embedding data.');
        }

        // Sort by index to guarantee order (OpenAI sorts by index in batch responses)
        $data = $decoded['data'];
        usort($data, fn($a, $b) => $a['index'] <=> $b['index']);

        return array_map(fn($item) => $item['embedding'], $data);
    }

    /**
     * Resolve the OpenAI API key for text-embedding-3-small.
     *
     * Priority:
     *   1. llm_providers row where model_name = 'text-embedding-3-small' and is_active = 1
     *   2. OPENAI_API_KEY environment variable (fallback)
     *
     * @throws Exception if no valid key is found.
     */
    private static function resolveApiKey(): string
    {
        try {
            $db   = Database::getConnection();
            $stmt = $db->prepare("
                SELECT api_key_encrypted
                FROM llm_providers
                WHERE model_name = :model
                  AND is_active  = 1
                LIMIT 1
            ");
            $stmt->execute([':model' => self::MODEL]);
            $row = $stmt->fetch();

            if ($row && !empty($row['api_key_encrypted'])) {
                $key = self::decryptKey($row['api_key_encrypted']);
                if ($key !== '') {
                    return $key;
                }
            }
        } catch (\Throwable $e) {
            error_log('[EmbeddingService] DB key lookup failed: ' . $e->getMessage());
        }

        // Fallback: environment variable
        $envKey = Env::get('OPENAI_API_KEY', '');
        if ($envKey !== '') {
            return $envKey;
        }

        throw new Exception('[EmbeddingService] No valid API key found for text-embedding-3-small. '
            . 'Configure it in llm_providers or set OPENAI_API_KEY environment variable.');
    }

    /**
     * Decrypt AES-256-CBC encrypted API key (same scheme as LlmService::decryptKey).
     */
    private static function decryptKey(string $encryptedHex): string
    {
        $key       = Env::get('LLM_ENCRYPTION_KEY', 'EdvoraLLM_SecretEncryptionKey2026!');
        $data      = hex2bin($encryptedHex);
        $ivLen     = openssl_cipher_iv_length('AES-256-CBC');
        $iv        = substr($data, 0, $ivLen);
        $encrypted = substr($data, $ivLen);
        return openssl_decrypt($encrypted, 'AES-256-CBC', md5($key), 0, $iv) ?: '';
    }
}
