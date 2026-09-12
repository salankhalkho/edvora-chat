<?php

namespace App\Services;

use Exception;
use ZipArchive;

class DocumentParser
{
    /**
     * Parse text from file based on mime type or extension
     */
    public static function parse(string $filePath, string $originalFilename): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found at path: {$filePath}");
        }

        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'pdf':
                return self::parsePdf($filePath);
            case 'docx':
                return self::parseDocx($filePath);
            case 'txt':
                return self::parseTxt($filePath);
            default:
                throw new Exception("Unsupported file extension: .{$extension}. Allowed: .pdf, .docx, .txt");
        }
    }

    /**
     * Extract text from PDF using poppler-utils pdftotext
     */
    private static function parsePdf(string $filePath): string
    {
        $escapedPath = escapeshellarg($filePath);
        $command = "pdftotext -layout {$escapedPath} -";

        $output = shell_exec($command);
        if ($output === null || trim($output) === '') {
            // Fallback command without layout flag if needed
            $command = "pdftotext {$escapedPath} -";
            $output = shell_exec($command);
        }

        if ($output === null) {
            throw new Exception("Failed to execute pdftotext on PDF file.");
        }

        return self::sanitizeText($output);
    }

    /**
     * Extract text from DOCX file by reading word/document.xml
     */
    private static function parseDocx(string $filePath): string
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception("PHP ZipArchive extension is required for DOCX parsing.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Failed to open DOCX archive.");
        }

        $xmlIndex = $zip->locateName('word/document.xml');
        if ($xmlIndex === false) {
            $zip->close();
            throw new Exception("Invalid DOCX format: word/document.xml missing.");
        }

        $xmlData = $zip->getFromIndex($xmlIndex);
        $zip->close();

        if (empty($xmlData)) {
            throw new Exception("Empty XML content in DOCX file.");
        }

        // Replace paragraph tags with linebreaks
        $xmlData = str_replace(['</w:p>', '</w:tr>'], "\n", $xmlData);
        $text = strip_tags($xmlData);

        return self::sanitizeText($text);
    }

    /**
     * Extract text from plain text (.txt) file
     */
    private static function parseTxt(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new Exception("Failed to read text file.");
        }

        return self::sanitizeText($content);
    }

    /**
     * Clean and normalize extracted text
     */
    public static function sanitizeText(string $text): string
    {
        // Convert to UTF-8 encoding if needed
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }

        // Strip non-printable control characters except newline and tab
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normalize multiple spaces and multiple blank lines
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text);

        return trim($text);
    }
}
