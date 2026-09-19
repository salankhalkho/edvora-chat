<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class ContentEngine
{
    /**
     * Select top 1 to 3 relevant knowledge sources using search strategy.
     *
     * Tier 1 — Primary:
     *   FULLTEXT MATCH against title + keywords + processed_content.
     *
     * Tier 2 — Fallback:
     *   LIKE keyword search across title, keywords, processed_content.
     *
     * All tiers apply adaptive selection: top 1–3 results, score >= 30% of top score.
     */
    public static function selectContext(int $organizationId, string $userQuery, ?int $chatbotId = null): array
    {
        $query = trim($userQuery);
        if (empty($query)) {
            return [];
        }

        $db         = Database::getConnection();
        $results    = [];
        $cleanQuery = preg_replace('/[^\w\s]/u', ' ', $query);

        // ──────────────────────────────────────────────────────────────────────
        // TIER 1: Primary — FULLTEXT on title + keywords + processed_content
        // ──────────────────────────────────────────────────────────────────────
        try {
            $sqlTier1 = "
                SELECT id, title, processed_content, keywords,
                       MATCH(title, keywords, processed_content) AGAINST(:query IN NATURAL LANGUAGE MODE) AS score
                FROM knowledge_sources
                WHERE organization_id = :org_id
                  AND status = 'active'
                  AND (effective_from IS NULL OR effective_from <= CURDATE())
                  AND (expires_on IS NULL OR expires_on >= CURDATE())
                  AND MATCH(title, keywords, processed_content) AGAINST(:query IN NATURAL LANGUAGE MODE) > 0
                ORDER BY score DESC
                LIMIT 10
            ";
            $stmt = $db->prepare($sqlTier1);
            $stmt->execute([':query' => $cleanQuery, ':org_id' => $organizationId]);
            $results = $stmt->fetchAll();
        } catch (Throwable $e) {
            // FULLTEXT unavailable — fall through to Tier 2
        }

        // ──────────────────────────────────────────────────────────────────────
        // TIER 2: Fallback — LIKE search across title, keywords, processed_content
        // ──────────────────────────────────────────────────────────────────────
        if (empty($results)) {
            $results = self::fallbackLikeSearch($db, $organizationId, $cleanQuery);
        }

        if (empty($results)) {
            return [];
        }

        // ──────────────────────────────────────────────────────────────────────
        // Adaptive Selection: Top 1–3, score >= 30% of max score
        // ──────────────────────────────────────────────────────────────────────
        $topScore        = (float)$results[0]['score'];
        $threshold       = $topScore * 0.30;
        $selectedSources = [];

        foreach ($results as $item) {
            $itemScore = (float)$item['score'];

            // Always include the top result; include subsequent matches if score >= threshold
            if (count($selectedSources) === 0 || $itemScore >= $threshold) {
                $selectedSources[] = [
                    'id'                => (int)$item['id'],
                    'title'             => $item['title'],
                    'processed_content' => $item['processed_content'],
                    'score'             => round($itemScore, 4)
                ];
            }

            // Cap at maximum 3 sources
            if (count($selectedSources) >= 3) {
                break;
            }
        }

        return $selectedSources;
    }

    /**
     * Tier 2 fallback: LIKE search across title, keywords, processed_content.
     * Used when FULLTEXT produces 0 results (e.g. very short single-char queries).
     */
    private static function fallbackLikeSearch(PDO $db, int $organizationId, string $query): array
    {
        $words = preg_split('/\s+/', strtolower($query));
        $words = array_filter($words, fn($w) => strlen($w) >= 3);

        if (empty($words)) {
            return [];
        }

        $likeConditions = [];
        $params         = [':org_id' => $organizationId];

        foreach ($words as $idx => $word) {
            $p1 = ":w1_{$idx}";
            $p2 = ":w2_{$idx}";
            $p3 = ":w3_{$idx}";

            $likeConditions[] = "(LOWER(title) LIKE {$p1} OR LOWER(keywords) LIKE {$p2} OR LOWER(processed_content) LIKE {$p3})";
            $params[$p1]      = "%{$word}%";
            $params[$p2]      = "%{$word}%";
            $params[$p3]      = "%{$word}%";
        }

        $whereClause = implode(' OR ', $likeConditions);

        $sql = "
            SELECT id, title, processed_content, keywords, 1.0000 AS score
            FROM knowledge_sources
            WHERE organization_id = :org_id
              AND status = 'active'
              AND (effective_from IS NULL OR effective_from <= CURDATE())
              AND (expires_on IS NULL OR expires_on >= CURDATE())
              AND ({$whereClause})
            ORDER BY id DESC
            LIMIT 3
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
