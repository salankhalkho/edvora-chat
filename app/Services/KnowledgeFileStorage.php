<?php

namespace App\Services;

use Exception;

class KnowledgeFileStorage
{
    /**
     * Get the base directory for knowledge files: storage/knowledge
     */
    public static function getBaseDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/knowledge';
    }

    /**
     * Get the directory for a specific tenant's knowledge files: storage/knowledge/{org_id}
     */
    public static function getOrgDirectory(int $orgId): string
    {
        return self::getBaseDirectory() . '/' . $orgId;
    }

    /**
     * Get the directory for storing original uploaded files: storage/knowledge/{org_id}/original
     */
    public static function getOriginalFilesDirectory(int $orgId): string
    {
        return self::getOrgDirectory($orgId) . '/original';
    }

    /**
     * Get the relative path for a source's text file (used in database column file_path)
     */
    public static function getRelativeTxtPath(int $orgId, int $sourceId): string
    {
        return "storage/knowledge/{$orgId}/source_{$sourceId}.txt";
    }

    /**
     * Get the absolute path for a source's text file
     */
    public static function getAbsoluteTxtPath(int $orgId, int $sourceId): string
    {
        return dirname(__DIR__, 2) . '/' . self::getRelativeTxtPath($orgId, $sourceId);
    }

    /**
     * Ensure the tenant directory exists with proper permissions
     */
    public static function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Normalize content to clean, well-formed UTF-8 text.
     * Strips BOM, converts line endings, and eliminates non-printable control characters
     * while preserving newlines, carriage returns, and tabs.
     */
    public static function normalizeUtf8Text(string $text): string
    {
        // 1. Strip UTF-8 Byte Order Mark (BOM) if present
        $bom = pack('H*', 'EFBBBF');
        $text = preg_replace("/^{$bom}/", '', $text);

        // 2. Convert to valid UTF-8 encoding if invalid sequences exist
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text, mb_detect_order(), true) ?: 'ISO-8859-1');
        }

        // 3. Normalize line endings to \n
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);

        // 4. Remove non-printable control characters except \t and \n
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return trim($text);
    }

    /**
     * Estimate token count using average English word & subword token ratio (~0.75 words per token)
     */
    public static function estimateTokenCount(string $text): int
    {
        $wordCount = str_word_count($text);
        if ($wordCount === 0) {
            return (int)ceil(mb_strlen($text) / 4);
        }
        return (int)ceil($wordCount * 1.33);
    }

    /**
     * Save clean text content to storage/knowledge/{org_id}/source_{source_id}.txt
     *
     * Returns an array with metadata:
     * [
     *   'file_path'       => 'storage/knowledge/{org_id}/source_{source_id}.txt',
     *   'file_size_bytes' => 12345,
     *   'checksum_sha256' => 'abc123...',
     *   'token_count'     => 520
     * ]
     */
    public static function saveText(int $orgId, int $sourceId, string $content): array
    {
        $cleanText = self::normalizeUtf8Text($content);
        $orgDir = self::getOrgDirectory($orgId);
        self::ensureDirectoryExists($orgDir);

        $absPath = self::getAbsoluteTxtPath($orgId, $sourceId);
        $tempPath = $absPath . '.tmp.' . uniqid('', true);

        if (file_put_contents($tempPath, $cleanText) === false) {
            throw new Exception("[KnowledgeFileStorage] Failed to write text file to {$tempPath}");
        }

        if (!rename($tempPath, $absPath)) {
            @unlink($tempPath);
            throw new Exception("[KnowledgeFileStorage] Failed to atomically replace file at {$absPath}");
        }

        @chmod($absPath, 0664);

        $fileSizeBytes = filesize($absPath) ?: strlen($cleanText);
        $checksumSha256 = hash('sha256', $cleanText);
        $tokenCount = self::estimateTokenCount($cleanText);

        return [
            'file_path'       => self::getRelativeTxtPath($orgId, $sourceId),
            'file_size_bytes' => $fileSizeBytes,
            'checksum_sha256' => $checksumSha256,
            'token_count'     => $tokenCount,
            'clean_text'      => $cleanText
        ];
    }

    /**
     * Load text content on-demand from storage/knowledge/{org_id}/source_{source_id}.txt
     */
    public static function loadText(int $orgId, int $sourceId): ?string
    {
        $absPath = self::getAbsoluteTxtPath($orgId, $sourceId);
        if (file_exists($absPath) && is_readable($absPath)) {
            $content = file_get_contents($absPath);
            return $content !== false ? $content : null;
        }
        return null;
    }

    /**
     * Delete the source text file (and any temporary files) from disk
     */
    public static function deleteText(int $orgId, int $sourceId): bool
    {
        $absPath = self::getAbsoluteTxtPath($orgId, $sourceId);
        if (file_exists($absPath)) {
            return @unlink($absPath);
        }
        return true;
    }
}
