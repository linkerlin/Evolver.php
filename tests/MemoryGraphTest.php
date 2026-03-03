<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\MemoryGraph;
use PHPUnit\Framework\TestCase;

/**
 * MemoryGraph tests.
 */
final class MemoryGraphTest extends TestCase
{
    public function testNowIso(): void
    {
        $iso = MemoryGraph::nowIso();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $iso);
    }

    public function testClamp01(): void
    {
        $this->assertEquals(0.5, MemoryGraph::clamp01(0.5));
        $this->assertEquals(0.0, MemoryGraph::clamp01(-0.5));
        $this->assertEquals(1.0, MemoryGraph::clamp01(1.5));
    }

    public function testStableHash(): void
    {
        $hash1 = MemoryGraph::stableHash('test');
        $hash2 = MemoryGraph::stableHash('test');

        $this->assertEquals($hash1, $hash2);
        $this->assertNotEquals($hash1, MemoryGraph::stableHash('different'));
    }

    public function testNormalizeSignalsForMatching(): void
    {
        $signals = ['Error', 'PHP_ERROR', 'test'];
        $normalized = MemoryGraph::normalizeSignalsForMatching($signals);

        $this->assertIsArray($normalized);
        // normalizeSignalsForMatching preserves original case
        $this->assertContains('Error', $normalized);
        $this->assertContains('PHP_ERROR', $normalized);
        $this->assertContains('test', $normalized);
    }

    public function testComputeSignalKey(): void
    {
        $signals = ['error', 'php'];
        $key = MemoryGraph::computeSignalKey($signals);

        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    public function testJaccard(): void
    {
        $a = ['error', 'php'];
        $b = ['error', 'js'];

        $similarity = MemoryGraph::jaccard($a, $b);

        $this->assertGreaterThanOrEqual(0, $similarity);
        $this->assertLessThanOrEqual(1, $similarity);
        $this->assertEquals(0.333, round($similarity, 3));
    }

    public function testDecayWeight(): void
    {
        $now = date('c');
        $weight = MemoryGraph::decayWeight($now, 30);

        $this->assertEquals(1.0, $weight);

        $oldDate = date('c', strtotime('-60 days'));
        $oldWeight = MemoryGraph::decayWeight($oldDate, 30);

        $this->assertLessThan(1.0, $oldWeight);
        $this->assertGreaterThan(0, $oldWeight);
    }

    public function testGetMemoryAdvice(): void
    {
        $signals = ['error', 'php_error'];
        $genes = [
            ['id' => 'gene_repair', 'signals_match' => ['error']],
        ];

        $advice = MemoryGraph::getMemoryAdvice($signals, $genes);

        $this->assertIsArray($advice);
    }

    public function testRecordSignalSnapshot(): void
    {
        $signals = ['error', 'warning'];

        $result = MemoryGraph::recordSignalSnapshot($signals);

        $this->assertIsArray($result);
    }

    public function testRecordHypothesis(): void
    {
        $opts = [
            'hypothesis' => 'Test hypothesis',
            'signals' => ['error'],
        ];

        $result = MemoryGraph::recordHypothesis($opts);

        $this->assertIsArray($result);
    }

    public function testRecordAttempt(): void
    {
        $opts = [
            'gene_id' => 'gene_test',
            'signals' => ['error'],
        ];

        $result = MemoryGraph::recordAttempt($opts);

        $this->assertIsArray($result);
    }
}
