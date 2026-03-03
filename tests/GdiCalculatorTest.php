<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\GdiCalculator;
use PHPUnit\Framework\TestCase;

final class GdiCalculatorTest extends TestCase
{
    private GdiCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new GdiCalculator();
    }

    // =========================================================================
    // Capsule GDI Tests
    // =========================================================================

    public function testComputeCapsuleGdiWithMinimalCapsule(): void
    {
        $capsule = [];
        $score = $this->calculator->computeCapsuleGdi($capsule);

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testComputeCapsuleGdiWithHighOutcome(): void
    {
        $capsule = [
            'outcome' => ['score' => 1.0],
            'confidence' => 1.0,
            'success_streak' => 10,
            'content' => 'some content',
            'blast_radius' => ['files' => 1, 'lines' => 10],
        ];

        $score = $this->calculator->computeCapsuleGdi($capsule);
        $this->assertGreaterThan(0.7, $score);
    }

    public function testComputeCapsuleGdiWithLowOutcome(): void
    {
        $capsule = [
            'outcome' => ['score' => 0.2],
            'confidence' => 0.3,
        ];

        $score = $this->calculator->computeCapsuleGdi($capsule);
        $this->assertLessThan(0.5, $score);
    }

    public function testComputeCapsuleGdiRewardsPrecision(): void
    {
        $precise = [
            'outcome' => ['score' => 0.5],
            'confidence' => 0.5,
            'blast_radius' => ['files' => 2, 'lines' => 50],
        ];

        $imprecise = [
            'outcome' => ['score' => 0.5],
            'confidence' => 0.5,
            'blast_radius' => ['files' => 20, 'lines' => 500],
        ];

        $preciseScore = $this->calculator->computeCapsuleGdi($precise);
        $impreciseScore = $this->calculator->computeCapsuleGdi($imprecise);

        $this->assertGreaterThan($impreciseScore, $preciseScore);
    }

    public function testComputeCapsuleGdiRewardsContent(): void
    {
        $withContent = [
            'outcome' => ['score' => 0.5],
            'confidence' => 0.5,
            'content' => 'detailed content',
        ];

        $withoutContent = [
            'outcome' => ['score' => 0.5],
            'confidence' => 0.5,
        ];

        $withScore = $this->calculator->computeCapsuleGdi($withContent);
        $withoutScore = $this->calculator->computeCapsuleGdi($withoutContent);

        $this->assertGreaterThan($withoutScore, $withScore);
    }

    // =========================================================================
    // Gene GDI Tests
    // =========================================================================

    public function testComputeGeneGdiWithMinimalGene(): void
    {
        $score = $this->calculator->computeGeneGdi([]);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testComputeGeneGdiWithUsageCount(): void
    {
        $geneWithUsage = ['usage_count' => 10];
        $geneWithoutUsage = [];

        $scoreWith = $this->calculator->computeGeneGdi($geneWithUsage);
        $scoreWithout = $this->calculator->computeGeneGdi($geneWithoutUsage);

        $this->assertGreaterThan($scoreWithout, $scoreWith);
    }

    public function testComputeGeneGdiWithConstraints(): void
    {
        $geneWithConstraints = ['constraints' => ['max_files' => 10]];
        $geneWithoutConstraints = [];

        $scoreWith = $this->calculator->computeGeneGdi($geneWithConstraints);
        $scoreWithout = $this->calculator->computeGeneGdi($geneWithoutConstraints);

        $this->assertGreaterThan($scoreWithout, $scoreWith);
    }

    public function testComputeGeneGdiWithCapsules(): void
    {
        $gene = ['id' => 'test_gene'];
        $successfulCapsules = [
            ['outcome' => ['status' => 'success'], 'success_streak' => 5],
            ['outcome' => ['status' => 'success'], 'success_streak' => 3],
        ];

        $failedCapsules = [
            ['outcome' => ['status' => 'failed'], 'success_streak' => 0],
        ];

        $scoreSuccess = $this->calculator->computeGeneGdi($gene, $successfulCapsules);
        $scoreFailed = $this->calculator->computeGeneGdi($gene, $failedCapsules);

        $this->assertGreaterThan($scoreFailed, $scoreSuccess);
    }

    // =========================================================================
    // Sorting Tests
    // =========================================================================

    public function testSortCapsulesByGdiDescending(): void
    {
        $capsules = [
            ['id' => 'low', 'outcome' => ['score' => 0.2], 'confidence' => 0.3],
            ['id' => 'high', 'outcome' => ['score' => 0.9], 'confidence' => 0.95],
            ['id' => 'mid', 'outcome' => ['score' => 0.5], 'confidence' => 0.5],
        ];

        $sorted = $this->calculator->sortCapsulesByGdi($capsules, true);

        $this->assertSame('high', $sorted[0]['id']);
        $this->assertSame('mid', $sorted[1]['id']);
        $this->assertSame('low', $sorted[2]['id']);
    }

    public function testSortCapsulesByGdiAscending(): void
    {
        $capsules = [
            ['id' => 'low', 'outcome' => ['score' => 0.2], 'confidence' => 0.3],
            ['id' => 'high', 'outcome' => ['score' => 0.9], 'confidence' => 0.95],
        ];

        $sorted = $this->calculator->sortCapsulesByGdi($capsules, false);

        $this->assertSame('low', $sorted[0]['id']);
        $this->assertSame('high', $sorted[1]['id']);
    }

    public function testSortGenesByGdi(): void
    {
        $genes = [
            ['id' => 'low', 'usage_count' => 1],
            ['id' => 'high', 'usage_count' => 50],
        ];

        $sorted = $this->calculator->sortGenesByGdi($genes);

        $this->assertSame('high', $sorted[0]['id']);
        $this->assertSame('low', $sorted[1]['id']);
    }

    // =========================================================================
    // Filtering Tests
    // =========================================================================

    public function testFilterCapsulesByMinGdi(): void
    {
        $capsules = [
            ['id' => 'low', 'outcome' => ['score' => 0.1], 'confidence' => 0.1],
            ['id' => 'high', 'outcome' => ['score' => 0.9], 'confidence' => 0.9],
            ['id' => 'mid', 'outcome' => ['score' => 0.5], 'confidence' => 0.5],
        ];

        $filtered = $this->calculator->filterCapsulesByMinGdi($capsules, 0.4);

        $this->assertCount(2, $filtered);
        $ids = array_column($filtered, 'id');
        $this->assertContains('high', $ids);
        $this->assertContains('mid', $ids);
        $this->assertNotContains('low', $ids);
    }

    // =========================================================================
    // Annotate Tests
    // =========================================================================

    public function testAnnotateCapsulesWithGdi(): void
    {
        $capsules = [
            ['id' => 'test', 'outcome' => ['score' => 0.8], 'confidence' => 0.8],
        ];

        $this->calculator->annotateCapsulesWithGdi($capsules);

        $this->assertArrayHasKey('_gdi', $capsules[0]);
        $this->assertGreaterThanOrEqual(0.0, $capsules[0]['_gdi']);
        $this->assertLessThanOrEqual(1.0, $capsules[0]['_gdi']);
    }

    // =========================================================================
    // Top N Tests
    // =========================================================================

    public function testGetTopCapsules(): void
    {
        $capsules = [];
        for ($i = 0; $i < 20; $i++) {
            $capsules[] = ['id' => "capsule_{$i}", 'outcome' => ['score' => $i / 20], 'confidence' => $i / 20];
        }

        $top = $this->calculator->getTopCapsules($capsules, 5);

        $this->assertCount(5, $top);
    }

    // =========================================================================
    // Category Tests
    // =========================================================================

    public function testGetGdiCategoryExcellent(): void
    {
        $this->assertSame('excellent', $this->calculator->getGdiCategory(0.9));
        $this->assertSame('excellent', $this->calculator->getGdiCategory(0.8));
    }

    public function testGetGdiCategoryGood(): void
    {
        $this->assertSame('good', $this->calculator->getGdiCategory(0.7));
        $this->assertSame('good', $this->calculator->getGdiCategory(0.6));
    }

    public function testGetGdiCategoryAverage(): void
    {
        $this->assertSame('average', $this->calculator->getGdiCategory(0.5));
        $this->assertSame('average', $this->calculator->getGdiCategory(0.4));
    }

    public function testGetGdiCategoryPoor(): void
    {
        $this->assertSame('poor', $this->calculator->getGdiCategory(0.3));
        $this->assertSame('poor', $this->calculator->getGdiCategory(0.2));
    }

    public function testGetGdiCategoryVeryPoor(): void
    {
        $this->assertSame('very_poor', $this->calculator->getGdiCategory(0.1));
        $this->assertSame('very_poor', $this->calculator->getGdiCategory(0.0));
    }

    // =========================================================================
    // Stats Tests
    // =========================================================================

    public function testGetGdiStatsWithEmptyArray(): void
    {
        $stats = $this->calculator->getGdiStats([]);

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0.0, $stats['average']);
        $this->assertSame(0.0, $stats['min']);
        $this->assertSame(0.0, $stats['max']);
    }

    public function testGetGdiStatsWithCapsules(): void
    {
        $capsules = [
            ['outcome' => ['score' => 0.2], 'confidence' => 0.2],
            ['outcome' => ['score' => 0.8], 'confidence' => 0.8],
        ];

        $stats = $this->calculator->getGdiStats($capsules);

        $this->assertSame(2, $stats['count']);
        $this->assertGreaterThan(0, $stats['average']);
        $this->assertLessThan(1, $stats['average']);
        $this->assertArrayHasKey('distribution', $stats);
    }
}
