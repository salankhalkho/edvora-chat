<?php

namespace App\Services;

class ContentCompactor
{
    /**
     * Compact raw content into high-density processed_content for LLM prompt context
     */
    public static function process(string $rawContent, string $title = ''): array
    {
        $lines = explode("\n", $rawContent);
        $compactLines = [];
        $uniqueLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines or trivial boilerplate (e.g. "Privacy Policy", "Cookie Settings", short links)
            if (empty($line) || strlen($line) < 3) {
                continue;
            }

            if (self::isBoilerplateLine($line)) {
                continue;
            }

            // Deduplicate exact repeating lines
            $hash = md5(strtolower($line));
            if (isset($uniqueLines[$hash])) {
                continue;
            }
            $uniqueLines[$hash] = true;

            $compactLines[] = $line;
        }

        $processedContent = implode("\n", $compactLines);

        // Extract algorithmic single-word keywords (frequency-ranked)
        $keywords = self::extractKeywords($rawContent . ' ' . $title);

        return [
            'processed_content' => $processedContent,
            'keywords'          => implode(', ', $keywords)
        ];
    }

    /**
     * Check if a line is common web/document boilerplate
     */
    private static function isBoilerplateLine(string $line): bool
    {
        $lineLower = strtolower($line);
        $boilerplatePhrases = [
            'all rights reserved',
            'privacy policy',
            'terms of service',
            'terms and conditions',
            'cookie policy',
            'click here to',
            'follow us on',
            'copyright ©',
            'sitemap',
            'top of page',
            'skip to content',
            'back to top'
        ];

        foreach ($boilerplatePhrases as $phrase) {
            if (str_contains($lineLower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract key nouns, academic terms, numbers, and important phrases for FULLTEXT index
     */
    public static function extractKeywords(string $text): array
    {
        // Strip punctuation
        $clean = preg_replace('/[^\w\s-]/u', ' ', strtolower($text));
        $words = preg_split('/\s+/', $clean);

        $stopwords = [
            'the','and','is','in','it','of','to','a','for','with','on','that','by','this','an','be',
            'are','from','at','as','your','or','have','more','was','not','we','can','will','has',
            'all','one','about','they','which','our','you','other','been','if','no','out','when',
            'so','than','what','who','how','where','why','their','some','them','these','into'
        ];

        $wordCounts = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) < 3 || in_array($word, $stopwords) || is_numeric($word)) {
                continue;
            }
            $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
        }

        arsort($wordCounts);
        $topKeywords = array_slice(array_keys($wordCounts), 0, 20);

        return $topKeywords;
    }

}
