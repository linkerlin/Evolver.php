<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\DecayEngine;
use Evolver\DecayScore;
use Evolver\SimpleDecayableMemory;
use Evolver\TierManager;
use Evolver\TierTransition;
use PHPUnit\Framework\TestCase;

/**
 * TierManager tests - three-tier promotion/demotion logic.
 */
final class TierManagerTest extends TestCase
{
    private TierManager $manager;
    private DecayEngine $engine;

    protected function setUp(): void
    {
        $this->manager = new TierManager();
        $this->engine = new DecayEngine();
    }

    // -------------------------------------------------------------------------
    // Promotion: Peripheral → Working
    // -------------------------------------------------------------------------

    public function testPromotePeripheralToWorkingWhenAccessThresholdMet(): void
    {
        $now = (int)(microtime(true) * 1000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-1',
            'tier' => 'peripheral',
            'importance' => 0.6,
            'confidence' => 0.8,
            'accessCount' => 5, // >= workingAccessThreshold (3)
            'createdAt' => $now - 86400000,
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        $this->assertNotNull($transition);
        $this->assertSame('peripheral', $transition->fromTier);
        $this->assertSame('working', $transition->toTier);
        $this->assertTrue($transition->isPromotion());
    }

    public function testNoPromotionWhenAccessCountTooLow(): void
    {
        $now = (int)(microtime(true) * 1000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-2',
            'tier' => 'peripheral',
            'accessCount' => 1, // < workingAccessThreshold (3)
            'createdAt' => $now,
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        $this->assertNull($transition);
    }

    // -------------------------------------------------------------------------
    // Promotion: Working → Core
    // -------------------------------------------------------------------------

    public function testPromoteWorkingToCoreWhenAllThresholdsMet(): void
    {
        $now = (int)(microtime(true) * 1000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-3',
            'tier' => 'working',
            'importance' => 0.9, // >= coreImportanceThreshold (0.8)
            'confidence' => 0.9,
            'accessCount' => 15, // >= coreAccessThreshold (10)
            'createdAt' => $now - (30 * 86400000),
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        $this->assertNotNull($transition);
        $this->assertSame('working', $transition->fromTier);
        $this->assertSame('core', $transition->toTier);
        $this->assertTrue($transition->isPromotion());
    }

    public function testNoPromotionToCoreWhenImportanceTooLow(): void
    {
        $now = (int)(microtime(true) * 1000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-4',
            'tier' => 'working',
            'importance' => 0.5, // < coreImportanceThreshold (0.8)
            'confidence' => 0.9,
            'accessCount' => 15,
            'createdAt' => $now - (30 * 86400000),
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        // Should NOT be promoted to core (importance too low)
        if ($transition !== null) {
            // If there's a transition, it must be demotion, not promotion to core
            $this->assertTrue($transition->isDemotion());
        } else {
            // No transition is also acceptable
            $this->assertNull($transition);
        }
    }

    // -------------------------------------------------------------------------
    // Demotion: Working → Peripheral
    // -------------------------------------------------------------------------

    public function testDemoteWorkingToPeripheralWhenLowScoreAndOld(): void
    {
        $now = (int)(microtime(true) * 1000);
        $oldTimestamp = $now - (60 * 86400000); // 60 days ago

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-5',
            'tier' => 'working',
            'importance' => 0.2,
            'confidence' => 0.3,
            'accessCount' => 1,
            'createdAt' => $oldTimestamp,
            'lastAccessedAt' => $oldTimestamp,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        $this->assertNotNull($transition);
        $this->assertSame('working', $transition->fromTier);
        $this->assertSame('peripheral', $transition->toTier);
        $this->assertTrue($transition->isDemotion());
    }

    public function testNoDemotionWhenMemoryIsFresh(): void
    {
        $now = (int)(microtime(true) * 1000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'mem-6',
            'tier' => 'working',
            'importance' => 0.5,
            'confidence' => 0.7,
            'accessCount' => 1,
            'createdAt' => $now, // Fresh
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        // Fresh memory should not be demoted
        $this->assertNull($transition);
    }

    // -------------------------------------------------------------------------
    // Demotion: Core → Working
    // -------------------------------------------------------------------------

    public function testCoreMemoryRarelyDemoted(): void
    {
        $now = (int)(microtime(true) * 1000);
        $recentTimestamp = $now - (30 * 86400000);

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'core-mem',
            'tier' => 'core',
            'importance' => 0.9,
            'confidence' => 0.9,
            'accessCount' => 10,
            'createdAt' => $recentTimestamp,
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);
        $transition = $this->manager->evaluate($memory, $score, $now);

        // Core memory with recent access should not be demoted
        $this->assertNull($transition);
    }

    public function testCoreMemoryDemotedUnderExtremeConditions(): void
    {
        $now = (int)(microtime(true) * 1000);
        $veryOldTimestamp = $now - (365 * 86400000); // 365 days (1 year)

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'neglected-core',
            'tier' => 'core',
            'importance' => 0.1, // Very low importance
            'confidence' => 0.1, // Very low confidence
            'accessCount' => 1, // Very low access
            'createdAt' => $veryOldTimestamp,
            'lastAccessedAt' => $veryOldTimestamp,
        ]);

        $score = $this->engine->score($memory, $now);

        // Verify the composite score is indeed very low
        $this->assertLessThan(0.2, $score->composite, 'Composite should be below 0.2 for demotion');

        $transition = $this->manager->evaluate($memory, $score, $now);

        // Very old, low importance, low access core memory can be demoted
        $this->assertNotNull($transition);
        $this->assertSame('core', $transition->fromTier);
        $this->assertSame('working', $transition->toTier);
        $this->assertTrue($transition->isDemotion());
    }

    // -------------------------------------------------------------------------
    // TierTransition
    // -------------------------------------------------------------------------

    public function testTierTransitionIsPromotion(): void
    {
        $transition = new TierTransition(
            memoryId: 'test',
            fromTier: 'peripheral',
            toTier: 'working',
            reason: 'test',
            timestamp: time() * 1000,
        );

        $this->assertTrue($transition->isPromotion());
        $this->assertFalse($transition->isDemotion());
    }

    public function testTierTransitionIsDemotion(): void
    {
        $transition = new TierTransition(
            memoryId: 'test',
            fromTier: 'working',
            toTier: 'peripheral',
            reason: 'test',
            timestamp: time() * 1000,
        );

        $this->assertTrue($transition->isDemotion());
        $this->assertFalse($transition->isPromotion());
    }

    public function testTierTransitionToArray(): void
    {
        $timestamp = time() * 1000;
        $transition = new TierTransition(
            memoryId: 'mem-123',
            fromTier: 'working',
            toTier: 'core',
            reason: 'High value memory',
            timestamp: $timestamp,
        );

        $array = $transition->toArray();

        $this->assertSame('mem-123', $array['memoryId']);
        $this->assertSame('working', $array['fromTier']);
        $this->assertSame('core', $array['toTier']);
        $this->assertSame('High value memory', $array['reason']);
        $this->assertSame($timestamp, $array['timestamp']);
        $this->assertSame('promotion', $array['type']);
    }

    // -------------------------------------------------------------------------
    // Initial Tier Recommendation
    // -------------------------------------------------------------------------

    public function testRecommendInitialTierForHighImportance(): void
    {
        $this->assertSame('core', $this->manager->recommendInitialTier(0.95, 0.95));
    }

    public function testRecommendInitialTierForProfileCategory(): void
    {
        $this->assertSame('core', $this->manager->recommendInitialTier(0.8, 0.7, 'profile'));
    }

    public function testRecommendInitialTierForMediumImportance(): void
    {
        $this->assertSame('working', $this->manager->recommendInitialTier(0.5, 0.7));
        $this->assertSame('working', $this->manager->recommendInitialTier(0.7, 0.5));
    }

    public function testRecommendInitialTierForLowImportance(): void
    {
        $this->assertSame('peripheral', $this->manager->recommendInitialTier(0.2, 0.5));
        $this->assertSame('peripheral', $this->manager->recommendInitialTier(0.3, 0.3));
    }

    // -------------------------------------------------------------------------
    // Tier Statistics
    // -------------------------------------------------------------------------

    public function testGetTierStats(): void
    {
        $memories = [
            SimpleDecayableMemory::fromArray(['id' => 'c1', 'tier' => 'core']),
            SimpleDecayableMemory::fromArray(['id' => 'c2', 'tier' => 'core']),
            SimpleDecayableMemory::fromArray(['id' => 'w1', 'tier' => 'working']),
            SimpleDecayableMemory::fromArray(['id' => 'w2', 'tier' => 'working']),
            SimpleDecayableMemory::fromArray(['id' => 'w3', 'tier' => 'working']),
            SimpleDecayableMemory::fromArray(['id' => 'p1', 'tier' => 'peripheral']),
        ];

        $stats = $this->manager->getTierStats($memories);

        $this->assertSame(6, $stats['total']);
        $this->assertSame(2, $stats['core']);
        $this->assertSame(3, $stats['working']);
        $this->assertSame(1, $stats['peripheral']);
        $this->assertEqualsWithDelta(33.33, $stats['corePercent'], 0.1);
        $this->assertEqualsWithDelta(50.0, $stats['workingPercent'], 0.1);
        $this->assertEqualsWithDelta(16.67, $stats['peripheralPercent'], 0.1);
    }

    public function testGetTierStatsEmpty(): void
    {
        $stats = $this->manager->getTierStats([]);

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['core']);
        $this->assertSame(0, $stats['working']);
        $this->assertSame(0, $stats['peripheral']);
        $this->assertEquals(0.0, $stats['corePercent']);
    }

    // -------------------------------------------------------------------------
    // Evaluate Many
    // -------------------------------------------------------------------------

    public function testEvaluateMany(): void
    {
        $now = (int)(microtime(true) * 1000);
        $oldTimestamp = $now - (90 * 86400000);

        $memoriesWithScores = [
            [
                'memory' => SimpleDecayableMemory::fromArray([
                    'id' => 'promote-me',
                    'tier' => 'peripheral',
                    'accessCount' => 5,
                    'importance' => 0.7,
                    'createdAt' => $now - 86400000,
                    'lastAccessedAt' => $now,
                ]),
                'score' => null, // Will be calculated
            ],
            [
                'memory' => SimpleDecayableMemory::fromArray([
                    'id' => 'demote-me',
                    'tier' => 'working',
                    'accessCount' => 1,
                    'importance' => 0.2,
                    'confidence' => 0.3,
                    'createdAt' => $oldTimestamp,
                    'lastAccessedAt' => $oldTimestamp,
                ]),
                'score' => null,
            ],
            [
                'memory' => SimpleDecayableMemory::fromArray([
                    'id' => 'stable',
                    'tier' => 'working',
                    'accessCount' => 5,
                    'importance' => 0.6,
                    'createdAt' => $now,
                    'lastAccessedAt' => $now,
                ]),
                'score' => null,
            ],
        ];

        // Calculate scores
        foreach ($memoriesWithScores as &$item) {
            $item['score'] = $this->engine->score($item['memory'], $now);
        }

        $transitions = $this->manager->evaluateMany($memoriesWithScores, $now);

        // Should have at least 2 transitions (promote peripheral, demote working)
        $this->assertGreaterThanOrEqual(2, count($transitions));

        $transitionIds = array_map(fn($t) => $t->memoryId, $transitions);
        $this->assertContains('promote-me', $transitionIds);
        $this->assertContains('demote-me', $transitionIds);
    }

    // -------------------------------------------------------------------------
    // Configuration Getters
    // -------------------------------------------------------------------------

    public function testGetConfigurationValues(): void
    {
        $manager = new TierManager(
            coreAccessThreshold: 20,
            coreCompositeThreshold: 0.8,
            workingAccessThreshold: 5,
            workingCompositeThreshold: 0.5,
        );

        $this->assertSame(20, $manager->getCoreAccessThreshold());
        $this->assertSame(0.8, $manager->getCoreCompositeThreshold());
        $this->assertSame(5, $manager->getWorkingAccessThreshold());
        $this->assertSame(0.5, $manager->getWorkingCompositeThreshold());
    }
}
