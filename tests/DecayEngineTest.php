<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\DecayEngine;
use Evolver\DecayScore;
use Evolver\SimpleDecayableMemory;
use PHPUnit\Framework\TestCase;

/**
 * DecayEngine tests - Weibull stretched exponential decay.
 */
final class DecayEngineTest extends TestCase
{
    private DecayEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DecayEngine();
    }

    // -------------------------------------------------------------------------
    // Basic Scoring
    // -------------------------------------------------------------------------

    public function testScoreReturnsDecayScore(): void
    {
        $memory = SimpleDecayableMemory::fromArray(['id' => 'test-1']);
        $now = (int)(microtime(true) * 1000);

        $score = $this->engine->score($memory, $now);

        $this->assertInstanceOf(DecayScore::class, $score);
        $this->assertSame('test-1', $score->memoryId);
        $this->assertGreaterThanOrEqual(0, $score->recency);
        $this->assertGreaterThanOrEqual(0, $score->frequency);
        $this->assertGreaterThanOrEqual(0, $score->intrinsic);
        $this->assertGreaterThanOrEqual(0, $score->composite);
    }

    public function testScoreComponentsAreNormalized(): void
    {
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'test-2',
            'importance' => 1.0,
            'confidence' => 1.0,
            'accessCount' => 100,
        ]);
        $now = (int)(microtime(true) * 1000);

        $score = $this->engine->score($memory, $now);

        // All components should be <= 1
        $this->assertLessThanOrEqual(1.0, $score->recency);
        $this->assertLessThanOrEqual(1.0, $score->frequency);
        $this->assertLessThanOrEqual(1.0, $score->intrinsic);
        $this->assertLessThanOrEqual(1.0, $score->composite);
    }

    public function testCompositeScoreIsWeightedSum(): void
    {
        $memory = SimpleDecayableMemory::fromArray(['id' => 'test-3']);
        $now = (int)(microtime(true) * 1000);

        $score = $this->engine->score($memory, $now);

        $expected = (0.4 * $score->recency) + (0.3 * $score->frequency) + (0.3 * $score->intrinsic);
        $this->assertEqualsWithDelta($expected, $score->composite, 0.0001);
    }

    // -------------------------------------------------------------------------
    // Recency Decay
    // -------------------------------------------------------------------------

    public function testFreshMemoryHasHighRecency(): void
    {
        $now = (int)(microtime(true) * 1000);
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'fresh',
            'createdAt' => $now,
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($memory, $now);

        // Fresh memory should have recency close to 1
        $this->assertGreaterThan(0.99, $score->recency);
    }

    public function testOldMemoryHasLowerRecency(): void
    {
        $now = (int)(microtime(true) * 1000);
        $thirtyDaysAgo = $now - (30 * 86400000);

        $freshMemory = SimpleDecayableMemory::fromArray([
            'id' => 'fresh',
            'createdAt' => $now,
            'lastAccessedAt' => $now,
        ]);

        $oldMemory = SimpleDecayableMemory::fromArray([
            'id' => 'old',
            'createdAt' => $thirtyDaysAgo,
            'lastAccessedAt' => $thirtyDaysAgo,
        ]);

        $freshScore = $this->engine->score($freshMemory, $now);
        $oldScore = $this->engine->score($oldMemory, $now);

        $this->assertGreaterThan($oldScore->recency, $freshScore->recency);
    }

    public function testHighImportanceSlowsDecay(): void
    {
        $now = (int)(microtime(true) * 1000);
        $thirtyDaysAgo = $now - (30 * 86400000);

        $lowImportance = SimpleDecayableMemory::fromArray([
            'id' => 'low',
            'importance' => 0.1,
            'createdAt' => $thirtyDaysAgo,
            'lastAccessedAt' => $thirtyDaysAgo,
        ]);

        $highImportance = SimpleDecayableMemory::fromArray([
            'id' => 'high',
            'importance' => 0.9,
            'createdAt' => $thirtyDaysAgo,
            'lastAccessedAt' => $thirtyDaysAgo,
        ]);

        $lowScore = $this->engine->score($lowImportance, $now);
        $highScore = $this->engine->score($highImportance, $now);

        // High importance memory should have higher recency (slower decay)
        $this->assertGreaterThan($lowScore->recency, $highScore->recency);
    }

    public function testCoreTierDecaysSlower(): void
    {
        $now = (int)(microtime(true) * 1000);
        $thirtyDaysAgo = $now - (30 * 86400000);

        $peripheral = SimpleDecayableMemory::fromArray([
            'id' => 'peripheral',
            'tier' => 'peripheral',
            'importance' => 0.5,
            'createdAt' => $thirtyDaysAgo,
            'lastAccessedAt' => $thirtyDaysAgo,
        ]);

        $core = SimpleDecayableMemory::fromArray([
            'id' => 'core',
            'tier' => 'core',
            'importance' => 0.5,
            'createdAt' => $thirtyDaysAgo,
            'lastAccessedAt' => $thirtyDaysAgo,
        ]);

        $peripheralScore = $this->engine->score($peripheral, $now);
        $coreScore = $this->engine->score($core, $now);

        // Core should have higher recency due to lower beta
        $this->assertGreaterThan($peripheralScore->recency, $coreScore->recency);
    }

    // -------------------------------------------------------------------------
    // Frequency Score
    // -------------------------------------------------------------------------

    public function testZeroAccessHasLowFrequency(): void
    {
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'no-access',
            'accessCount' => 0,
        ]);

        $score = $this->engine->score($memory);

        $this->assertEquals(0.0, $score->frequency);
    }

    public function testSingleAccessHasLowFrequency(): void
    {
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'single-access',
            'accessCount' => 1,
        ]);

        $score = $this->engine->score($memory);

        // 1 - exp(-1/5) ≈ 0.181
        $this->assertGreaterThan(0, $score->frequency);
        $this->assertLessThan(0.3, $score->frequency);
    }

    public function testManyAccessesSaturatesFrequency(): void
    {
        $now = (int)(microtime(true) * 1000);
        $manyAccess = SimpleDecayableMemory::fromArray([
            'id' => 'many',
            'accessCount' => 100,
            'createdAt' => $now - 86400000, // 1 day ago
            'lastAccessedAt' => $now,
        ]);

        $score = $this->engine->score($manyAccess);

        // With 100 accesses, frequency should be close to 1
        $this->assertGreaterThan(0.8, $score->frequency);
    }

    public function testRecentAccessPatternBoostsFrequency(): void
    {
        $now = (int)(microtime(true) * 1000);

        // Frequent recent access (1 day span)
        $recentAccess = SimpleDecayableMemory::fromArray([
            'id' => 'recent',
            'accessCount' => 10,
            'createdAt' => $now - 86400000, // 1 day ago
            'lastAccessedAt' => $now,
        ]);

        // Sparse old access (100 day span)
        $sparseAccess = SimpleDecayableMemory::fromArray([
            'id' => 'sparse',
            'accessCount' => 10,
            'createdAt' => $now - (100 * 86400000), // 100 days ago
            'lastAccessedAt' => $now - (90 * 86400000), // 90 days ago
        ]);

        $recentScore = $this->engine->score($recentAccess, $now);
        $sparseScore = $this->engine->score($sparseAccess, $now);

        $this->assertGreaterThan($sparseScore->frequency, $recentScore->frequency);
    }

    // -------------------------------------------------------------------------
    // Intrinsic Score
    // -------------------------------------------------------------------------

    public function testIntrinsicIsImportanceTimesConfidence(): void
    {
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'intrinsic-test',
            'importance' => 0.8,
            'confidence' => 0.6,
        ]);

        $score = $this->engine->score($memory);

        $this->assertEqualsWithDelta(0.48, $score->intrinsic, 0.0001);
    }

    public function testHighImportanceConfidenceHasHighIntrinsic(): void
    {
        $highMemory = SimpleDecayableMemory::fromArray([
            'id' => 'high',
            'importance' => 1.0,
            'confidence' => 1.0,
        ]);

        $score = $this->engine->score($highMemory);

        $this->assertEqualsWithDelta(1.0, $score->intrinsic, 0.0001);
    }

    public function testLowImportanceOrConfidenceHasLowIntrinsic(): void
    {
        $lowMemory = SimpleDecayableMemory::fromArray([
            'id' => 'low',
            'importance' => 0.2,
            'confidence' => 0.9,
        ]);

        $score = $this->engine->score($lowMemory);

        $this->assertEqualsWithDelta(0.18, $score->intrinsic, 0.0001);
    }

    // -------------------------------------------------------------------------
    // Tier Floor
    // -------------------------------------------------------------------------

    public function testGetTierFloor(): void
    {
        $this->assertSame(0.9, $this->engine->getTierFloor('core'));
        $this->assertSame(0.7, $this->engine->getTierFloor('working'));
        $this->assertSame(0.5, $this->engine->getTierFloor('peripheral'));
        $this->assertSame(0.5, $this->engine->getTierFloor('unknown'));
    }

    public function testApplyTierFloor(): void
    {
        $memory = SimpleDecayableMemory::fromArray(['id' => 'core', 'tier' => 'core']);
        $score = $this->engine->score($memory);

        $floored = $this->engine->applyTierFloor($score, 'core');

        // Core tier floor is 0.9, so result should be at least 0.9
        $this->assertGreaterThanOrEqual(0.9, $floored);
    }

    // -------------------------------------------------------------------------
    // Health Check
    // -------------------------------------------------------------------------

    public function testIsHealthyForFreshMemory(): void
    {
        $now = (int)(microtime(true) * 1000);
        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'fresh',
            'createdAt' => $now,
            'lastAccessedAt' => $now,
            'importance' => 0.8,
            'confidence' => 0.9,
        ]);

        $this->assertTrue($this->engine->isHealthy($memory, 0.3, $now));
    }

    public function testIsNotHealthyForOldLowImportanceMemory(): void
    {
        $now = (int)(microtime(true) * 1000);
        $oldTimestamp = $now - (120 * 86400000); // 120 days ago

        $memory = SimpleDecayableMemory::fromArray([
            'id' => 'old',
            'tier' => 'peripheral',
            'importance' => 0.1,
            'confidence' => 0.3,
            'createdAt' => $oldTimestamp,
            'lastAccessedAt' => $oldTimestamp,
            'accessCount' => 0,
        ]);

        // Check without tier floor protection
        $score = $this->engine->score($memory, $now);
        // The raw composite should be low (below 0.3)
        $this->assertLessThan(0.3, $score->composite, 'Old low-importance memory should have low composite score');
    }

    // -------------------------------------------------------------------------
    // Sorting
    // -------------------------------------------------------------------------

    public function testSortByDecay(): void
    {
        $now = (int)(microtime(true) * 1000);

        // Create memories with clearly different decay characteristics
        $memories = [
            // Very old, low importance, peripheral tier - should decay most
            SimpleDecayableMemory::fromArray([
                'id' => 'old',
                'tier' => 'peripheral',
                'importance' => 0.1,
                'confidence' => 0.2,
                'createdAt' => $now - (90 * 86400000),
                'lastAccessedAt' => $now - (90 * 86400000),
            ]),
            // Fresh, high importance, working tier - should decay least
            SimpleDecayableMemory::fromArray([
                'id' => 'fresh',
                'tier' => 'working',
                'importance' => 0.9,
                'confidence' => 0.9,
                'createdAt' => $now,
                'lastAccessedAt' => $now,
            ]),
            // Medium age, medium importance, working tier
            SimpleDecayableMemory::fromArray([
                'id' => 'medium',
                'tier' => 'working',
                'importance' => 0.5,
                'confidence' => 0.7,
                'createdAt' => $now - (7 * 86400000),
                'lastAccessedAt' => $now,
            ]),
        ];

        $sorted = $this->engine->sortByDecay($memories, $now);

        // Verify fresh (working tier floor 0.7, high composite) comes first
        $this->assertSame('fresh', $sorted[0]->getId());
        // Verify old (peripheral tier floor 0.5, low composite) comes last
        $this->assertSame('old', $sorted[2]->getId());
    }

    // -------------------------------------------------------------------------
    // Pruning
    // -------------------------------------------------------------------------

    public function testGetPrunableFiltersLowScoreMemories(): void
    {
        $now = (int)(microtime(true) * 1000);
        $oldTimestamp = $now - (120 * 86400000);

        $memories = [
            // Should be prunable: old, low importance, peripheral
            SimpleDecayableMemory::fromArray([
                'id' => 'prunable',
                'tier' => 'peripheral',
                'importance' => 0.1,
                'confidence' => 0.2,
                'createdAt' => $oldTimestamp,
                'lastAccessedAt' => $oldTimestamp,
                'accessCount' => 0,
            ]),
            // Should NOT be prunable: fresh
            SimpleDecayableMemory::fromArray([
                'id' => 'fresh',
                'tier' => 'working',
                'importance' => 0.8,
                'createdAt' => $now,
                'lastAccessedAt' => $now,
            ]),
        ];

        $prunable = $this->engine->getPrunable($memories, 0.15, $now);

        $this->assertCount(1, $prunable);
        $prunableIds = array_map(fn($m) => $m->getId(), $prunable);
        $this->assertContains('prunable', $prunableIds);
    }

    public function testCoreMemoriesAreNotPrunable(): void
    {
        $now = (int)(microtime(true) * 1000);
        $oldTimestamp = $now - (365 * 86400000); // 1 year ago

        $memories = [
            // Core memory, even very old, should not be prunable
            SimpleDecayableMemory::fromArray([
                'id' => 'core-old',
                'tier' => 'core',
                'importance' => 0.9,
                'confidence' => 0.9,
                'createdAt' => $oldTimestamp,
                'lastAccessedAt' => $oldTimestamp,
                'accessCount' => 0,
            ]),
        ];

        $prunable = $this->engine->getPrunable($memories, 0.15, $now);

        // Core tier floor is 0.9, which is > 0.15, so not prunable
        $this->assertCount(0, $prunable);
    }

    // -------------------------------------------------------------------------
    // Score Many
    // -------------------------------------------------------------------------

    public function testScoreMany(): void
    {
        $memories = [
            SimpleDecayableMemory::fromArray(['id' => 'mem-1']),
            SimpleDecayableMemory::fromArray(['id' => 'mem-2']),
            SimpleDecayableMemory::fromArray(['id' => 'mem-3']),
        ];

        $scores = $this->engine->scoreMany($memories);

        $this->assertCount(3, $scores);
        $this->assertSame('mem-1', $scores[0]->memoryId);
        $this->assertSame('mem-2', $scores[1]->memoryId);
        $this->assertSame('mem-3', $scores[2]->memoryId);
    }

    // -------------------------------------------------------------------------
    // Configuration Getters
    // -------------------------------------------------------------------------

    public function testGetConfigurationValues(): void
    {
        $engine = new DecayEngine(
            recencyHalfLifeDays: 45.0,
            recencyWeight: 0.5,
            frequencyWeight: 0.3,
            intrinsicWeight: 0.2,
        );

        $this->assertSame(45.0, $engine->getRecencyHalfLifeDays());
        $this->assertSame(0.5, $engine->getRecencyWeight());
        $this->assertSame(0.3, $engine->getFrequencyWeight());
        $this->assertSame(0.2, $engine->getIntrinsicWeight());
    }
}
