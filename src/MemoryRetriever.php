<?php

declare(strict_types=1);

namespace Evolver;

/**
 * FTS5-based Retrieval System.
 * Full-text search with Chinese tokenization support.
 */
final class MemoryRetriever
{
    private const DEFAULT_CONFIG = [
        'mode' => 'fts',
        'minScore' => 0.0,
        'candidatePoolSize' => 20,
        'recencyHalfLifeDays' => 14,
        'recencyWeight' => 0.1,
        'filterNoise' => true,
        'lengthNormAnchor' => 500,
        'hardMinScore' => 0.0,
        'timeDecayHalfLifeDays' => 60,
    ];

    public function __construct(
        private Database $db,
        private VectorStore $vectorStore,
        private ?DecayEngine $decayEngine = null,
        private array $config = []
    ) {
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);
    }

    /**
     * Retrieve memories matching the query using FTS5.
     *
     * @return RetrievalResult[]
     */
    public function retrieve(string $query, int $limit = 10, array $options = []): array
    {
        $safeLimit = max(1, min(20, $limit));
        $scopeFilter = $options['scope'] ?? null;
        $category = $options['category'] ?? null;

        return $this->ftsRetrieval($query, $safeLimit, $scopeFilter, $category);
    }

    /**
     * FTS5-based retrieval.
     */
    private function ftsRetrieval(
        string $query,
        int $limit,
        ?string $scope,
        ?string $category
    ): array {
        $candidatePoolSize = max($this->config['candidatePoolSize'], $limit * 2);

        // 使用 FTS5 全文搜索
        $queryTokens = Database::tokenizeChinese($query);
        $escapedQuery = $this->escapeFtsQuery($queryTokens);

        $sql = <<<'SQL'
            SELECT id, bm25(memory_fts) as score
            FROM memory_fts
            WHERE memory_fts MATCH :query
            ORDER BY score ASC
            LIMIT :limit
        SQL;

        $rows = $this->db->fetchAll($sql, [
            ':query' => $escapedQuery,
            ':limit' => $candidatePoolSize,
        ]);

        $retrievalResults = [];

        foreach ($rows as $row) {
            $entry = $this->getMemoryEntry($row['id']);
            if ($entry === null) {
                continue;
            }

            // Filter by scope and category
            if ($scope !== null && ($entry['scope'] ?? null) !== $scope) {
                continue;
            }
            if ($category !== null && ($entry['category'] ?? null) !== $category) {
                continue;
            }

            // bm25 返回负值，转换为 0-1 分数
            $bm25Score = (float) $row['score'];
            $normalizedScore = max(0, min(1, 1 + ($bm25Score / 10)));

            $retrievalResults[] = new RetrievalResult(
                entry: $entry,
                score: $normalizedScore,
                sources: [
                    'fts' => ['score' => $normalizedScore, 'bm25' => $bm25Score],
                ]
            );
        }

        // Apply boosts
        $retrievalResults = $this->applyRecencyBoost($retrievalResults);
        $retrievalResults = $this->applyLengthNormalization($retrievalResults);
        $retrievalResults = $this->applyDecayBoost($retrievalResults);

        // Hard minimum score filter
        $retrievalResults = array_filter(
            $retrievalResults,
            fn(RetrievalResult $r) => $r->score >= $this->config['hardMinScore']
        );

        // MMR diversity
        $retrievalResults = $this->applyMMRDiversity($retrievalResults);

        // Re-index and limit
        $retrievalResults = array_values($retrievalResults);
        return array_slice($retrievalResults, 0, $limit);
    }

    /**
     * Apply recency boost: newer memories get a small score bonus.
     */
    private function applyRecencyBoost(array $results): array
    {
        $halfLife = $this->config['recencyHalfLifeDays'];
        $weight = $this->config['recencyWeight'];

        if ($halfLife <= 0 || $weight <= 0) {
            return $results;
        }

        $now = time() * 1000;

        foreach ($results as $result) {
            $ts = $result->entry['timestamp'] ?? $now;
            $ageDays = ($now - $ts) / 86400000;
            $boost = exp(-$ageDays / $halfLife) * $weight;
            $result->score = min(1.0, $result->score + $boost);
        }

        usort($results, fn($a, $b) => $b->score <=> $a->score);
        return $results;
    }

    /**
     * Apply length normalization: penalize very long entries.
     */
    private function applyLengthNormalization(array $results): array
    {
        $anchor = $this->config['lengthNormAnchor'];

        if ($anchor <= 0) {
            return $results;
        }

        foreach ($results as $result) {
            $charLen = mb_strlen($result->entry['text'] ?? '', 'UTF-8');
            $ratio = $charLen / $anchor;
            $logRatio = log(max($ratio, 1), 2);
            $factor = 1 / (1 + 0.5 * $logRatio);
            $result->score = max($result->score * 0.3, min(1.0, $result->score * $factor));
        }

        usort($results, fn($a, $b) => $b->score <=> $a->score);
        return $results;
    }

    /**
     * Apply decay engine boost if available.
     */
    private function applyDecayBoost(array $results): array
    {
        if ($this->decayEngine === null || empty($results)) {
            return $results;
        }

        $now = (int)(microtime(true) * 1000);

        foreach ($results as $result) {
            $metadata = SmartMetadata::fromJson($result->entry['metadata'] ?? '{}');
            $memory = new SimpleDecayableMemory(
                id: $result->entry['id'],
                tier: $metadata->tier,
                importance: $result->entry['importance'] ?? 0.7,
                confidence: $metadata->confidence,
                accessCount: $metadata->accessCount,
                createdAt: $metadata->createdAt ?? $now,
                lastAccessedAt: $metadata->lastAccessedAt ?? $now,
            );

            $score = $this->decayEngine->score($memory, $now);
            // Blend decay score with search score
            $result->score = max($result->score * 0.3, $result->score * 0.7 + $score->composite * 0.3);
        }

        usort($results, fn($a, $b) => $b->score <=> $a->score);
        return $results;
    }

    /**
     * MMR diversity filter using text similarity.
     */
    private function applyMMRDiversity(array $results, float $similarityThreshold = 0.85): array
    {
        if (count($results) <= 1) {
            return $results;
        }

        $selected = [];
        $deferred = [];

        foreach ($results as $candidate) {
            $tooSimilar = false;

            foreach ($selected as $s) {
                $sim = $this->computeTextSimilarity($s->entry, $candidate->entry);
                if ($sim > $similarityThreshold) {
                    $tooSimilar = true;
                    break;
                }
            }

            if ($tooSimilar) {
                $deferred[] = $candidate;
            } else {
                $selected[] = $candidate;
            }
        }

        return array_merge($selected, $deferred);
    }

    /**
     * Compute text similarity using Jaccard index on tokens.
     */
    private function computeTextSimilarity(array $a, array $b): float
    {
        $textA = $a['text'] ?? '';
        $textB = $b['text'] ?? '';

        $tokensA = array_unique(preg_split('/\s+/u', Database::tokenizeChinese($textA)));
        $tokensB = array_unique(preg_split('/\s+/u', Database::tokenizeChinese($textB)));

        $tokensA = array_filter($tokensA, fn($t) => strlen($t) > 0);
        $tokensB = array_filter($tokensB, fn($t) => strlen($t) > 0);

        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersection = array_intersect($tokensA, $tokensB);
        $union = array_unique(array_merge($tokensA, $tokensB));

        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }

    /**
     * Get memory entry from database.
     */
    private function getMemoryEntry(string $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, type, text, metadata FROM vector_index WHERE id = :id',
            [':id' => $id]
        );

        if ($row === null) {
            return null;
        }

        $metadata = json_decode($row['metadata'] ?? '{}', true);

        return [
            'id' => $row['id'],
            'text' => $row['text'],
            'category' => $row['type'],
            'scope' => $metadata['scope'] ?? 'global',
            'metadata' => $row['metadata'],
            'importance' => $metadata['importance'] ?? 0.7,
            'timestamp' => $metadata['timestamp'] ?? (time() * 1000),
        ];
    }

    /**
     * Escape FTS5 special characters in query.
     */
    private function escapeFtsQuery(string $query): string
    {
        // FTS5 特殊字符: * " ' ( ) : ; ^ { } [ ] ~
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

    /**
     * Update configuration.
     */
    public function updateConfig(array $newConfig): void
    {
        $this->config = array_merge($this->config, $newConfig);
    }

    /**
     * Get current configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Test the retrieval system.
     */
    public function test(string $query = 'test query'): array
    {
        try {
            $results = $this->retrieve($query, 1);

            return [
                'success' => true,
                'mode' => $this->config['mode'],
                'resultCount' => count($results),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'mode' => $this->config['mode'],
                'error' => $e->getMessage(),
            ];
        }
    }
}
