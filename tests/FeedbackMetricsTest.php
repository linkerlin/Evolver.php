<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\FeedbackMetrics;
use PHPUnit\Framework\TestCase;

final class FeedbackMetricsTest extends TestCase
{
    public function testComputeWithEmptyEvents(): void
    {
        $metrics = FeedbackMetrics::compute([]);
        $this->assertSame(0, $metrics['event_count']);
        $this->assertSame(0.0, $metrics['reproducible_success_rate']);
        $this->assertStringContainsString('无近期事件', $metrics['report']);
    }

    public function testComputeWithSuccessEvents(): void
    {
        $events = [
            [
                'outcome' => ['status' => 'success', 'score' => 0.85, 'source' => 'solidify'],
                'cost' => ['total_cost' => 5.0],
                'blast_radius' => ['files' => 2, 'lines' => 20],
                'attribution' => ['decision_source' => 'gene_selected', 'primary_cause' => 'gene_strategy'],
                'signals' => ['log_error'],
            ],
            [
                'outcome' => ['status' => 'failed', 'score' => 0.2, 'source' => 'solidify'],
                'cost' => ['total_cost' => 10.0],
                'blast_radius' => ['files' => 5, 'lines' => 50],
                'attribution' => [],
                'signals' => ['error'],
            ],
        ];
        $metrics = FeedbackMetrics::compute($events);
        $this->assertSame(2, $metrics['event_count']);
        $this->assertSame(0.5, $metrics['reproducible_success_rate']);
        $this->assertSame(1.0, $metrics['closure_coverage']);
        $this->assertGreaterThan(0, $metrics['avg_total_cost']);
        $this->assertGreaterThanOrEqual(0, $metrics['attribution_clarity_rate']);
        $this->assertStringContainsString('闭环覆盖率', $metrics['report']);
    }
}
