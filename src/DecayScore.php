<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Decay score result from DecayEngine.
 *
 * Contains individual component scores and composite score.
 */
final class DecayScore
{
    public function __construct(
        public readonly string $memoryId,
        public readonly float $recency,
        public readonly float $frequency,
        public readonly float $intrinsic,
        public readonly float $composite,
    ) {}

    public function toArray(): array
    {
        return [
            'memoryId' => $this->memoryId,
            'recency' => $this->recency,
            'frequency' => $this->frequency,
            'intrinsic' => $this->intrinsic,
            'composite' => $this->composite,
        ];
    }
}
