<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Retrieval result from MemoryRetriever.
 */
final class RetrievalResult
{
    /**
     * @param array $entry Memory entry from database
     * @param float $score Final score after all boosts
     * @param array $sources Score sources (vector, bm25, fused, reranked)
     */
    public function __construct(
        public readonly array $entry,
        public float $score,
        public readonly array $sources = []
    ) {}

    /**
     * Get the memory ID.
     */
    public function getId(): string
    {
        return $this->entry['id'] ?? '';
    }

    /**
     * Get the memory text.
     */
    public function getText(): string
    {
        return $this->entry['text'] ?? '';
    }

    /**
     * Get the memory category.
     */
    public function getCategory(): string
    {
        return $this->entry['category'] ?? '';
    }

    /**
     * Get the memory scope.
     */
    public function getScope(): string
    {
        return $this->entry['scope'] ?? 'global';
    }

    /**
     * Get the memory importance.
     */
    public function getImportance(): float
    {
        return (float)($this->entry['importance'] ?? 0.7);
    }

    /**
     * Get parsed metadata.
     */
    public function getMetadata(): SmartMetadata
    {
        $raw = $this->entry['metadata'] ?? '{}';
        if (is_string($raw)) {
            return SmartMetadata::fromJson($raw);
        }
        if (is_array($raw)) {
            return SmartMetadata::fromArray($raw);
        }
        return SmartMetadata::empty();
    }

    /**
     * Get vector source score.
     */
    public function getVectorScore(): ?float
    {
        return $this->sources['vector']['score'] ?? null;
    }

    /**
     * Get BM25 source score.
     */
    public function getBM25Score(): ?float
    {
        return $this->sources['bm25']['score'] ?? null;
    }

    /**
     * Get fused score.
     */
    public function getFusedScore(): ?float
    {
        return $this->sources['fused']['score'] ?? null;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'text' => $this->getText(),
            'score' => $this->score,
            'category' => $this->getCategory(),
            'scope' => $this->getScope(),
            'importance' => $this->getImportance(),
            'sources' => $this->sources,
        ];
    }
}
