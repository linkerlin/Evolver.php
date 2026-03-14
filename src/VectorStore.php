<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Vector store for semantic search.
 *
 * Stores text with embeddings in SQLite and provides similarity search.
 * Uses in-memory cache for vectors to avoid repeated JSON parsing.
 */
final class VectorStore
{
    private array $vectorCache = [];
    private bool $cacheLoaded = false;

    public function __construct(
        private Database $db,
        private EmbedderInterface $embedder,
        private int $maxCacheSize = 10000,
    ) {}

    /**
     * Store a text with its embedding.
     *
     * @param string $type Memory type (gene, capsule, event)
     * @param string $id Unique identifier
     * @param string $text Text to embed
     * @param array $metadata Additional metadata
     */
    public function store(string $type, string $id, string $text, array $metadata = []): bool
    {
        $vector = $this->embedder->embed($text);
        $vectorJson = $vector !== null ? json_encode($vector) : null;

        $sql = <<<'SQL'
            INSERT OR REPLACE INTO vector_index (id, type, text, vector, metadata)
            VALUES (:id, :type, :text, :vector, :metadata)
        SQL;

        $result = $this->db->exec($sql, [
            ':id' => $id,
            ':type' => $type,
            ':text' => $text,
            ':vector' => $vectorJson,
            ':metadata' => json_encode($metadata),
        ]);

        // Update cache
        if ($vector !== null) {
            $this->vectorCache[$id] = $vector;
            $this->trimCacheIfNeeded();
        }

        return $result;
    }

    /**
     * Search for similar texts.
     *
     * @param string $query Query text
     * @param int $limit Maximum results
     * @param float $minScore Minimum similarity score
     * @return array Array of [id => score]
     */
    public function search(string $query, int $limit = 10, float $minScore = 0.3): array
    {
        $queryVector = $this->embedder->embed($query);
        if ($queryVector === null) {
            return [];
        }

        $this->loadVectorsIfNeeded();

        $scores = [];
        foreach ($this->vectorCache as $id => $vector) {
            $similarity = $this->cosineSimilarity($queryVector, $vector);
            if ($similarity >= $minScore) {
                $scores[$id] = $similarity;
            }
        }

        arsort($scores);
        return array_slice($scores, 0, $limit, true);
    }

    /**
     * Search within a specific type.
     */
    public function searchByType(string $query, string $type, int $limit = 10, float $minScore = 0.3): array
    {
        $results = $this->search($query, $limit * 2, $minScore);

        $filtered = [];
        foreach ($results as $id => $score) {
            $metadata = $this->getMetadata($id);
            if ($metadata !== null && ($metadata['type'] ?? null) === $type) {
                $filtered[$id] = $score;
                if (count($filtered) >= $limit) {
                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Get similarity between two stored vectors.
     */
    public function similarity(string $id1, string $id2): ?float
    {
        $this->loadVectorsIfNeeded();

        $v1 = $this->vectorCache[$id1] ?? null;
        $v2 = $this->vectorCache[$id2] ?? null;

        if ($v1 === null || $v2 === null) {
            return null;
        }

        return $this->cosineSimilarity($v1, $v2);
    }

    /**
     * Get a stored vector by ID.
     */
    public function getVector(string $id): ?array
    {
        $this->loadVectorsIfNeeded();
        return $this->vectorCache[$id] ?? null;
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
        unset($this->vectorCache[$id]);

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
        // Remove from cache
        $this->vectorCache = array_filter(
            $this->vectorCache,
            fn($id) => ($this->getMetadata($id)['type'] ?? null) !== $type,
            ARRAY_FILTER_USE_KEY
        );

        $this->db->exec('DELETE FROM vector_index WHERE type = :type', [':type' => $type]);
        return $this->db->getDb()->changes();
    }

    /**
     * Get total count of stored vectors.
     */
    public function count(): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) as count FROM vector_index');
        return (int)($row['count'] ?? 0);
    }

    /**
     * Get count of vectors with embeddings.
     */
    public function countWithVectors(): int
    {
        $this->loadVectorsIfNeeded();
        return count($this->vectorCache);
    }

    /**
     * Clear the vector cache.
     */
    public function clearCache(): void
    {
        $this->vectorCache = [];
        $this->cacheLoaded = false;
    }

    /**
     * Check if embedder is available.
     */
    public function hasEmbedder(): bool
    {
        return $this->embedder->isAvailable();
    }

    /**
     * Get the embedder dimension.
     */
    public function getDimension(): int
    {
        return $this->embedder->getDimension();
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Calculate cosine similarity between two vectors.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $norm = sqrt($normA) * sqrt($normB);
        return $norm > 0 ? $dot / $norm : 0.0;
    }

    /**
     * Load vectors from database into cache.
     */
    private function loadVectorsIfNeeded(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        $rows = $this->db->fetchAll(
            'SELECT id, vector FROM vector_index WHERE vector IS NOT NULL'
        );

        foreach ($rows as $row) {
            $vector = json_decode($row['vector'], true);
            if (is_array($vector)) {
                $this->vectorCache[$row['id']] = $vector;
            }
        }

        $this->cacheLoaded = true;
    }

    /**
     * Trim cache if it exceeds max size.
     */
    private function trimCacheIfNeeded(): void
    {
        if (count($this->vectorCache) > $this->maxCacheSize) {
            // Remove oldest entries (first half)
            $toRemove = (int)(count($this->vectorCache) / 2);
            $keys = array_keys($this->vectorCache);
            for ($i = 0; $i < $toRemove; $i++) {
                unset($this->vectorCache[$keys[$i]]);
            }
        }
    }
}
