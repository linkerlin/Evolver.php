<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Hybrid Retrieval System.
 * Combines vector search + BM25 full-text search with RRF fusion.
 */
final class MemoryRetriever
{
    private const DEFAULT_CONFIG = [
        'mode' => 'hybrid',
        'vectorWeight' => 0.7,
        'bm25Weight' => 0.3,
        'minScore' => 0.3,
        'candidatePoolSize' => 20,
        'recencyHalfLifeDays' => 14,
        'recencyWeight' => 0.1,
        'filterNoise' => true,
        'lengthNormAnchor' => 500,
        'hardMinScore' => 0.35,
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
     * Retrieve memories matching the query.
     *
     * @return RetrievalResult[]
     */
    public function retrieve(string $query, int $limit = 10, array $options = []): array
    {
        $safeLimit = max(1, min(20, $limit));
        $scopeFilter = $options['scope'] ?? null;
        $category = $options['category'] ?? null;

        if ($this->config['mode'] === 'vector' || !$this->hasFtsSupport()) {
            return $this->vectorOnlyRetrieval($query, $safeLimit, $scopeFilter, $category);
        }

        return $this->hybridRetrieval($query, $safeLimit, $scopeFilter, $category);
    }

    /**
     * Vector-only retrieval (fallback when no FTS support).
     */
    private function vectorOnlyRetrieval(
        string $query,
        int $limit,
        ?string $scope,
        ?string $category
    ): array {
        $results = $this->vectorStore->search($query, $limit, $this->config['minScore']);

        // Convert to RetrievalResult objects
        $retrievalResults = [];
        $rank = 1;
        foreach ($results as $id => $score) {
            $entry = $this->getMemoryEntry($id);
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

            $retrievalResults[] = new RetrievalResult(
                entry: $entry,
                score: $score,
                sources: [
                    'vector' => ['score' => $score, 'rank' => $rank++],
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
     * Hybrid retrieval combining vector + BM25.
     */
    private function hybridRetrieval(
        string $query,
        int $limit,
        ?string $scope,
        ?string $category
    ): array {
        $candidatePoolSize = max($this->config['candidatePoolSize'], $limit * 2);

        // Run vector and BM25 searches
        $vectorResults = $this->runVectorSearch($query, $candidatePoolSize, $scope, $category);
        $bm25Results = $this->runBM25Search($query, $candidatePoolSize, $scope, $category);

        // Fuse results using RRF
        $fusedResults = $this->fuseResults($vectorResults, $bm25Results);

        // Apply minimum score threshold
        $fusedResults = array_filter(
            $fusedResults,
            fn(RetrievalResult $r) => $r->score >= $this->config['minScore']
        );

        // Apply boosts
        $fusedResults = $this->applyRecencyBoost($fusedResults);
        $fusedResults = $this->applyLengthNormalization($fusedResults);

        // Hard minimum score filter
        $fusedResults = array_filter(
            $fusedResults,
            fn(RetrievalResult $r) => $r->score >= $this->config['hardMinScore']
        );

        // Apply decay
        $fusedResults = $this->applyDecayBoost($fusedResults);

        // MMR diversity
        $fusedResults = $this->applyMMRDiversity($fusedResults);

        // Re-index and limit
        $fusedResults = array_values($fusedResults);
        return array_slice($fusedResults, 0, $limit);
    }

    /**
     * Run vector search.
     */
    private function runVectorSearch(string $query, int $limit, ?string $scope, ?string $category): array
    {
        $results = $this->vectorStore->search($query, $limit, 0.1);

        $ranked = [];
        $rank = 1;
        foreach ($results as $id => $score) {
            $entry = $this->getMemoryEntry($id);
            if ($entry === null) {
                continue;
            }

            if ($scope !== null && ($entry['scope'] ?? null) !== $scope) {
                continue;
            }
            if ($category !== null && ($entry['category'] ?? null) !== $category) {
                continue;
            }

            $ranked[$id] = [
                'entry' => $entry,
                'score' => $score,
                'rank' => $rank++,
            ];
        }

        return $ranked;
    }

    /**
     * Run BM25 full-text search using SQLite FTS.
     */
    private function runBM25Search(string $query, int $limit, ?string $scope, ?string $category): array
    {
        // Query vector_index table which stores text for all memory types
        $sql = 'SELECT id, type, text, metadata FROM vector_index WHERE 1=1';
        $params = [];

        // Use LIKE for simple matching (no FTS5 available in all SQLite builds)
        $sql .= ' AND text LIKE :query';
        $params[':query'] = '%' . $query . '%';

        if ($category !== null) {
            $sql .= ' AND type = :category';
            $params[':category'] = $category;
        }

        $sql .= ' LIMIT :limit';
        $params[':limit'] = $limit;

        $rows = $this->db->fetchAll($sql, $params);

        $results = [];
        $rank = 1;
        foreach ($rows as $row) {
            $metadata = json_decode($row['metadata'] ?? '{}', true);
            
            // Filter by scope if specified (scope is in metadata)
            if ($scope !== null && ($metadata['scope'] ?? null) !== $scope) {
                continue;
            }
            
            // Simple BM25-like scoring based on match density
            $text = $row['text'] ?? '';
            $queryLen = strlen($query);
            $textLen = strlen($text);
            $matchCount = substr_count(strtolower($text), strtolower($query));
            $score = $textLen > 0 ? min(1.0, ($matchCount * $queryLen) / $textLen * 2) : 0;

            // Build entry compatible with RetrievalResult
            $entry = [
                'id' => $row['id'],
                'text' => $text,
                'category' => $row['type'],
                'scope' => $metadata['scope'] ?? 'global',
                'metadata' => $row['metadata'],
                'importance' => $metadata['importance'] ?? 0.7,
                'timestamp' => $metadata['timestamp'] ?? (time() * 1000),
            ];

            $results[$row['id']] = [
                'entry' => $entry,
                'score' => $score,
                'rank' => $rank++,
            ];
        }

        return $results;
    }

    /**
     * Fuse vector and BM25 results using weighted combination.
     */
    private function fuseResults(array $vectorResults, array $bm25Results): array
    {
        $allIds = array_unique(array_merge(
            array_keys($vectorResults),
            array_keys($bm25Results)
        ));

        $fusedResults = [];

        foreach ($allIds as $id) {
            $vectorResult = $vectorResults[$id] ?? null;
            $bm25Result = $bm25Results[$id] ?? null;

            // Use the result with more complete data
            $baseEntry = $vectorResult['entry'] ?? $bm25Result['entry'] ?? null;
            if ($baseEntry === null) {
                continue;
            }

            $vectorScore = $vectorResult['score'] ?? 0;
            $bm25Score = $bm25Result['score'] ?? 0;

            // Weighted fusion
            $fusedScore = ($vectorScore * $this->config['vectorWeight'])
                        + ($bm25Score * $this->config['bm25Weight']);

            // Preserve high BM25 scores for exact matches
            if ($bm25Score >= 0.75) {
                $fusedScore = max($fusedScore, $bm25Score * 0.92);
            }

            $fusedScore = max(0.1, min(1.0, $fusedScore));

            $fusedResults[] = new RetrievalResult(
                entry: $baseEntry,
                score: $fusedScore,
                sources: [
                    'vector' => $vectorResult ? [
                        'score' => $vectorResult['score'],
                        'rank' => $vectorResult['rank'],
                    ] : null,
                    'bm25' => $bm25Result ? [
                        'score' => $bm25Result['score'],
                        'rank' => $bm25Result['rank'],
                    ] : null,
                    'fused' => ['score' => $fusedScore],
                ]
            );
        }

        // Sort by score descending
        usort($fusedResults, fn($a, $b) => $b->score <=> $a->score);

        return $fusedResults;
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
     * Apply length normalization: penalize long entries.
     */
    private function applyLengthNormalization(array $results): array
    {
        $anchor = $this->config['lengthNormAnchor'];

        if ($anchor <= 0) {
            return $results;
        }

        foreach ($results as $result) {
            $charLen = strlen($result->entry['text'] ?? '');
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
     * MMR diversity filter.
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
                $sim = $this->computeSimilarity($s->entry, $candidate->entry);
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
     * Compute similarity between two entries using their vectors.
     */
    private function computeSimilarity(array $a, array $b): float
    {
        $vecA = $this->vectorStore->getVector($a['id'] ?? '');
        $vecB = $this->vectorStore->getVector($b['id'] ?? '');

        if ($vecA === null || $vecB === null) {
            return 0.0;
        }

        return $this->cosineSimilarity($vecA, $vecB);
    }

    /**
     * Cosine similarity between two vectors.
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
     * Check if FTS support is available.
     */
    private function hasFtsSupport(): bool
    {
        // For now, use hybrid mode always (LIKE-based fallback)
        return true;
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
