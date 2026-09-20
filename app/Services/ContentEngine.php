<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class ContentEngine
{
    /**
     * Select top 1 to 5 relevant knowledge items using search strategy.
     *
     * Tier 1 — Primary (Vector Search):
     *   Embed the user query, then cosine similarity search against knowledge_items.
     *   Optionally scoped by detected program_id.
     *
     * Tier 2 — Fallback (FULLTEXT on knowledge_sources):
     *   Used if knowledge_items table is empty or vector search returns nothing.
     *   MATCH(title, keywords, processed_content) AGAINST(query).
     *
     * Tier 3 — Last Resort (LIKE search):
     *   Used if FULLTEXT also returns nothing.
     *
     * Returns unified array of context items each with:
     *   ['id', 'title', 'processed_content', 'program_id', 'score', 'retrieval_tier']
     */
    public static function selectContext(
        int    $organizationId,
        string $userQuery,
        ?int   $chatbotId  = null,
        ?int   $programId  = null
    ): array {
        $query = trim($userQuery);
        if (empty($query)) {
            return [];
        }

        // ──────────────────────────────────────────────────────────────────────
        // TIER 1: Vector Similarity Search against knowledge_items
        // ──────────────────────────────────────────────────────────────────────
        try {
            $questionVector = EmbeddingService::embed($query);
            $items = VectorSearchEngine::findClosest($questionVector, $organizationId, $programId, 5);

            if (!empty($items)) {
                // Map knowledge_items results to unified context format
                return self::mapKnowledgeItems($items, $organizationId);
            }
        } catch (Throwable $e) {
            error_log('[ContentEngine] Vector search failed, falling back to FULLTEXT. Error: ' . $e->getMessage());
        }

        // ──────────────────────────────────────────────────────────────────────
        // TIER 2: FULLTEXT on knowledge_sources (legacy fallback)
        // ──────────────────────────────────────────────────────────────────────
        $db          = Database::getConnection();
        $results     = [];
        $cleanQuery  = preg_replace('/[^\w\s]/u', ' ', $query);

        try {
            $sqlTier2 = "
                SELECT id, title, processed_content, keywords, program_id,
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
            $stmt = $db->prepare($sqlTier2);
            $stmt->execute([':query' => $cleanQuery, ':org_id' => $organizationId]);
            $results = $stmt->fetchAll();
        } catch (Throwable $e) {
            // FULLTEXT unavailable — fall through to Tier 3
        }

        // ──────────────────────────────────────────────────────────────────────
        // TIER 3: LIKE search (last resort)
        // ──────────────────────────────────────────────────────────────────────
        if (empty($results)) {
            $results = self::fallbackLikeSearch($db, $organizationId, $cleanQuery);
            foreach ($results as &$r) {
                $r['retrieval_tier'] = 'like_fallback';
            }
            unset($r);
            return self::adaptiveTrim($results);
        }

        foreach ($results as &$r) {
            $r['retrieval_tier'] = 'fulltext';
        }
        unset($r);

        return self::adaptiveTrim($results);
    }

    /**
     * Map knowledge_items vector results to the unified context format expected by PromptBuilder.
     * Fetches the parent knowledge_source title for display.
     */
    private static function mapKnowledgeItems(array $items, int $organizationId): array
    {
        $db = Database::getConnection();
        $sourceIds = array_unique(array_column($items, 'source_id'));

        // Bulk-fetch source titles
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmtSrc = $db->prepare("
            SELECT id, title FROM knowledge_sources
            WHERE id IN ({$placeholders}) AND organization_id = ?
        ");
        $stmtSrc->execute(array_merge($sourceIds, [$organizationId]));
        $sourceTitles = [];
        foreach ($stmtSrc->fetchAll(PDO::FETCH_ASSOC) as $src) {
            $sourceTitles[(int)$src['id']] = $src['title'];
        }

        $mapped = [];
        foreach ($items as $item) {
            $srcId = (int)$item['source_id'];
            $mapped[] = [
                'id'                => $item['id'],
                'title'             => $sourceTitles[$srcId] ?? 'Knowledge Item',
                'processed_content' => $item['content'],   // chunk content IS the context
                'program_id'        => $item['program_id'],
                'score'             => $item['score'],
                'retrieval_tier'    => 'vector',
            ];
        }

        return $mapped;
    }

    /**
     * Adaptive Selection: Top 1–3 items, score >= 30% of max score.
     * Applied to FULLTEXT / LIKE results (vector results already trimmed by VectorSearchEngine).
     */
    private static function adaptiveTrim(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $topScore  = (float)$results[0]['score'];
        $threshold = $topScore * 0.30;
        $selected  = [];

        foreach ($results as $item) {
            if (count($selected) === 0 || (float)$item['score'] >= $threshold) {
                $selected[] = [
                    'id'                => (int)$item['id'],
                    'title'             => $item['title'],
                    'processed_content' => $item['processed_content'],
                    'program_id'        => $item['program_id'] ?? null,
                    'score'             => round((float)$item['score'], 4),
                    'retrieval_tier'    => $item['retrieval_tier'] ?? 'fulltext',
                ];
            }
            if (count($selected) >= 3) {
                break;
            }
        }

        return $selected;
    }

    /**
     * Tier 3 fallback: LIKE search across title, keywords, processed_content.
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
            SELECT id, title, processed_content, keywords, program_id, 1.0000 AS score
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
