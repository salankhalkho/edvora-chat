<?php

namespace App\Services;

use App\Config\Database;
use PDO;
use Exception;

/**
 * VectorSearchEngine
 *
 * Performs semantic vector similarity search against knowledge_items.
 * Retrieves all embeddings for an organization (optionally scoped by program_id),
 * computes PHP cosine similarity, and returns the top-K matching items.
 *
 * Usage:
 *   $items = VectorSearchEngine::findClosest($questionVector, $orgId, $programId, 5);
 *   // Returns array of ['id', 'source_id', 'program_id', 'content', 'score']
 */
class VectorSearchEngine
{
    private const DEFAULT_TOP_K   = 5;
    private const MIN_SCORE       = 0.30;  // Minimum cosine similarity (0–1) to include a result

    /**
     * Find top-K knowledge_items most similar to the given embedding vector.
     *
     * @param  float[]  $queryVector   1536-dim embedding of the user question.
     * @param  int      $organizationId
     * @param  int|null $programId     Optional: scope search to a specific program.
     * @param  int      $topK          Maximum number of results to return.
     * @return array[]                 Sorted by score DESC. Each item:
     *                                 ['id', 'source_id', 'program_id', 'content', 'score']
     */
    public static function findClosest(
        array $queryVector,
        int   $organizationId,
        ?int  $programId = null,
        int   $topK      = self::DEFAULT_TOP_K
    ): array {
        if (empty($queryVector)) {
            return [];
        }

        $db = Database::getConnection();

        // Build query — optionally scope by program_id
        if ($programId !== null) {
            $sql = "
                SELECT id, source_id, program_id, content, embedding
                FROM knowledge_items
                WHERE organization_id = :org_id
                  AND program_id = :prog_id
                  AND embedding IS NOT NULL
            ";
            $params = [':org_id' => $organizationId, ':prog_id' => $programId];
        } else {
            $sql = "
                SELECT id, source_id, program_id, content, embedding
                FROM knowledge_items
                WHERE organization_id = :org_id
                  AND embedding IS NOT NULL
            ";
            $params = [':org_id' => $organizationId];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        // Compute cosine similarity for each item
        $scored = [];
        foreach ($rows as $row) {
            $itemVector = json_decode($row['embedding'], true);
            if (!is_array($itemVector) || empty($itemVector)) {
                continue;
            }

            $score = EmbeddingService::cosineSimilarity($queryVector, $itemVector);

            if ($score >= self::MIN_SCORE) {
                $scored[] = [
                    'id'         => (int)$row['id'],
                    'source_id'  => (int)$row['source_id'],
                    'program_id' => $row['program_id'] ? (int)$row['program_id'] : null,
                    'content'    => $row['content'],
                    'score'      => round($score, 6),
                ];
            }
        }

        if (empty($scored)) {
            return [];
        }

        // Sort by score descending and return top-K
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $topK);
    }
}
