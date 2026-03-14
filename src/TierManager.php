<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Tier Manager for three-tier memory lifecycle management.
 *
 * Implements the Core/Working/Peripheral tier system from memory-lancedb-pro:
 * - Core: Identity-level facts, almost never forget (decay floor 0.9)
 * - Working: Active context, decays without reinforcement (decay floor 0.7)
 * - Peripheral: Low priority or aging memories (decay floor 0.5)
 */
final class TierManager
{
    private const MS_PER_DAY = 86400000;

    public function __construct(
        // Promotion thresholds
        private int $coreAccessThreshold = 10,
        private float $coreCompositeThreshold = 0.7,
        private float $coreImportanceThreshold = 0.8,
        private int $workingAccessThreshold = 3,
        private float $workingCompositeThreshold = 0.4,

        // Demotion thresholds
        private float $peripheralCompositeThreshold = 0.15,
        private int $peripheralAgeDays = 60,
        private float $workingDemotionCompositeThreshold = 0.25,
        private int $workingDemotionAgeDays = 30,
    ) {}

    /**
     * Evaluate whether a memory should change tiers.
     *
     * @param DecayableMemory $memory The memory to evaluate
     * @param DecayScore $score The current decay score
     * @param int|null $now Current timestamp in milliseconds
     * @return TierTransition|null Returns null if no transition needed
     */
    public function evaluate(DecayableMemory $memory, DecayScore $score, ?int $now = null): ?TierTransition
    {
        $now = $now ?? (int)(microtime(true) * 1000);
        $ageDays = ($now - $memory->getCreatedAt()) / self::MS_PER_DAY;

        return match ($memory->getTier()) {
            'peripheral' => $this->tryPromoteToWorking($memory, $score, $now),
            'working' => $this->tryPromoteToCore($memory, $score, $now)
                      ?? $this->tryDemoteToPeripheral($memory, $score, $ageDays, $now),
            'core' => $this->tryDemoteToWorking($memory, $score, $ageDays, $now),
            default => null,
        };
    }

    /**
     * Evaluate multiple memories and return all needed transitions.
     *
     * @param array $memoriesWithScores Array of ['memory' => DecayableMemory, 'score' => DecayScore]
     * @param int|null $now
     * @return TierTransition[]
     */
    public function evaluateMany(array $memoriesWithScores, ?int $now = null): array
    {
        $now = $now ?? (int)(microtime(true) * 1000);
        $transitions = [];

        foreach ($memoriesWithScores as $item) {
            $transition = $this->evaluate($item['memory'], $item['score'], $now);
            if ($transition !== null) {
                $transitions[] = $transition;
            }
        }

        return $transitions;
    }

    /**
     * Try to promote from peripheral to working.
     */
    private function tryPromoteToWorking(DecayableMemory $memory, DecayScore $score, int $now): ?TierTransition
    {
        if ($memory->getAccessCount() >= $this->workingAccessThreshold
            && $score->composite >= $this->workingCompositeThreshold) {
            return new TierTransition(
                memoryId: $memory->getId(),
                fromTier: 'peripheral',
                toTier: 'working',
                reason: sprintf(
                    'Access count (%d) >= %d, composite score (%.2f) >= %.2f',
                    $memory->getAccessCount(),
                    $this->workingAccessThreshold,
                    $score->composite,
                    $this->workingCompositeThreshold
                ),
                timestamp: $now,
            );
        }
        return null;
    }

    /**
     * Try to promote from working to core.
     */
    private function tryPromoteToCore(DecayableMemory $memory, DecayScore $score, int $now): ?TierTransition
    {
        if ($memory->getAccessCount() >= $this->coreAccessThreshold
            && $score->composite >= $this->coreCompositeThreshold
            && $memory->getImportance() >= $this->coreImportanceThreshold) {
            return new TierTransition(
                memoryId: $memory->getId(),
                fromTier: 'working',
                toTier: 'core',
                reason: sprintf(
                    'High access (%d), composite (%.2f), importance (%.2f)',
                    $memory->getAccessCount(),
                    $score->composite,
                    $memory->getImportance()
                ),
                timestamp: $now,
            );
        }
        return null;
    }

    /**
     * Try to demote from working to peripheral.
     */
    private function tryDemoteToPeripheral(DecayableMemory $memory, DecayScore $score, float $ageDays, int $now): ?TierTransition
    {
        // Demote if low composite AND old enough
        if ($score->composite < $this->workingDemotionCompositeThreshold
            && $ageDays >= $this->workingDemotionAgeDays) {
            return new TierTransition(
                memoryId: $memory->getId(),
                fromTier: 'working',
                toTier: 'peripheral',
                reason: sprintf(
                    'Low composite (%.2f < %.2f) and age (%.0f days >= %d days)',
                    $score->composite,
                    $this->workingDemotionCompositeThreshold,
                    $ageDays,
                    $this->workingDemotionAgeDays
                ),
                timestamp: $now,
            );
        }
        return null;
    }

    /**
     * Try to demote from core to working.
     *
     * Core memories are very stable, only demote under extreme conditions.
     */
    private function tryDemoteToWorking(DecayableMemory $memory, DecayScore $score, float $ageDays, int $now): ?TierTransition
    {
        // Core memories need very low score AND very old age to demote
        $coreDemotionThreshold = 0.2;
        $coreDemotionAgeDays = 180; // 6 months

        if ($score->composite < $coreDemotionThreshold
            && $ageDays >= $coreDemotionAgeDays
            && $memory->getAccessCount() < 3) {
            return new TierTransition(
                memoryId: $memory->getId(),
                fromTier: 'core',
                toTier: 'working',
                reason: sprintf(
                    'Very low composite (%.2f < %.2f), old age (%.0f days), low access (%d)',
                    $score->composite,
                    $coreDemotionThreshold,
                    $ageDays,
                    $memory->getAccessCount()
                ),
                timestamp: $now,
            );
        }
        return null;
    }

    /**
     * Get the recommended tier for a new memory based on its properties.
     */
    public function recommendInitialTier(float $importance, float $confidence, string $category = ''): string
    {
        // High importance + high confidence = core
        if ($importance >= 0.9 && $confidence >= 0.9) {
            return 'core';
        }

        // Profile category usually goes to core
        if ($category === 'profile' && $importance >= 0.7) {
            return 'core';
        }

        // Medium importance = working
        if ($importance >= 0.4) {
            return 'working';
        }

        // Low importance = peripheral
        return 'peripheral';
    }

    /**
     * Get tier statistics for a collection of memories.
     *
     * @param DecayableMemory[] $memories
     */
    public function getTierStats(array $memories): array
    {
        $stats = [
            'core' => 0,
            'working' => 0,
            'peripheral' => 0,
            'unknown' => 0,
            'total' => count($memories),
        ];

        foreach ($memories as $memory) {
            $tier = $memory->getTier();
            if (isset($stats[$tier])) {
                $stats[$tier]++;
            } else {
                $stats['unknown']++;
            }
        }

        $stats['corePercent'] = $stats['total'] > 0 ? ($stats['core'] / $stats['total']) * 100 : 0;
        $stats['workingPercent'] = $stats['total'] > 0 ? ($stats['working'] / $stats['total']) * 100 : 0;
        $stats['peripheralPercent'] = $stats['total'] > 0 ? ($stats['peripheral'] / $stats['total']) * 100 : 0;

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Getters for configuration
    // -------------------------------------------------------------------------

    public function getCoreAccessThreshold(): int
    {
        return $this->coreAccessThreshold;
    }

    public function getCoreCompositeThreshold(): float
    {
        return $this->coreCompositeThreshold;
    }

    public function getWorkingAccessThreshold(): int
    {
        return $this->workingAccessThreshold;
    }

    public function getWorkingCompositeThreshold(): float
    {
        return $this->workingCompositeThreshold;
    }
}
