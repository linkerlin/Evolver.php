<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\SignalDeduplicator;
use PHPUnit\Framework\TestCase;

/**
 * Signal Deduplicator tests - extracted from EvolverTest.php
 */
final class SignalDeduplicatorTest extends TestCase
{
    private SignalDeduplicator $deduplicator;

    protected function setUp(): void
    {
        // Short suppression window for testing
        $this->deduplicator = new SignalDeduplicator(3600);
    }

    public function testShouldSuppressFreshSignal(): void
    {
        $result = $this->deduplicator->shouldSuppress('log_error');

        $this->assertFalse($result['suppress']);
        $this->assertEquals(1, $result['count']);
    }

    public function testShouldSuppressDuplicateSignal(): void
    {
        // First occurrence
        $this->deduplicator->shouldSuppress('log_error');

        // Same signal within suppress window should be suppressed
        $result = $this->deduplicator->shouldSuppress('log_error');

        $this->assertTrue($result['suppress']);
        $this->assertGreaterThan(1, $result['count']);
    }

    public function testProcessSignalFresh(): void
    {
        $result = $this->deduplicator->processSignal('test_signal');

        $this->assertEquals('notify', $result['action']);
        $this->assertArrayHasKey('notification', $result);
    }

    public function testProcessSignalSuppressed(): void
    {
        // First occurrence
        $this->deduplicator->processSignal('test_signal');

        // Second occurrence should be suppressed
        $result = $this->deduplicator->processSignal('test_signal');

        $this->assertEquals('suppressed', $result['action']);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testGetSuppressionSummary(): void
    {
        // Add some signals
        $this->deduplicator->processSignal('signal_a');
        $this->deduplicator->processSignal('signal_a');
        $this->deduplicator->processSignal('signal_b');

        $summary = $this->deduplicator->getSuppressionSummary();

        $this->assertArrayHasKey('period_seconds', $summary);
        $this->assertArrayHasKey('unique_signals', $summary);
        $this->assertArrayHasKey('total_occurrences', $summary);
        $this->assertArrayHasKey('signals', $summary);
        $this->assertEquals(2, $summary['unique_signals']);
    }

    public function testClearHistory(): void
    {
        $this->deduplicator->processSignal('test_signal');
        $this->assertEquals(1, $this->deduplicator->getHistorySize());

        $this->deduplicator->clearHistory();
        $this->assertEquals(0, $this->deduplicator->getHistorySize());
    }

    public function testGetHistorySize(): void
    {
        $this->assertEquals(0, $this->deduplicator->getHistorySize());

        $this->deduplicator->processSignal('signal_a');
        $this->assertEquals(1, $this->deduplicator->getHistorySize());

        $this->deduplicator->processSignal('signal_b');
        $this->assertEquals(2, $this->deduplicator->getHistorySize());
    }
}
