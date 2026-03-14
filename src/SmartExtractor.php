<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Smart Memory Extractor — LLM-powered extraction pipeline.
 *
 * Replaces regex-triggered capture with intelligent 6-category extraction.
 *
 * Pipeline: conversation → LLM extract → candidates → dedup → persist
 */
final class SmartExtractor
{
    private const SIMILARITY_THRESHOLD = 0.7;
    private const MAX_SIMILAR_FOR_PROMPT = 3;
    private const MAX_MEMORIES_PER_EXTRACTION = 5;

    private \Closure $log;
    private \Closure $debugLog;

    public function __construct(
        private Database $db,
        private VectorStore $vectorStore,
        private LlmClient $llm,
        private array $config = [],
    ) {
        $this->log = $config['log'] ?? fn(string $msg) => error_log($msg);
        $this->debugLog = $config['debugLog'] ?? fn(string $msg) => {};
    }

    /**
     * Extract memories from a conversation text and persist them.
     */
    public function extractAndPersist(
        string $conversationText,
        string $sessionKey = 'unknown',
        array $options = []
    ): array {
        $stats = [
            'created' => 0,
            'merged' => 0,
            'skipped' => 0,
            'supported' => 0,
            'superseded' => 0,
        ];

        $targetScope = $options['scope'] ?? $this->config['defaultScope'] ?? 'global';

        // Step 1: LLM extraction
        $candidates = $this->extractCandidates($conversationText);

        if (empty($candidates)) {
            ($this->log)("memory-pro: smart-extractor: no memories extracted");
            return $stats;
        }

        ($this->log)("memory-pro: smart-extractor: extracted " . count($candidates) . " candidate(s)");

        // Step 2: Process each candidate through dedup pipeline
        $processed = 0;
        foreach ($candidates as $candidate) {
            if ($processed >= self::MAX_MEMORIES_PER_EXTRACTION) {
                break;
            }

            try {
                $this->processCandidate(
                    $candidate,
                    $sessionKey,
                    $stats,
                    $targetScope
                );
                $processed++;
            } catch (\Throwable $err) {
                ($this->log)("memory-pro: smart-extractor: failed to process candidate [{$candidate['category']}]: {$err->getMessage()}");
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Step 1: LLM Extraction
    // -------------------------------------------------------------------------

    /**
     * Call LLM to extract candidate memories from conversation text.
     */
    private function extractCandidates(string $conversationText): array
    {
        $maxChars = $this->config['extractMaxChars'] ?? 8000;
        $truncated = strlen($conversationText) > $maxChars
            ? substr($conversationText, -$maxChars)
            : $conversationText;

        $user = $this->config['user'] ?? 'User';
        $prompt = ExtractionPrompts::buildExtractionPrompt($truncated, $user);

        $result = $this->llm->completeJson($prompt, 'extract-candidates');

        if ($result === null) {
            ($this->debugLog)("memory-pro: smart-extractor: extract-candidates returned null");
            return [];
        }

        if (!isset($result['memories']) || !is_array($result['memories'])) {
            ($this->debugLog)("memory-pro: smart-extractor: extract-candidates returned unexpected shape");
            return [];
        }

        // Validate and normalize candidates
        $candidates = [];
        foreach ($result['memories'] as $raw) {
            $category = MemoryCategory::tryFromString($raw['category'] ?? '');
            if ($category === null) {
                ($this->debugLog)("memory-pro: smart-extractor: dropping candidate due to invalid category");
                continue;
            }

            $abstract = trim($raw['abstract'] ?? '');
            $overview = trim($raw['overview'] ?? '');
            $content = trim($raw['content'] ?? '');

            // Skip empty or too short
            if ($abstract === '' || strlen($abstract) < 5) {
                ($this->debugLog)("memory-pro: smart-extractor: dropping candidate due to short abstract");
                continue;
            }

            // Skip noise (basic check)
            if ($this->isNoise($abstract)) {
                ($this->debugLog)("memory-pro: smart-extractor: dropping candidate due to noise abstract");
                continue;
            }

            $candidates[] = [
                'category' => $category,
                'abstract' => $abstract,
                'overview' => $overview,
                'content' => $content,
            ];
        }

        return $candidates;
    }

    // -------------------------------------------------------------------------
    // Step 2: Dedup + Persist
    // -------------------------------------------------------------------------

    /**
     * Process a single candidate memory: dedup → merge/create → store
     */
    private function processCandidate(
        array $candidate,
        string $sessionKey,
        array &$stats,
        string $targetScope
    ): void {
        /** @var MemoryCategory $category */
        $category = $candidate['category'];

        // Profile always merges (skip dedup)
        if ($category->alwaysMerge()) {
            $this->handleProfileMerge($candidate, $sessionKey, $targetScope);
            $stats['merged']++;
            return;
        }

        // Embed the candidate for vector dedup
        $embeddingText = "{$candidate['abstract']} {$candidate['content']}";
        $vector = $this->vectorStore->getVector($embeddingText);

        // If no embedder available, store directly
        if ($vector === null && !$this->vectorStore->hasEmbedder()) {
            $this->storeCandidate($candidate, [], $sessionKey, $targetScope);
            $stats['created']++;
            return;
        }

        // Dedup pipeline
        $dedupResult = $this->deduplicate($candidate, $embeddingText);

        switch ($dedupResult['decision']) {
            case 'create':
                $this->storeCandidate($candidate, $dedupResult['vector'] ?? [], $sessionKey, $targetScope);
                $stats['created']++;
                break;

            case 'merge':
                if (isset($dedupResult['matchId']) && $category->supportsMerge()) {
                    $this->handleMerge(
                        $candidate,
                        $dedupResult['matchId'],
                        $targetScope,
                        $dedupResult['contextLabel'] ?? null
                    );
                    $stats['merged']++;
                } else {
                    $this->storeCandidate($candidate, $dedupResult['vector'] ?? [], $sessionKey, $targetScope);
                    $stats['created']++;
                }
                break;

            case 'skip':
                ($this->log)("memory-pro: smart-extractor: skipped [{$category->value}] " . substr($candidate['abstract'], 0, 60));
                $stats['skipped']++;
                break;

            case 'supersede':
                if (isset($dedupResult['matchId']) && $category->isTemporalVersioned()) {
                    $this->handleSupersede(
                        $candidate,
                        $dedupResult['vector'] ?? [],
                        $dedupResult['matchId'],
                        $sessionKey,
                        $targetScope
                    );
                    $stats['created']++;
                    $stats['superseded']++;
                } else {
                    $this->storeCandidate($candidate, $dedupResult['vector'] ?? [], $sessionKey, $targetScope);
                    $stats['created']++;
                }
                break;

            case 'support':
                if (isset($dedupResult['matchId'])) {
                    $this->handleSupport(
                        $dedupResult['matchId'],
                        $sessionKey,
                        $dedupResult['reason'] ?? '',
                        $dedupResult['contextLabel'] ?? null
                    );
                    $stats['supported']++;
                } else {
                    $this->storeCandidate($candidate, $dedupResult['vector'] ?? [], $sessionKey, $targetScope);
                    $stats['created']++;
                }
                break;

            default:
                // Unknown decision - create as fallback
                $this->storeCandidate($candidate, $dedupResult['vector'] ?? [], $sessionKey, $targetScope);
                $stats['created']++;
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Dedup Pipeline
    // -------------------------------------------------------------------------

    /**
     * Two-stage dedup: vector similarity search → LLM decision.
     */
    private function deduplicate(array $candidate, string $embeddingText): array
    {
        // Stage 1: Vector pre-filter — find similar memories
        $similar = $this->vectorStore->search($embeddingText, 5, self::SIMILARITY_THRESHOLD);

        if (empty($similar)) {
            return ['decision' => 'create', 'reason' => 'No similar memories found'];
        }

        // Stage 2: LLM decision
        return $this->llmDedupDecision($candidate, $similar, $embeddingText);
    }

    /**
     * Get LLM dedup decision.
     */
    private function llmDedupDecision(array $candidate, array $similar, string $embeddingText): array
    {
        $topSimilar = array_slice($similar, 0, self::MAX_SIMILAR_FOR_PROMPT, true);

        $existingFormatted = '';
        $idx = 1;
        foreach ($topSimilar as $id => $score) {
            $metadata = $this->vectorStore->getMetadata($id);
            $abstract = $metadata['l0_abstract'] ?? $this->vectorStore->getText($id) ?? '';
            $overview = $metadata['l1_overview'] ?? '';
            $category = $metadata['memory_category'] ?? 'unknown';
            $existingFormatted .= "{$idx}. [{$category}] {$abstract}\n   Overview: {$overview}\n   Score: " . number_format($score, 3) . "\n";
            $idx++;
        }

        $prompt = ExtractionPrompts::buildDedupPrompt(
            $candidate['abstract'],
            $candidate['overview'],
            $candidate['content'],
            $existingFormatted
        );

        try {
            $data = $this->llm->completeJson($prompt, 'dedup-decision');

            if ($data === null) {
                ($this->log)("memory-pro: smart-extractor: dedup LLM returned unparseable response, defaulting to CREATE");
                return ['decision' => 'create', 'reason' => 'LLM response unparseable'];
            }

            $decision = strtolower($data['decision'] ?? 'create');

            $validDecisions = ['create', 'merge', 'skip', 'support', 'contextualize', 'contradict', 'supersede'];
            if (!in_array($decision, $validDecisions, true)) {
                return ['decision' => 'create', 'reason' => "Unknown decision: {$data['decision']}"];
            }

            // Resolve match target
            $matchIdx = $data['match_index'] ?? null;
            $hasValidIndex = is_int($matchIdx) && $matchIdx >= 1 && $matchIdx <= count($topSimilar);
            $ids = array_keys($topSimilar);
            $matchId = $hasValidIndex ? $ids[$matchIdx - 1] : ($ids[0] ?? null);

            // For destructive decisions, missing match_index is unsafe
            $destructiveDecisions = ['supersede', 'contradict'];
            if (in_array($decision, $destructiveDecisions, true) && !$hasValidIndex) {
                ($this->log)("memory-pro: smart-extractor: {$decision} decision has missing match_index, degrading to create");
                return ['decision' => 'create', 'reason' => "{$decision} degraded: missing match_index"];
            }

            return [
                'decision' => $decision,
                'reason' => $data['reason'] ?? '',
                'matchId' => in_array($decision, ['merge', 'support', 'contextualize', 'contradict', 'supersede']) ? $matchId : null,
                'contextLabel' => $data['context_label'] ?? null,
                'vector' => $this->vectorStore->getVector($embeddingText),
            ];
        } catch (\Throwable $err) {
            ($this->log)("memory-pro: smart-extractor: dedup LLM failed: {$err->getMessage()}");
            return ['decision' => 'create', 'reason' => "LLM failed: {$err->getMessage()}"];
        }
    }

    // -------------------------------------------------------------------------
    // Merge Logic
    // -------------------------------------------------------------------------

    /**
     * Profile always-merge: read existing profile, merge with LLM, upsert.
     */
    private function handleProfileMerge(array $candidate, string $sessionKey, string $targetScope): void
    {
        // For profile, we simply store as new for now
        // Full implementation would search for existing profile and merge
        $this->storeCandidate($candidate, [], $sessionKey, $targetScope);
    }

    /**
     * Merge a candidate into an existing memory using LLM.
     */
    private function handleMerge(
        array $candidate,
        string $matchId,
        string $targetScope,
        ?string $contextLabel
    ): void {
        $existing = $this->getMemoryById($matchId);
        if ($existing === null) {
            $this->storeCandidate($candidate, [], 'merge-fallback', $targetScope);
            return;
        }

        $existingMeta = SmartMetadata::fromJson($existing['metadata'] ?? '{}');
        $existingAbstract = $existingMeta->abstract ?: ($existing['text'] ?? '');
        $existingOverview = $existingMeta->overview;
        $existingContent = $existingMeta->content;

        /** @var MemoryCategory $category */
        $category = $candidate['category'];

        // Call LLM to merge
        $prompt = ExtractionPrompts::buildMergePrompt(
            $existingAbstract,
            $existingOverview,
            $existingContent,
            $candidate['abstract'],
            $candidate['overview'],
            $candidate['content'],
            $category->value
        );

        $merged = $this->llm->completeJson($prompt, 'merge-memory');

        if ($merged === null) {
            ($this->log)("memory-pro: smart-extractor: merge LLM failed, skipping merge");
            return;
        }

        // Update the memory
        $newMeta = SmartMetadata::fromArray([
            'abstract' => $merged['abstract'] ?? $candidate['abstract'],
            'overview' => $merged['overview'] ?? $candidate['overview'],
            'content' => $merged['content'] ?? $candidate['content'],
            'tier' => $existingMeta->tier,
            'confidence' => min(1.0, $existingMeta->confidence + 0.05),
            'memory_category' => $category->value,
        ]);

        $this->updateMemory($matchId, [
            'text' => $merged['abstract'] ?? $candidate['abstract'],
            'metadata' => $newMeta->toJson(),
        ]);

        ($this->log)("memory-pro: smart-extractor: merged [{$category->value}] into " . substr($matchId, 0, 8));
    }

    /**
     * Handle SUPERSEDE: mark old as historical, create new active.
     */
    private function handleSupersede(
        array $candidate,
        array $vector,
        string $matchId,
        string $sessionKey,
        string $targetScope
    ): void {
        /** @var MemoryCategory $category */
        $category = $candidate['category'];

        // Mark existing as superseded
        $existing = $this->getMemoryById($matchId);
        if ($existing !== null) {
            $existingMeta = SmartMetadata::fromJson($existing['metadata'] ?? '{}');
            $existingMeta->supersededBy = 'pending'; // Will update after create
            $this->updateMemory($matchId, [
                'metadata' => $existingMeta->toJson(),
            ]);
        }

        // Create new memory
        $this->storeCandidate($candidate, $vector, $sessionKey, $targetScope);

        ($this->log)("memory-pro: smart-extractor: superseded [{$category->value}] " . substr($matchId, 0, 8));
    }

    /**
     * Handle SUPPORT: update support stats on existing memory.
     */
    private function handleSupport(
        string $matchId,
        string $sessionKey,
        string $reason,
        ?string $contextLabel
    ): void {
        $existing = $this->getMemoryById($matchId);
        if ($existing === null) {
            return;
        }

        // Increment confidence slightly for supported memories
        $meta = SmartMetadata::fromJson($existing['metadata'] ?? '{}');
        $meta->confidence = min(1.0, $meta->confidence + 0.02);
        $meta->accessCount++;

        $this->updateMemory($matchId, [
            'metadata' => $meta->toJson(),
        ]);

        ($this->log)("memory-pro: smart-extractor: support [{$contextLabel}] on " . substr($matchId, 0, 8) . " — {$reason}");
    }

    // -------------------------------------------------------------------------
    // Store Helper
    // -------------------------------------------------------------------------

    /**
     * Store a candidate memory as a new entry with L0/L1/L2 metadata.
     */
    private function storeCandidate(
        array $candidate,
        array $vector,
        string $sessionKey,
        string $targetScope
    ): void {
        /** @var MemoryCategory $category */
        $category = $candidate['category'];
        $storeCategory = $category->toStoreCategory();

        $id = $this->generateId();
        $now = time() * 1000;

        $metadata = SmartMetadata::fromArray([
            'abstract' => $candidate['abstract'],
            'overview' => $candidate['overview'],
            'content' => $candidate['content'],
            'tier' => 'working',
            'confidence' => 0.7,
            'accessCount' => 0,
            'memory_category' => $category->value,
            'source_session' => $sessionKey,
            'created_at' => $now,
            'last_accessed_at' => $now,
        ]);

        // Store in vector store
        $this->vectorStore->store(
            $storeCategory,
            $id,
            $candidate['abstract'],
            [
                'category' => $category->value,
                'scope' => $targetScope,
                'importance' => $category->getDefaultImportance(),
                'metadata' => $metadata->toJson(),
            ]
        );

        ($this->log)("memory-pro: smart-extractor: created [{$category->value}] " . substr($candidate['abstract'], 0, 60));
    }

    // -------------------------------------------------------------------------
    // Utility methods
    // -------------------------------------------------------------------------

    /**
     * Check if text looks like noise.
     */
    private function isNoise(string $text): bool
    {
        $noisePatterns = [
            '/^(ok|okay|yes|no|sure|thanks?|hi|hello|bye|goodbye)$/i',
            '/^(test|testing|foo|bar|baz)$/i',
            '/^\W+$/',  // Only non-word chars
        ];

        foreach ($noisePatterns as $pattern) {
            if (preg_match($pattern, trim($text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a unique ID.
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get memory by ID from database.
     */
    private function getMemoryById(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM memories WHERE id = :id',
            [':id' => $id]
        );
    }

    /**
     * Update memory in database.
     */
    private function updateMemory(string $id, array $data): bool
    {
        $sets = [];
        $params = [':id' => $id];

        if (isset($data['text'])) {
            $sets[] = 'text = :text';
            $params[':text'] = $data['text'];
        }

        if (isset($data['metadata'])) {
            $sets[] = 'metadata = :metadata';
            $params[':metadata'] = $data['metadata'];
        }

        if (empty($sets)) {
            return false;
        }

        $sql = 'UPDATE memories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        return $this->db->exec($sql, $params);
    }
}
