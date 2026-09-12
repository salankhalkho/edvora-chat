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
            'keywords'          => implode(', ', $keywords),
            'semantic_keywords' => '' // populated asynchronously via enrich_keywords job
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

    /**
     * Generate LLM-based strategic keyword phrases (2–5 words) for intent-based retrieval.
     *
     * Called asynchronously by the enrich_keywords background job after a knowledge
     * source is uploaded. Uses the primary chat LLM to produce 10–15 phrases that mirror
     * how a prospective student or parent would phrase their question in a chat window.
     *
     * Throws an Exception on failure so the worker can apply the retry schedule
     * (30 min → 60 min → 5 hr → permanently failed).
     *
     * @param int $sourceId  knowledge_sources.id
     * @throws \Exception    on LLM failure, unparseable response, or empty validated set
     */
    public static function generateSemanticKeywords(int $sourceId): void
    {
        $db = \App\Config\Database::getConnection();

        $stmt = $db->prepare("SELECT title, processed_content FROM knowledge_sources WHERE id = :id");
        $stmt->execute([':id' => $sourceId]);
        $source = $stmt->fetch();

        if (!$source || empty($source['processed_content'])) {
            throw new \Exception("Source #{$sourceId} not found or has no processed content.");
        }

        $title          = $source['title'];
        $contentExcerpt = mb_substr($source['processed_content'], 0, 2000);

        $systemPrompt = <<<'EOT'
You are a search-index optimization expert for an educational institution admissions chatbot.
Your task is to extract strategic keyword phrases from the document that a prospective student or parent would realistically type into a chat window.

Rules:
- Return ONLY a valid JSON array. No markdown, no code fences, no explanations.
- Each phrase must be 2 to 5 words.
- Generate 10 to 15 phrases.
- Phrases must reflect realistic user intent (e.g. "hostel fee per year", "MBA admission eligibility", "scholarship for 90 percent").
- Do NOT use full sentences or questions.

Example of valid output:
["hostel fee per year", "MBA admission eligibility", "scholarship for top scorers", "application deadline 2026", "JEE main cutoff required"]
EOT;

        $userMessage = "DOCUMENT TITLE: {$title}\n\nDOCUMENT CONTENT:\n{$contentExcerpt}\n\nReturn the JSON array of keyword phrases now:";

        $result       = LlmService::complete($systemPrompt, $userMessage);
        $responseText = trim($result['text'] ?? '');

        if (empty($responseText)) {
            throw new \Exception("LLM returned an empty response for source #{$sourceId}.");
        }

        // Strip markdown code fences if the LLM wraps the JSON (e.g. ```json [...] ```)
        $responseText = preg_replace('/^```(?:json)?\s*/i', '', $responseText);
        $responseText = preg_replace('/\s*```$/', '', $responseText);
        $responseText = trim($responseText);

        // Extract the JSON array portion if surrounded by extra text
        if (preg_match('/(\[.*\])/s', $responseText, $matches)) {
            $responseText = $matches[1];
        }

        $phrases = json_decode($responseText, true);

        if (!is_array($phrases) || empty($phrases)) {
            throw new \Exception("LLM returned invalid JSON for source #{$sourceId}: " . substr($responseText, 0, 200));
        }

        // Validate and sanitize each phrase: must be a string with 2–5 words
        $validated = [];
        foreach ($phrases as $phrase) {
            if (!is_string($phrase)) {
                continue;
            }
            // Strip non-word characters except spaces and hyphens
            $phrase    = trim(preg_replace('/[^\w\s\-]/u', '', $phrase));
            $wordCount = str_word_count($phrase);
            if ($wordCount >= 2 && $wordCount <= 5 && strlen($phrase) >= 4) {
                $validated[] = strtolower($phrase);
            }
            if (count($validated) >= 15) {
                break;
            }
        }

        if (empty($validated)) {
            throw new \Exception("No valid 2–5 word phrases found in LLM response for source #{$sourceId}.");
        }

        $semanticKeywords = implode(', ', $validated);

        $stmtUpdate = $db->prepare("UPDATE knowledge_sources SET semantic_keywords = :sk, updated_at = NOW() WHERE id = :id");
        $stmtUpdate->execute([':sk' => $semanticKeywords, ':id' => $sourceId]);
    }
}
