<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Simple implementation of DecayableMemory for testing and basic use cases.
 */
final class SimpleDecayableMemory implements DecayableMemory
{
    public function __construct(
        private string $id,
        private string $tier = 'working',
        private float $importance = 0.5,
        private float $confidence = 0.7,
        private int $createdAt = 0,
        private int $lastAccessedAt = 0,
        private int $accessCount = 0,
    ) {
        if ($createdAt === 0) {
            $this->createdAt = (int)(microtime(true) * 1000);
        }
        if ($lastAccessedAt === 0) {
            $this->lastAccessedAt = $this->createdAt;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function getImportance(): float
    {
        return $this->importance;
    }

    public function getConfidence(): float
    {
        return $this->confidence;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getLastAccessedAt(): int
    {
        return $this->lastAccessedAt;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? uniqid('mem-', true),
            tier: $data['tier'] ?? 'working',
            importance: $data['importance'] ?? 0.5,
            confidence: $data['confidence'] ?? 0.7,
            createdAt: $data['createdAt'] ?? 0,
            lastAccessedAt: $data['lastAccessedAt'] ?? 0,
            accessCount: $data['accessCount'] ?? 0,
        );
    }

    /**
     * Create a copy with modifications.
     */
    public function with(array $changes): self
    {
        return new self(
            id: $changes['id'] ?? $this->id,
            tier: $changes['tier'] ?? $this->tier,
            importance: $changes['importance'] ?? $this->importance,
            confidence: $changes['confidence'] ?? $this->confidence,
            createdAt: $changes['createdAt'] ?? $this->createdAt,
            lastAccessedAt: $changes['lastAccessedAt'] ?? $this->lastAccessedAt,
            accessCount: $changes['accessCount'] ?? $this->accessCount,
        );
    }
}
