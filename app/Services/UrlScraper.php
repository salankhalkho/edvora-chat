<?php

namespace App\Services;

use Exception;

class UrlScraper
{
    /**
     * Fetch web page content via cURL and clean HTML
     */
    public static function scrape(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception("Invalid URL provided: {$url}");
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 edvora-bot/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($html === false || $httpCode >= 400) {
            throw new Exception("Failed to fetch URL (HTTP Status {$httpCode}): {$error}");
        }

        return self::extractContentFromHtml($html, $url);
    }

    /**
     * Parse HTML DOM to extract title and clean plain text content
     */
    private static function extractContentFromHtml(string $html, string $url): array
    {
        // Extract Title
        $title = 'Scraped Webpage';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Remove non-content HTML elements
        $cleanHtml = preg_replace([
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/<style\b[^>]*>(.*?)<\/style>/is',
            '/<nav\b[^>]*>(.*?)<\/nav>/is',
            '/<header\b[^>]*>(.*?)<\/header>/is',
            '/<footer\b[^>]*>(.*?)<\/footer>/is',
            '/<form\b[^>]*>(.*?)<\/form>/is',
            '/<iframe\b[^>]*>(.*?)<\/iframe>/is',
            '/<!--(.*?)-->/s'
        ], '', $html);

        // Convert breaks and block elements to linebreaks
        $cleanHtml = preg_replace('/<(p|br|div|h1|h2|h3|h4|h5|h6|li|tr)[^>]*>/i', "\n", $cleanHtml);

        // Strip remaining HTML tags
        $text = strip_tags($cleanHtml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Sanitize text
        $text = DocumentParser::sanitizeText($text);
        $contentHash = hash('sha256', $text);

        return [
            'title' => $title,
            'raw_content' => $text,
            'content_hash' => $contentHash
        ];
    }
}
