<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Smart Metadata structure for long-term memory.
 *
 * Implements L0/L1/L2 content hierarchy, lifecycle management,
 * temporal versioning, and relation tracking inspired by memory-lancedb-pro.
 */
final class SmartMetadata
{
    // L0: One-line abstract for indexing (< 80 chars recommended)
    public string $abstract = '';

    // L1: Structured overview for context injection (Markdown)
    public string $overview = '';

    // L2: Full content for deep analysis
    public string $content = '';

    // Lifecycle
    public string $tier = 'working'; // core|working|peripheral
    public int $accessCount = 0;
    public int $lastAccessedAt = 0;
    public float $confidence = 0.7;

    // Temporal versioning
    public ?string $factKey = null;
    public ?int $validFrom = null;
    public ?int $invalidatedAt = null;
    public ?string $supersededBy = null;
    public ?string $supersedes = null;

    // Context support statistics
    public array $supportInfo = [];

    // Relations to other memories
    public array $relations = [];

    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Create from JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new self();
        }
        return new self($data);
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'abstract' => $this->abstract,
            'overview' => $this->overview,
            'content' => $this->content,
            'tier' => $this->tier,
            'accessCount' => $this->accessCount,
            'lastAccessedAt' => $this->lastAccessedAt,
            'confidence' => $this->confidence,
            'factKey' => $this->factKey,
            'validFrom' => $this->validFrom,
            'invalidatedAt' => $this->invalidatedAt,
            'supersededBy' => $this->supersededBy,
            'supersedes' => $this->supersedes,
            'supportInfo' => $this->supportInfo,
            'relations' => $this->relations,
        ];
    }

    /**
     * Create an empty instance.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * Create with abstract only (L0).
     */
    public static function fromAbstract(string $abstract): self
    {
        return new self(['abstract' => $abstract]);
    }

    /**
     * Create with full content hierarchy.
     */
    public static function fromHierarchy(string $abstract, string $overview, string $content): self
    {
        return new self([
            'abstract' => $abstract,
            'overview' => $overview,
            'content' => $content,
        ]);
    }

    /**
     * Check if this memory is invalidated.
     */
    public function isInvalidated(): bool
    {
        return $this->invalidatedAt !== null;
    }

    /**
     * Check if this memory supersedes another.
     */
    public function hasSuperseded(): bool
    {
        return $this->supersedes !== null;
    }

    /**
     * Check if this memory has been superseded.
     */
    public function isSuperseded(): bool
    {
        return $this->supersededBy !== null;
    }

    /**
     * Check if this is a core tier memory.
     */
    public function isCore(): bool
    {
        return $this->tier === 'core';
    }

    /**
     * Check if this is a working tier memory.
     */
    public function isWorking(): bool
    {
        return $this->tier === 'working';
    }

    /**
     * Check if this is a peripheral tier memory.
     */
    public function isPeripheral(): bool
    {
        return $this->tier === 'peripheral';
    }

    /**
     * Record an access (increment count and update timestamp).
     */
    public function recordAccess(?int $timestamp = null): void
    {
        $this->accessCount++;
        $this->lastAccessedAt = $timestamp ?? (int)(microtime(true) * 1000);
    }

    /**
     * Get the decay floor for this tier.
     */
    public function getDecayFloor(): float
    {
        return match ($this->tier) {
            'core' => 0.9,
            'working' => 0.7,
            'peripheral' => 0.5,
            default => 0.5,
        };
    }

    /**
     * Add a relation to another memory.
     */
    public function addRelation(string $targetId, string $type, float $strength = 1.0): void
    {
        $this->relations[] = [
            'targetId' => $targetId,
            'type' => $type,
            'strength' => $strength,
        ];
    }

    /**
     * Add support info (context confirmation).
     */
    public function addSupport(string $source, float $weight = 1.0): void
    {
        if (!isset($this->supportInfo['sources'])) {
            $this->supportInfo['sources'] = [];
        }
        $this->supportInfo['sources'][] = [
            'source' => $source,
            'weight' => $weight,
            'timestamp' => (int)(microtime(true) * 1000),
        ];

        // Update aggregate confidence
        $this->recalculateConfidence();
    }

    /**
     * Recalculate confidence based on support info.
     */
    private function recalculateConfidence(): void
    {
        if (empty($this->supportInfo['sources'])) {
            return;
        }

        $totalWeight = 0;
        foreach ($this->supportInfo['sources'] as $support) {
            $totalWeight += $support['weight'];
        }

        if ($totalWeight > 0) {
            // Confidence increases with more support, capped at 1.0
            $this->confidence = min(1.0, 0.5 + ($totalWeight * 0.1));
        }
    }

    /**
     * Invalidate this memory (mark as superseded).
     */
    public function invalidate(int $timestamp, string $supersededBy): void
    {
        $this->invalidatedAt = $timestamp;
        $this->supersededBy = $supersededBy;
    }

    /**
     * Mark this memory as superseding another.
     */
    public function markAsSuperseding(string $supersedes): void
    {
        $this->supersedes = $supersedes;
    }

    /**
     * Promote to a higher tier.
     */
    public function promoteTo(string $newTier): bool
    {
        $validTiers = ['peripheral', 'working', 'core'];
        $currentIndex = array_search($this->tier, $validTiers);
        $newIndex = array_search($newTier, $validTiers);

        if ($newIndex === false || $currentIndex === false) {
            return false;
        }

        // Can only promote upward
        if ($newIndex <= $currentIndex) {
            return false;
        }

        $this->tier = $newTier;
        return true;
    }

    /**
     * Demote to a lower tier.
     */
    public function demoteTo(string $newTier): bool
    {
        $validTiers = ['peripheral', 'working', 'core'];
        $currentIndex = array_search($this->tier, $validTiers);
        $newIndex = array_search($newTier, $validTiers);

        if ($newIndex === false || $currentIndex === false) {
            return false;
        }

        // Can only demote downward
        if ($newIndex >= $currentIndex) {
            return false;
        }

        $this->tier = $newTier;
        return true;
    }

    /**
     * Get the best text for search/indexing.
     */
    public function getSearchableText(): string
    {
        if (!empty($this->abstract)) {
            return $this->abstract;
        }
        if (!empty($this->overview)) {
            return $this->overview;
        }
        return $this->content;
    }

    /**
     * Get the best text for context injection.
     */
    public function getContextText(): string
    {
        if (!empty($this->overview)) {
            return $this->overview;
        }
        if (!empty($this->abstract)) {
            return $this->abstract;
        }
        return $this->content;
    }

    /**
     * Clone with modifications.
     */
    public function with(array $changes): self
    {
        $new = clone $this;
        foreach ($changes as $key => $value) {
            if (property_exists($new, $key)) {
                $new->{$key} = $value;
            }
        }
        return $new;
    }
}
