<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Weibull stretched exponential decay engine for long-term memory lifecycle management.
 *
 * Implements the decay model from memory-lancedb-pro:
 * - Composite score = recencyWeight × recency + frequencyWeight × frequency + intrinsicWeight × intrinsic
 * - Recency uses Weibull decay with importance-modulated half-life
 * - Frequency uses logarithmic saturation curve
 * - Intrinsic = importance × confidence
 */
final class DecayEngine
{
    private const MS_PER_DAY = 86400000;

    public function __construct(
        private float $recencyHalfLifeDays = 30.0,
        private float $recencyWeight = 0.4,
        private float $frequencyWeight = 0.3,
        private float $intrinsicWeight = 0.3,
        private float $betaCore = 0.8,
        private float $betaWorking = 1.0,
        private float $betaPeripheral = 1.3,
        private float $importanceMu = 1.5,
    ) {}

    /**
     * Calculate composite decay score for a memory.
     *
     * @param DecayableMemory $memory The memory to score
     * @param int|null $now Current timestamp in milliseconds (defaults to now)
     */
    public function score(DecayableMemory $memory, ?int $now = null): DecayScore
    {
        $now = $now ?? (int)(microtime(true) * 1000);

        $recency = $this->computeRecency($memory, $now);
        $frequency = $this->computeFrequency($memory);
        $intrinsic = $this->computeIntrinsic($memory);

        $composite = ($this->recencyWeight * $recency)
                   + ($this->frequencyWeight * $frequency)
                   + ($this->intrinsicWeight * $intrinsic);

        return new DecayScore(
            memoryId: $memory->getId(),
            recency: $recency,
            frequency: $frequency,
            intrinsic: $intrinsic,
            composite: $composite,
        );
    }

    /**
     * Score multiple memories at once.
     *
     * @param DecayableMemory[] $memories
     * @param int|null $now
     * @return DecayScore[]
     */
    public function scoreMany(array $memories, ?int $now = null): array
    {
        $now = $now ?? (int)(microtime(true) * 1000);
        return array_map(fn($m) => $this->score($m, $now), $memories);
    }

    /**
     * Compute recency using Weibull stretched exponential decay.
     *
     * recency = exp(-λ × daysSince^β)
     * λ = ln(2) / effectiveHalfLife
     * effectiveHalfLife = baseHalfLife × exp(μ × importance)
     *
     * Higher importance → longer half-life → slower decay
     */
    private function computeRecency(DecayableMemory $memory, int $now): float
    {
        // Use last accessed time if there have been accesses, otherwise creation time
        $lastActive = $memory->getAccessCount() > 0
            ? $memory->getLastAccessedAt()
            : $memory->getCreatedAt();

        $daysSince = max(0, ($now - $lastActive) / self::MS_PER_DAY);

        // Importance-modulated half-life
        $effectiveHalfLife = $this->recencyHalfLifeDays * exp($this->importanceMu * $memory->getImportance());
        $lambda = M_LN2 / $effectiveHalfLife;

        // Tier-specific beta (decay shape parameter)
        $beta = match ($memory->getTier()) {
            'core' => $this->betaCore,         // Slower decay
            'peripheral' => $this->betaPeripheral, // Faster decay
            default => $this->betaWorking,
        };

        return exp(-$lambda * pow($daysSince, $beta));
    }

    /**
     * Compute frequency using logarithmic saturation curve.
     *
     * base = 1 - exp(-accessCount / 5)
     * With time-weighted access pattern bonus.
     */
    private function computeFrequency(DecayableMemory $memory): float
    {
        $accessCount = $memory->getAccessCount();

        // Base frequency score with saturation
        $base = 1 - exp(-$accessCount / 5);

        // Single access has no pattern to analyze
        if ($accessCount <= 1) {
            return $base;
        }

        // Calculate average gap between accesses
        $accessSpanDays = max(1, ($memory->getLastAccessedAt() - $memory->getCreatedAt()) / self::MS_PER_DAY);
        $avgGapDays = $accessSpanDays / max($accessCount - 1, 1);

        // Recent access pattern bonus (shorter gaps = more recent engagement)
        $recentnessBonus = exp(-$avgGapDays / 30);

        return $base * (0.5 + 0.5 * $recentnessBonus);
    }

    /**
     * Compute intrinsic value as importance × confidence.
     */
    private function computeIntrinsic(DecayableMemory $memory): float
    {
        return $memory->getImportance() * $memory->getConfidence();
    }

    /**
     * Get the decay floor for a tier.
     *
     * Memories cannot decay below their tier's floor.
     */
    public function getTierFloor(string $tier): float
    {
        return match ($tier) {
            'core' => 0.9,
            'working' => 0.7,
            'peripheral' => 0.5,
            default => 0.5,
        };
    }

    /**
     * Apply tier floor to a decay score.
     *
     * Returns max(tierFloor, composite) to ensure memories don't decay below their floor.
     */
    public function applyTierFloor(DecayScore $score, string $tier): float
    {
        return max($this->getTierFloor($tier), $score->composite);
    }

    /**
     * Check if a memory is "healthy" (above a threshold).
     */
    public function isHealthy(DecayableMemory $memory, float $threshold = 0.3, ?int $now = null): bool
    {
        $score = $this->score($memory, $now);
        $flooredScore = $this->applyTierFloor($score, $memory->getTier());
        return $flooredScore >= $threshold;
    }

    /**
     * Get memories sorted by decay score (highest first).
     *
     * @param DecayableMemory[] $memories
     * @return DecayableMemory[]
     */
    public function sortByDecay(array $memories, ?int $now = null): array
    {
        $now = $now ?? (int)(microtime(true) * 1000);

        $scored = [];
        foreach ($memories as $memory) {
            $score = $this->score($memory, $now);
            $flooredScore = $this->applyTierFloor($score, $memory->getTier());
            $scored[] = ['memory' => $memory, 'score' => $flooredScore];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_column($scored, 'memory');
    }

    /**
     * Filter memories that should be considered for pruning.
     *
     * Returns memories with composite score below threshold AND below tier floor.
     *
     * @param DecayableMemory[] $memories
     * @return DecayableMemory[]
     */
    public function getPrunable(array $memories, float $threshold = 0.15, ?int $now = null): array
    {
        $now = $now ?? (int)(microtime(true) * 1000);

        return array_filter($memories, function ($memory) use ($threshold, $now) {
            $score = $this->score($memory, $now);
            // Only prunable if below threshold AND not protected by tier floor
            return $score->composite < $threshold
                && $score->composite < $this->getTierFloor($memory->getTier());
        });
    }

    // -------------------------------------------------------------------------
    // Getters for configuration
    // -------------------------------------------------------------------------

    public function getRecencyHalfLifeDays(): float
    {
        return $this->recencyHalfLifeDays;
    }

    public function getRecencyWeight(): float
    {
        return $this->recencyWeight;
    }

    public function getFrequencyWeight(): float
    {
        return $this->frequencyWeight;
    }

    public function getIntrinsicWeight(): float
    {
        return $this->intrinsicWeight;
    }
}
