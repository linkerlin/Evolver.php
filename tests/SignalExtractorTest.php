<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SignalExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Signal Extractor tests - extracted from EvolverTest.php
 */
final class SignalExtractorTest extends TestCase
{
    private SignalExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SignalExtractor();
    }

    public function testExtractErrorSignal(): void
    {
        $signals = $this->extractor->extract(['context' => 'Some [error] occurred in the code']);
        $this->assertContains('log_error', $signals);
    }

    public function testExtractFeatureRequestSignal(): void
    {
        $signals = $this->extractor->extract(['context' => 'Please add a new feature for handling JSON']);
        $this->assertContains('user_feature_request', $signals);
    }

    public function testExtractPerfBottleneckSignal(): void
    {
        $signals = $this->extractor->extract(['context' => 'The system is very slow and has high latency']);
        $this->assertContains('perf_bottleneck', $signals);
    }

    public function testExtractImprovementSuggestion(): void
    {
        $signals = $this->extractor->extract(['context' => 'This could be better if we improve the code']);
        $this->assertContains('user_improvement_suggestion', $signals);
    }

    public function testExtractCapabilityGapSignal(): void
    {
        $signals = $this->extractor->extract(['context' => 'The system does not support XML parsing']);
        $this->assertContains('capability_gap', $signals);
    }

    public function testExtractRecurringErrorSignal(): void
    {
        $context = str_repeat('LLM error: something failed. ', 5);
        $signals = $this->extractor->extract(['context' => $context]);
        $this->assertContains('recurring_error', $signals);
    }

    public function testExtractRepairLoopDetected(): void
    {
        $recentEvents = [
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
        ];
        $signals = $this->extractor->extract([
            'context' => 'Some context',
            'recentEvents' => $recentEvents,
        ]);
        $this->assertContains('repair_loop_detected', $signals);
    }

    public function testExtractEmptyContext(): void
    {
        $signals = $this->extractor->extract(['context' => '']);
        $this->assertIsArray($signals);
        // Empty context should not produce error signals
        $this->assertNotContains('log_error', $signals);
    }

    public function testHasOpportunitySignal(): void
    {
        $signals = [
            'user_feature_request',
            'log_error',
        ];
        $this->assertTrue($this->extractor->hasOpportunitySignal($signals));

        $noOpportunitySignals = [
            'log_error',
            'memory_missing',
        ];
        $this->assertFalse($this->extractor->hasOpportunitySignal($noOpportunitySignals));
    }

    public function testAnalyzeRecentHistoryReturnsStructure(): void
    {
        $events = [
            [
                'intent' => 'repair',
                'outcome' => ['status' => 'failed'],
                'signals' => ['error1'],
                'genes_used' => ['gene1'],
                'blast_radius' => ['files' => 2, 'lines' => 10],
                'modified_files' => ['file1.php'],
            ],
            [
                'intent' => 'optimize',
                'outcome' => ['status' => 'success'],
                'signals' => ['signal2'],
                'genes_used' => ['gene2'],
                'blast_radius' => ['files' => 1, 'lines' => 5],
                'modified_files' => ['file2.php'],
            ],
        ];

        $history = $this->extractor->analyzeRecentHistory($events);

        $this->assertArrayHasKey('consecutiveRepairCount', $history);
        $this->assertArrayHasKey('consecutiveEmptyCycles', $history);
        $this->assertArrayHasKey('consecutiveFailureCount', $history);
        $this->assertArrayHasKey('recentFailureRatio', $history);
        $this->assertArrayHasKey('signalFreq', $history);
        $this->assertArrayHasKey('geneFreq', $history);
        $this->assertArrayHasKey('oscillationCount', $history);
    }

    public function testGetThresholds(): void
    {
        $thresholds = SignalExtractor::getThresholds();

        $this->assertArrayHasKey('repair_loop', $thresholds);
        $this->assertArrayHasKey('force_innovation', $thresholds);
        $this->assertArrayHasKey('stagnation', $thresholds);
        $this->assertArrayHasKey('failure_ratio', $thresholds);
        $this->assertArrayHasKey('oscillation', $thresholds);
    }
}
