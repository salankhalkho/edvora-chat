<?php

namespace App\Services;

use Exception;

class QueryTranslator
{
    /**
     * Translate visitor non-English query into English for ContentEngine retrieval
     */
    public static function translateToEnglish(string $query): string
    {
        $cleanQuery = trim($query);
        if (empty($cleanQuery)) {
            return '';
        }

        // If query is pure English ASCII with no non-English indicators, return directly to save latency
        if (self::isPureEnglishAscii($cleanQuery)) {
            return $cleanQuery;
        }

        // Use LLM to translate query to clean English search keywords
        try {
            $systemPrompt = "You are an admissions search query translator. Translate the given user query (which may be in Hindi, Tamil, Telugu, Spanish, Hinglish, Tanglish, or any regional script) into clean English search terms focusing on college admissions, fees, courses, eligibility, and placements. Output ONLY the translated English search query text without any formatting, quotes, or conversational preamble.";
            
            $res = LlmService::complete($systemPrompt, $cleanQuery, []);
            $translated = trim($res['text'] ?? '');

            // Clean up any extraneous quotes or punctuation
            $translated = trim(preg_replace('/^["\']|["\']$/', '', $translated));

            if (!empty($translated) && strlen($translated) >= 2) {
                return $translated;
            }
        } catch (Exception $e) {
            error_log("[QueryTranslator] Translation fallback: " . $e->getMessage());
        }

        return $cleanQuery;
    }

    /**
     * Check if text is standard English ASCII without non-English native script characters or regional romanized markers
     */
    private static function isPureEnglishAscii(string $text): bool
    {
        // If string contains non-ASCII characters (Devanagari, Tamil, Telugu, Spanish accents)
        if (preg_match('/[^\x20-\x7E\s]/u', $text)) {
            return false;
        }

        // Check for common Hinglish / Tanglish / Romanized non-English query markers
        $romanizedMarkers = [
            'hai', 'kya', 'kaise', 'kitna', 'kitni', 'kab', 'kahan', 'chahiye',
            'enna', 'iruku', 'enta', 'adi', 'cuanto', 'como', 'donde', 'que', 'por'
        ];

        $words = preg_split('/\s+/', strtolower($text));
        foreach ($words as $word) {
            if (in_array($word, $romanizedMarkers)) {
                return false;
            }
        }

        return true;
    }
}
