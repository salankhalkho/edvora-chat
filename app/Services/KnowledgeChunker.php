<?php

namespace App\Services;

/**
 * KnowledgeChunker
 *
 * Chunks raw or processed document text into self-contained, embedding-friendly chunks.
 * Supports sliding window sentence overlap to preserve semantic context across boundaries.
 */
class KnowledgeChunker
{
    /**
     * Chunk text into array of strings with sentence-level sliding window overlap.
     *
     * @param string $text         Input raw or processed text.
     * @param int    $maxChunkLen  Target maximum character length per chunk (default 400).
     * @param int    $overlap      Number of overlapping sentences to carry into next chunk (default 1).
     * @return string[]            Array of clean text chunks.
     */
    public static function chunkText(string $text, int $maxChunkLen = 400, int $overlap = 1): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // Split text into sentences using common punctuation delimiters
        $sentences = preg_split('/(?<=[.?!])\s+/u', $text);
        $sentences = array_values(array_filter(array_map('trim', $sentences), fn($s) => strlen($s) > 0));

        if (empty($sentences)) {
            return [$text];
        }

        $chunks       = [];
        $currentSent  = [];
        $currentLength = 0;

        foreach ($sentences as $sentence) {
            $sentLen = strlen($sentence);

            // If a single sentence exceeds maxChunkLen, push current buffer and add sentence alone
            if ($sentLen > $maxChunkLen) {
                if (!empty($currentSent)) {
                    $chunks[]    = implode(' ', $currentSent);
                    $currentSent = [];
                    $currentLength = 0;
                }
                $chunks[] = $sentence;
                continue;
            }

            // If adding this sentence exceeds max length, push current chunk and apply overlap
            if ($currentLength + $sentLen + 1 > $maxChunkLen && !empty($currentSent)) {
                $chunks[] = implode(' ', $currentSent);

                // Carry over $overlap sentences for context continuity
                $overlapSent   = array_slice($currentSent, -$overlap);
                $currentSent   = $overlapSent;
                $currentLength = strlen(implode(' ', $overlapSent));
            }

            $currentSent[]  = $sentence;
            $currentLength += $sentLen + 1;
        }

        if (!empty($currentSent)) {
            $chunkStr = implode(' ', $currentSent);
            // Avoid duplicate chunk if overlap created identical trailing chunk
            if (empty($chunks) || end($chunks) !== $chunkStr) {
                $chunks[] = $chunkStr;
            }
        }

        return $chunks;
    }
}
