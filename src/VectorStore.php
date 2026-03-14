<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Memory store using FTS5 full-text search.
 *
 * Stores text with metadata in SQLite and provides full-text search.
 * Supports Chinese single-character tokenization for better CJK search.
 */
final class VectorStore
{
    public function __construct(
        private Database $db,
        private ?EmbedderInterface $embedder = null,
        private int $maxCacheSize = 10000,
    ) {}

    /**
     * Store a text with metadata.
     *
     * @param string $type Memory type (gene, capsule, event)
     * @param string $id Unique identifier
     * @param string $text Text to store
     * @param array $metadata Additional metadata
     */
    public function store(string $type, string $id, string $text, array $metadata = []): bool
    {
        // Store in vector_index table (kept for compatibility)
        $sql = <<<'SQL'
            INSERT OR REPLACE INTO vector_index (id, type, text, vector, metadata)
            VALUES (:id, :type, :text, NULL, :metadata)
        SQL;

        $result = $this->db->exec($sql, [
            ':id' => $id,
            ':type' => $type,
            ':text' => $text,
            ':metadata' => json_encode($metadata),
        ]);

        // Store in FTS5 table for full-text search
        $textTokens = Database::tokenizeChinese($text);
        $this->db->exec(
            'INSERT OR REPLACE INTO memory_fts (id, type, text, text_tokens, metadata) VALUES (:id, :type, :text, :text_tokens, :metadata)',
            [
                ':id' => $id,
                ':type' => $type,
                ':text' => $text,
                ':text_tokens' => $textTokens,
                ':metadata' => json_encode($metadata),
            ]
        );

        return $result;
    }

    /**
     * Full-text search using FTS5.
     *
     * @param string $query Query text
     * @param int $limit Maximum results
     * @param float $minScore Minimum relevance score (0-1)
     * @return array Array of [id => score]
     */
    public function search(string $query, int $limit = 10, float $minScore = 0.0): array
    {
        // 对查询进行中文分词
        $queryTokens = Database::tokenizeChinese($query);

        // 使用 FTS5 MATCH 搜索
        // bm25() 返回相关性分数（负值，越接近0越好）
        $sql = <<<'SQL'
            SELECT id, bm25(memory_fts) as score
            FROM memory_fts
            WHERE memory_fts MATCH :query
            ORDER BY score ASC
            LIMIT :limit
        SQL;

        // 转义 FTS5 特殊字符
        $escapedQuery = $this->escapeFtsQuery($queryTokens);

        $rows = $this->db->fetchAll($sql, [
            ':query' => $escapedQuery,
            ':limit' => $limit * 2, // 获取更多结果用于过滤
        ]);

        $results = [];
        foreach ($rows as $row) {
            // bm25 返回负值，转换为 0-1 分数
            // 典型范围：-10 到 0，转换为 0 到 1
            $bm25Score = (float) $row['score'];
            $normalizedScore = max(0, min(1, 1 + ($bm25Score / 10)));

            if ($normalizedScore >= $minScore) {
                $results[$row['id']] = $normalizedScore;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Search within a specific type.
     */
    public function searchByType(string $query, string $type, int $limit = 10, float $minScore = 0.0): array
    {
        $queryTokens = Database::tokenizeChinese($query);
        $escapedQuery = $this->escapeFtsQuery($queryTokens);

        $sql = <<<'SQL'
            SELECT id, bm25(memory_fts) as score
            FROM memory_fts
            WHERE memory_fts MATCH :query AND type = :type
            ORDER BY score ASC
            LIMIT :limit
        SQL;

        $rows = $this->db->fetchAll($sql, [
            ':query' => $escapedQuery,
            ':type' => $type,
            ':limit' => $limit * 2,
        ]);

        $results = [];
        foreach ($rows as $row) {
            $bm25Score = (float) $row['score'];
            $normalizedScore = max(0, min(1, 1 + ($bm25Score / 10)));

            if ($normalizedScore >= $minScore) {
                $results[$row['id']] = $normalizedScore;
            }

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Get similarity between two stored items (based on text overlap).
     */
    public function similarity(string $id1, string $id2): ?float
    {
        $text1 = $this->getText($id1);
        $text2 = $this->getText($id2);

        if ($text1 === null || $text2 === null) {
            return null;
        }

        // 简单的 Jaccard 相似度
        $tokens1 = array_unique(preg_split('/\s+/u', Database::tokenizeChinese($text1)));
        $tokens2 = array_unique(preg_split('/\s+/u', Database::tokenizeChinese($text2)));

        $intersection = array_intersect($tokens1, $tokens2);
        $union = array_unique(array_merge($tokens1, $tokens2));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    /**
     * Get a stored vector by ID (deprecated, returns null).
     */
    public function getVector(string $id): ?array
    {
        return null;
    }

    /**
     * Get metadata for a stored item.
     */
    public function getMetadata(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT type, metadata FROM vector_index WHERE id = :id',
            [':id' => $id]
        );

        if ($row === null) {
            return null;
        }

        $metadata = json_decode($row['metadata'] ?? '{}', true);
        $metadata['type'] = $row['type'];

        return $metadata;
    }

    /**
     * Get text for a stored item.
     */
    public function getText(string $id): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT text FROM vector_index WHERE id = :id',
            [':id' => $id]
        );

        return $row['text'] ?? null;
    }

    /**
     * Delete a stored item.
     */
    public function delete(string $id): bool
    {
        // Delete from FTS5
        $this->db->exec('DELETE FROM memory_fts WHERE id = :id', [':id' => $id]);

        // Delete from vector_index
        return $this->db->exec(
            'DELETE FROM vector_index WHERE id = :id',
            [':id' => $id]
        );
    }

    /**
     * Delete all items of a type.
     */
    public function deleteByType(string $type): int
    {
        $this->db->exec('DELETE FROM memory_fts WHERE type = :type', [':type' => $type]);
        $this->db->exec('DELETE FROM vector_index WHERE type = :type', [':type' => $type]);
        return $this->db->getDb()->changes();
    }

    /**
     * Get total count of stored items.
     */
    public function count(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) as count FROM vector_index');
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get count of items (alias for count(), kept for compatibility).
     */
    public function countWithVectors(): int
    {
        return $this->count();
    }

    /**
     * Clear the cache (no-op for FTS5).
     */
    public function clearCache(): void
    {
        // No cache needed for FTS5
    }

    /**
     * Check if embedder is available (always false now).
     */
    public function hasEmbedder(): bool
    {
        return false;
    }

    /**
     * Get the embedder dimension (always 0 now).
     */
    public function getDimension(): int
    {
        return 0;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Escape FTS5 special characters in query.
     */
    private function escapeFtsQuery(string $query): string
    {
        // FTS5 特殊字符: * " ' ( ) : ; ^ { } [ ] ~
        // 简单处理：用双引号包裹整个查询
        $escaped = str_replace(['"', "'"], '', $query);

        // 分词后用 OR 连接
        $tokens = preg_split('/\s+/u', trim($escaped));
        $tokens = array_filter($tokens, fn($t) => strlen($t) > 0);

        if (empty($tokens)) {
            return '""';
        }

        // 使用 OR 连接多个词，增加召回率
        return implode(' OR ', array_map(fn($t) => '"' . $t . '"', $tokens));
    }
}
