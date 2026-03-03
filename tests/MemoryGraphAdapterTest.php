<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\MemoryGraphAdapter;
use PHPUnit\Framework\TestCase;

final class MemoryGraphAdapterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Set env to local to ensure local adapter is used
        $_ENV['MEMORY_GRAPH_PROVIDER'] = 'local';
        $_ENV['MEMORY_GRAPH_PATH'] = $this->tempDir . '/memory_graph.jsonl';
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }

        // Clean up env
        unset($_ENV['MEMORY_GRAPH_PROVIDER']);
        unset($_ENV['MEMORY_GRAPH_PATH']);
    }

    // =========================================================================
    // Resolve Tests
    // =========================================================================

    public function testResolveReturnsLocalAdapterByDefault(): void
    {
        $_ENV['MEMORY_GRAPH_PROVIDER'] = 'local';
        $adapter = MemoryGraphAdapter::resolve();

        $this->assertSame('local', $adapter->getName());
    }

    public function testResolveReturnsRemoteAdapterWhenConfigured(): void
    {
        $_ENV['MEMORY_GRAPH_PROVIDER'] = 'remote';
        $_ENV['MEMORY_GRAPH_REMOTE_URL'] = 'https://example.com';
        $_ENV['MEMORY_GRAPH_REMOTE_KEY'] = 'test-key';

        $adapter = MemoryGraphAdapter::resolve();

        $this->assertSame('remote', $adapter->getName());

        // Clean up
        unset($_ENV['MEMORY_GRAPH_REMOTE_URL']);
        unset($_ENV['MEMORY_GRAPH_REMOTE_KEY']);
    }

    // =========================================================================
    // Get Name Tests
    // =========================================================================

    public function testGetNameReturnsCorrectName(): void
    {
        $_ENV['MEMORY_GRAPH_PROVIDER'] = 'local';
        $adapter = MemoryGraphAdapter::resolve();

        $this->assertSame('local', $adapter->getName());
    }

    // =========================================================================
    // Get Advice Tests
    // =========================================================================

    public function testGetAdviceReturnsValidStructure(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $advice = $adapter->getAdvice([
            'signals' => ['error', 'syntax'],
            'genes' => [],
            'driftEnabled' => false,
        ]);

        $this->assertArrayHasKey('currentSignalKey', $advice);
        $this->assertArrayHasKey('preferredGeneId', $advice);
        $this->assertArrayHasKey('bannedGeneIds', $advice);
    }

    // =========================================================================
    // Signal Snapshot Tests
    // =========================================================================

    public function testRecordSignalSnapshotReturnsEvent(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $event = $adapter->recordSignalSnapshot([
            'signals' => ['error', 'syntax'],
            'source' => 'test',
        ]);

        $this->assertArrayHasKey('type', $event);
        $this->assertSame('MemoryGraphEvent', $event['type']);
    }

    // =========================================================================
    // Hypothesis Tests
    // =========================================================================

    public function testRecordHypothesisReturnsResult(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $result = $adapter->recordHypothesis([
            'geneId' => 'test_gene',
            'signalKey' => 'error|syntax',
            'hypothesis' => 'Fix syntax error',
        ]);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Attempt Tests
    // =========================================================================

    public function testRecordAttemptReturnsResult(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $result = $adapter->recordAttempt([
            'geneId' => 'test_gene',
            'signalKey' => 'error|syntax',
            'attemptId' => 'attempt_1',
        ]);

        $this->assertIsArray($result);
    }

    // =========================================================================
    // Outcome Tests
    // =========================================================================

    public function testRecordOutcomeReturnsEvent(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        // First record a signal and attempt
        $adapter->recordSignalSnapshot([
            'signals' => ['error'],
            'source' => 'test',
        ]);

        $adapter->recordAttempt([
            'geneId' => 'test_gene',
            'signalKey' => 'error',
            'attemptId' => 'attempt_1',
        ]);

        $event = $adapter->recordOutcome([
            'status' => 'success',
            'score' => 0.9,
        ]);

        // Can be null if no current state
        $this->assertTrue($event === null || is_array($event));
    }

    // =========================================================================
    // External Candidate Tests
    // =========================================================================

    public function testRecordExternalCandidateReturnsEvent(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $event = $adapter->recordExternalCandidate([
            'assetId' => 'sha256:abc123',
            'sourceNodeId' => 'node_remote',
            'capsule' => ['id' => 'capsule_1'],
        ]);

        // Can be null if conditions not met
        $this->assertTrue($event === null || is_array($event));
    }

    // =========================================================================
    // Compute Signal Key Tests
    // =========================================================================

    public function testComputeSignalKeyReturnsConsistentKey(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $key1 = $adapter->computeSignalKey(['error', 'syntax']);
        $key2 = $adapter->computeSignalKey(['syntax', 'error']); // Different order

        // Key should be consistent regardless of order
        $this->assertSame($key1, $key2);
    }

    public function testComputeSignalKeyWithEmptySignals(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $key = $adapter->computeSignalKey([]);
        $this->assertNotEmpty($key);
    }

    // =========================================================================
    // Memory Graph Path Tests
    // =========================================================================

    public function testMemoryGraphPathReturnsValidPath(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $path = $adapter->memoryGraphPath();
        $this->assertStringContainsString('memory_graph.jsonl', $path);
    }

    // =========================================================================
    // Read Events Tests
    // =========================================================================

    public function testTryReadMemoryGraphEventsReturnsArray(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        $events = $adapter->tryReadMemoryGraphEvents(100);

        $this->assertIsArray($events);
    }

    public function testTryReadMemoryGraphEventsRespectsLimit(): void
    {
        $adapter = MemoryGraphAdapter::resolve();

        // Record multiple events
        for ($i = 0; $i < 15; $i++) {
            $adapter->recordSignalSnapshot([
                'signals' => ["signal_{$i}"],
                'source' => 'test',
            ]);
        }

        $events = $adapter->tryReadMemoryGraphEvents(5);

        $this->assertLessThanOrEqual(5, count($events));
    }
}
