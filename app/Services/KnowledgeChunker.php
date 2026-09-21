<?php

namespace App\Services;

/**
 * KnowledgeChunker
 *
 * Hierarchical recursive structural chunker designed for academic documents, syllabi,
 * course structures, fee tables, and general knowledge sources.
 *
 * It splits recursively across natural semantic boundaries:
 * 1. Double newlines (paragraphs, sections, semester headings)
 * 2. Single newlines (bulleted courses, syllabus modules, table rows)
 * 3. Sentence boundaries (.?! followed by space)
 * 4. Word boundaries (spaces) if a single sentence exceeds the target length.
 *
 * Enforces maxChunkLen strictly while preserving sliding-window overlap for context continuity.
 */
class KnowledgeChunker
{
    /**
     * Chunk text into array of clean strings with recursive structural boundaries and overlap.
     *
     * @param string $text        Input raw or processed text.
     * @param int    $maxChunkLen Target maximum character length per chunk (default 500).
     * @param int    $overlap     Number of trailing segments to carry into the next chunk (default 1).
     * @return string[]           Array of clean text chunks.
     */
    public static function chunkText(string $text, int $maxChunkLen = 500, int $overlap = 1): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // 1. Normalize carriage returns and invisible unicode spaces
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);

        // 2. Break text recursively down to atomic segments that do not exceed $maxChunkLen
        $atoms = self::splitRecursively($text, $maxChunkLen, [
            "/\n\n+/",                   // Priority 1: Double newlines (paragraphs, major sections)
            "/\n/",                      // Priority 2: Single newlines (lists, course rows, syllabus items)
            "/(?<=[.?!])\s+/u",          // Priority 3: Sentences ending with punctuation
            "/\s+/"                      // Priority 4: Words/whitespace fallback for runaway strings
        ]);

        if (empty($atoms)) {
            return [$text];
        }

        // 3. Assemble atomic segments into chunks <= $maxChunkLen with sliding overlap
        $chunks        = [];
        $currentBuffer = [];
        $currentLength = 0;

        foreach ($atoms as $atom) {
            $atom = trim($atom);
            if ($atom === '') {
                continue;
            }

            $atomLen = mb_strlen($atom, 'UTF-8');

            // If a single atom alone exceeds maxChunkLen (rare after recursive word split), force push
            if ($atomLen >= $maxChunkLen) {
                if (!empty($currentBuffer)) {
                    $chunks[] = self::joinSegments($currentBuffer);
                    $currentBuffer = [];
                    $currentLength = 0;
                }
                $chunks[] = $atom;
                continue;
            }

            // Check if adding this atom exceeds maxChunkLen
            $separatorLen = !empty($currentBuffer) ? 1 : 0;
            if ($currentLength + $separatorLen + $atomLen > $maxChunkLen && !empty($currentBuffer)) {
                $chunks[] = self::joinSegments($currentBuffer);

                // Sliding overlap: carry over $overlap items
                $overlapItems  = array_slice($currentBuffer, -$overlap);
                $currentBuffer = $overlapItems;
                $currentLength = mb_strlen(self::joinSegments($overlapItems), 'UTF-8');
            }

            $currentBuffer[] = $atom;
            $currentLength   += (!empty($currentBuffer) && count($currentBuffer) > 1 ? 1 : 0) + $atomLen;
        }

        if (!empty($currentBuffer)) {
            $lastChunk = self::joinSegments($currentBuffer);
            if (empty($chunks) || end($chunks) !== $lastChunk) {
                $chunks[] = $lastChunk;
            }
        }

        return array_values(array_filter(array_map('trim', $chunks), fn($c) => $c !== ''));
    }

    /**
     * Recursively split a block of text using delimiter patterns until every piece is <= $maxLen.
     *
     * @param string   $text
     * @param int      $maxLen
     * @param string[] $delimiters Array of regex patterns in order of priority.
     * @return string[]
     */
    private static function splitRecursively(string $text, int $maxLen, array $delimiters): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // If text already fits within maxLen, return as a single atomic segment
        if (mb_strlen($text, 'UTF-8') <= $maxLen) {
            return [$text];
        }

        // If no delimiters left, hard split by character limit
        if (empty($delimiters)) {
            return mb_str_split($text, $maxLen, 'UTF-8');
        }

        $pattern = array_shift($delimiters);
        $parts   = preg_split($pattern, $text);
        $parts   = array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== ''));

        // If the pattern did not produce multiple parts, try next delimiter in hierarchy
        if (count($parts) <= 1) {
            return self::splitRecursively($text, $maxLen, $delimiters);
        }

        $result = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') <= $maxLen) {
                $result[] = $part;
            } else {
                // Recursively split the oversized part using the remaining delimiters
                $subParts = self::splitRecursively($part, $maxLen, $delimiters);
                foreach ($subParts as $sub) {
                    $result[] = $sub;
                }
            }
        }

        return $result;
    }

    /**
     * Join segment atoms cleanly with natural spacing / newlines.
     *
     * @param string[] $segments
     * @return string
     */
    private static function joinSegments(array $segments): string
    {
        return implode("\n", $segments);
    }
}
