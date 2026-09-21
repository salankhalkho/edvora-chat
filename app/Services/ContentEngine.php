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
            $questionVector = EmbeddingService::embed($query, [
                'organization_id' => $organizationId,
                'activity_type' => 'query_embedding',
                'description' => "Vector search query embedding for org #{$organizationId}"
            ]);
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
                SELECT id, title, keywords, program_id, file_path,
                       MATCH(title, keywords) AGAINST(:query IN NATURAL LANGUAGE MODE) AS score
                FROM knowledge_sources
                WHERE organization_id = :org_id
                  AND status = 'active'
                  AND (effective_from IS NULL OR effective_from <= CURDATE())
                  AND (expires_on IS NULL OR expires_on >= CURDATE())
                  AND MATCH(title, keywords) AGAINST(:query IN NATURAL LANGUAGE MODE) > 0
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
            return self::adaptiveTrim($results, $organizationId);
        }

        foreach ($results as &$r) {
            $r['retrieval_tier'] = 'fulltext';
        }
        unset($r);

        return self::adaptiveTrim($results, $organizationId);
    }

    /**
     * Map knowledge_items vector results to the unified context format expected by PromptBuilder.
     */
    private static function mapKnowledgeItems(array $items, int $organizationId): array
    {
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = [
                'id'                => (int)$item['id'],
                'title'             => $item['title'] ?? 'Knowledge Fact',
                'processed_content' => $item['content'],   // chunk content IS the context
                'program_id'        => !empty($item['program_id']) ? (int)$item['program_id'] : null,
                'score'             => round((float)$item['similarity'], 4),
                'retrieval_tier'    => 'vector'
            ];
        }
        return $mapped;
    }

    /**
     * Adaptive Selection: Top 1–3 items, score >= 30% of max score.
     * Applied to FULLTEXT / LIKE results (vector results already trimmed by VectorSearchEngine).
     */
    private static function adaptiveTrim(array $results, int $organizationId): array
    {
        if (empty($results)) {
            return [];
        }

        $topScore  = (float)$results[0]['score'];
        $threshold = $topScore * 0.30;
        $selected  = [];

        foreach ($results as $item) {
            if (count($selected) === 0 || (float)$item['score'] >= $threshold) {
                $sourceId = (int)$item['id'];
                $content = KnowledgeFileStorage::loadText($organizationId, $sourceId) ?? ($item['keywords'] ?? '');

                $selected[] = [
                    'id'                => $sourceId,
                    'title'             => $item['title'],
                    'processed_content' => $content,
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
     * Tier 3 fallback: LIKE search across title and keywords.
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

            $likeConditions[] = "(LOWER(title) LIKE {$p1} OR LOWER(keywords) LIKE {$p2})";
            $params[$p1]      = "%{$word}%";
            $params[$p2]      = "%{$word}%";
        }

        $whereClause = implode(' OR ', $likeConditions);

        $sql = "
            SELECT id, title, keywords, program_id, file_path, 1.0000 AS score
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
