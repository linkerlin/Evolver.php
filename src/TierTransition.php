<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Tier transition result from TierManager.
 */
final class TierTransition
{
    public function __construct(
        public readonly string $memoryId,
        public readonly string $fromTier,
        public readonly string $toTier,
        public readonly string $reason,
        public readonly int $timestamp,
    ) {}

    public function isPromotion(): bool
    {
        $order = ['peripheral' => 0, 'working' => 1, 'core' => 2];
        return ($order[$this->toTier] ?? 0) > ($order[$this->fromTier] ?? 0);
    }

    public function isDemotion(): bool
    {
        $order = ['peripheral' => 0, 'working' => 1, 'core' => 2];
        return ($order[$this->toTier] ?? 0) < ($order[$this->fromTier] ?? 0);
    }

    public function toArray(): array
    {
        return [
            'memoryId' => $this->memoryId,
            'fromTier' => $this->fromTier,
            'toTier' => $this->toTier,
            'reason' => $this->reason,
            'timestamp' => $this->timestamp,
            'type' => $this->isPromotion() ? 'promotion' : 'demotion',
        ];
    }
}
