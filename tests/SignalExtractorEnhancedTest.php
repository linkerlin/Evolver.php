<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SignalExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Signal Extractor Enhanced Tests - Task 1.2 Multi-source signal fusion
 */
final class SignalExtractorEnhancedTest extends TestCase
{
    private SignalExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SignalExtractor();
    }

    // -------------------------------------------------------------------------
    // Multi-source signal fusion tests
    // -------------------------------------------------------------------------

    public function testExtractFromMultipleSources(): void
    {
        $sources = [
            'session_logs' => "[error] Something failed\n",  // Triggers log_error
            'today_logs' => 'Performance bottleneck detected - slow response time',
            'memory_snippet' => 'Please add a new feature for user management',
        ];

        $result = $this->extractor->extractFromMultipleSources($sources);

        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('detailed', $result);
        $this->assertArrayHasKey('by_source', $result);

        // Should have signals from all sources
        $this->assertNotEmpty($result['signals']);
        $this->assertContains('log_error', $result['signals']);
        $this->assertContains('perf_bottleneck', $result['signals']);
        $this->assertContains('user_feature_request', $result['signals']);
    }

    public function testExtractFromMultipleSourcesWithEmptySource(): void
    {
        $sources = [
            'session_logs' => "[error] First error\n",
            'empty_source' => '',
            'another_source' => "[error] Another error\n",
        ];

        $result = $this->extractor->extractFromMultipleSources($sources);

        // log_error appears in 2 sources
        $logErrorDetail = null;
        foreach ($result['detailed'] as $detail) {
            if ($detail['signal'] === 'log_error') {
                $logErrorDetail = $detail;
                break;
            }
        }

        $this->assertNotNull($logErrorDetail);
        $this->assertEquals(2, $logErrorDetail['count']);
        $this->assertContains('session_logs', $logErrorDetail['sources']);
        $this->assertContains('another_source', $logErrorDetail['sources']);
    }

    public function testExtractFromMultipleSourcesEmptySources(): void
    {
        $result = $this->extractor->extractFromMultipleSources([]);

        $this->assertEmpty($result['signals']);
        $this->assertEmpty($result['detailed']);
        $this->assertEmpty($result['by_source']);
    }

    // -------------------------------------------------------------------------
    // Session transcript parsing tests
    // -------------------------------------------------------------------------

    public function testParseSessionTranscriptJsonFormat(): void
    {
        $log = json_encode(['role' => 'user', 'content' => 'Hello', 'timestamp' => '2026-03-03T10:00:00Z']) . "\n";
        $log .= json_encode(['role' => 'assistant', 'content' => 'Hi', 'tool_calls' => [['name' => 'read_file']]]) . "\n";
        $log .= json_encode(['error' => ['message' => 'Something failed']]) . "\n";

        $result = $this->extractor->parseSessionTranscript($log);

        $this->assertArrayHasKey('messages', $result);
        $this->assertArrayHasKey('tool_calls', $result);
        $this->assertArrayHasKey('tool_results', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('metadata', $result);

        $this->assertCount(2, $result['messages']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertCount(1, $result['errors']);
        $this->assertTrue($result['metadata']['has_errors']);
    }

    public function testParseSessionTranscriptPlainText(): void
    {
        $log = "[TOOL: exec] Running command\n";
        $log .= "[error] Something went wrong\n";
        $log .= "Normal log line\n";

        $result = $this->extractor->parseSessionTranscript($log);

        $this->assertCount(1, $result['tool_calls']);
        $this->assertEquals('exec', $result['tool_calls'][0]['name']);
        $this->assertCount(1, $result['errors']);
        $this->assertTrue($result['metadata']['has_errors']);
    }

    public function testParseSessionTranscriptEmpty(): void
    {
        $result = $this->extractor->parseSessionTranscript('');

        $this->assertEmpty($result['messages']);
        $this->assertEmpty($result['tool_calls']);
        $this->assertEmpty($result['errors']);
        $this->assertEquals(0, $result['metadata']['total_lines']);
        $this->assertFalse($result['metadata']['has_errors']);
    }

    // -------------------------------------------------------------------------
    // Repair loop detection tests
    // -------------------------------------------------------------------------

    public function testDetectRepairLoopReturnsNullForHealthy(): void
    {
        $events = [
            ['intent' => 'optimize', 'outcome' => ['status' => 'success']],
            ['intent' => 'innovate', 'outcome' => ['status' => 'success']],
        ];

        $result = $this->extractor->detectRepairLoop($events);

        $this->assertNull($result);
    }

    public function testDetectRepairLoopDetectsRepairLoop(): void
    {
        $events = [
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
            ['intent' => 'repair', 'outcome' => ['status' => 'failed']],
        ];

        $result = $this->extractor->detectRepairLoop($events);

        $this->assertEquals('repair_loop_detected', $result);
    }

    public function testDetectRepairLoopDetectsForceInnovation(): void
    {
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = ['intent' => 'repair', 'outcome' => ['status' => 'failed']];
        }

        $result = $this->extractor->detectRepairLoop($events);

        $this->assertEquals('force_innovation_required', $result);
    }

    public function testDetectRepairLoopDetectsStagnation(): void
    {
        $events = [];
        for ($i = 0; $i < 4; $i++) {
            $events[] = [
                'intent' => 'optimize',
                'outcome' => ['status' => 'success'],
                'blast_radius' => ['files' => 0, 'lines' => 0],
                'meta' => ['empty_cycle' => true],
            ];
        }

        $result = $this->extractor->detectRepairLoop($events);

        $this->assertEquals('evolution_stagnation_detected', $result);
    }

    public function testDetectRepairLoopDetectsFailureLoop(): void
    {
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = ['intent' => 'innovate', 'outcome' => ['status' => 'failed']];
        }

        $result = $this->extractor->detectRepairLoop($events);

        $this->assertEquals('failure_loop_detected', $result);
    }

    public function testDetectRepairLoopEmptyEvents(): void
    {
        $result = $this->extractor->detectRepairLoop([]);

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Signal deduplication tests
    // -------------------------------------------------------------------------

    public function testDeduplicateSignals(): void
    {
        $signals = [
            'log_error',
            'log_error',
            'log_error',
            'perf_bottleneck',
            'perf_bottleneck',
        ];

        $result = $this->extractor->deduplicateSignals($signals);

        // Should have 2 groups
        $this->assertCount(2, $result);

        // log_error should be first (most frequent)
        $this->assertEquals('log_error', $result[0]['signal']);
        $this->assertEquals(3, $result[0]['count']);
        $this->assertTrue($result[0]['folded']);

        // perf_bottleneck second
        $this->assertEquals('perf_bottleneck', $result[1]['signal']);
        $this->assertEquals(2, $result[1]['count']);
    }

    public function testDeduplicateSignalsNormalizesVariants(): void
    {
        $signals = [
            'errsig:Error at line 10',
            'errsig:Error at line 20',
            'log_error',
        ];

        $result = $this->extractor->deduplicateSignals($signals);

        // errsig variants should be grouped under errsig:generic
        // Find the group that contains the errsig variants
        $errsigGroup = null;
        foreach ($result as $group) {
            // The canonical signal for errsig variants should be one of them
            // and related should contain the other
            if (str_starts_with($group['signal'], 'errsig:') && $group['signal'] !== 'errsig:generic') {
                $errsigGroup = $group;
                break;
            }
            if ($group['signal'] === 'errsig:generic') {
                $errsigGroup = $group;
                break;
            }
        }

        $this->assertNotNull($errsigGroup);
        $this->assertEquals(2, $errsigGroup['count']);
    }

    public function testDeduplicateSignalsEmpty(): void
    {
        $result = $this->extractor->deduplicateSignals([]);

        $this->assertEmpty($result);
    }

    public function testDeduplicateSignalsSingle(): void
    {
        $signals = ['log_error'];

        $result = $this->extractor->deduplicateSignals($signals);

        $this->assertCount(1, $result);
        $this->assertEquals('log_error', $result[0]['signal']);
        $this->assertEquals(1, $result[0]['count']);
        $this->assertFalse($result[0]['folded']);
    }
}
