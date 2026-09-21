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

        return [
            'processed_content' => $processedContent
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
}
