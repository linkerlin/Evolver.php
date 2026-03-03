<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\GepA2AProtocol;
use Evolver\TaskReceiver;
use PHPUnit\Framework\TestCase;

final class TaskReceiverTest extends TestCase
{
    private TaskReceiver $receiver;

    protected function setUp(): void
    {
        // Use dummy hub URL to avoid actual network calls
        $this->receiver = new TaskReceiver(null, 'https://test-hub.example.com');
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorWithDefaultParams(): void
    {
        $receiver = new TaskReceiver();
        $this->assertInstanceOf(TaskReceiver::class, $receiver);
    }

    public function testConstructorWithCustomProtocol(): void
    {
        $protocol = new GepA2AProtocol();
        $receiver = new TaskReceiver($protocol, 'https://custom.hub.com');

        $this->assertSame('https://custom.hub.com', $receiver->getHubUrl());
    }

    // =========================================================================
    // Getter Tests
    // =========================================================================

    public function testGetHubUrlReturnsConfiguredUrl(): void
    {
        $this->assertSame('https://test-hub.example.com', $this->receiver->getHubUrl());
    }

    public function testGetStrategyReturnsDefault(): void
    {
        $original = getenv('TASK_STRATEGY');
        putenv('TASK_STRATEGY');

        $receiver = new TaskReceiver();
        $this->assertSame('balanced', $receiver->getStrategy());

        if ($original !== false) {
            putenv("TASK_STRATEGY={$original}");
        }
    }

    public function testGetStrategyReturnsConfigured(): void
    {
        $original = getenv('TASK_STRATEGY');
        putenv('TASK_STRATEGY=greedy');

        $receiver = new TaskReceiver();
        $this->assertSame('greedy', $receiver->getStrategy());

        if ($original !== false) {
            putenv("TASK_STRATEGY={$original}");
        } else {
            putenv('TASK_STRATEGY');
        }
    }

    public function testGetStrategyWeightsReturnsValidArray(): void
    {
        $weights = $this->receiver->getStrategyWeights();

        $this->assertArrayHasKey('greedy', $weights);
        $this->assertArrayHasKey('balanced', $weights);
        $this->assertArrayHasKey('conservative', $weights);

        // Each strategy should have all weight components
        foreach ($weights as $strategy => $components) {
            $this->assertArrayHasKey('roi', $components);
            $this->assertArrayHasKey('capability', $components);
            $this->assertArrayHasKey('completion', $components);
            $this->assertArrayHasKey('bounty', $components);
        }
    }

    // =========================================================================
    // Task to Signals Tests
    // =========================================================================

    public function testTaskToSignalsExtractsFromSignalsField(): void
    {
        $task = ['signals' => 'error,syntax,bug'];

        $signals = $this->receiver->taskToSignals($task);

        $this->assertContains('error', $signals);
        $this->assertContains('syntax', $signals);
        $this->assertContains('bug', $signals);
    }

    public function testTaskToSignalsExtractsFromTitle(): void
    {
        $task = ['title' => 'Fix critical bug in parser'];

        $signals = $this->receiver->taskToSignals($task);

        $this->assertContains('external_task', $signals);
        // Words from title should be included
        $this->assertTrue(
            in_array('fix', $signals) ||
            in_array('critical', $signals) ||
            in_array('bug', $signals)
        );
    }

    public function testTaskToSignalsAddsBountyTag(): void
    {
        $task = [
            'title' => 'Test task',
            'bounty_id' => 'bounty_123',
        ];

        $signals = $this->receiver->taskToSignals($task);

        $this->assertContains('bounty_task', $signals);
        $this->assertContains('external_task', $signals);
    }

    public function testTaskToSignalsAlwaysAddsExternalTaskTag(): void
    {
        $signals = $this->receiver->taskToSignals([]);

        $this->assertContains('external_task', $signals);
    }

    public function testTaskToSignalsDeduplicates(): void
    {
        $task = ['signals' => 'error,error,error'];

        $signals = $this->receiver->taskToSignals($task);

        $errorCount = array_filter($signals, fn($s) => $s === 'error');
        $this->assertCount(1, $errorCount);
    }

    // =========================================================================
    // Capability Match Tests
    // =========================================================================

    public function testEstimateCapabilityMatchReturns05WithNoMemory(): void
    {
        $task = ['signals' => 'error,syntax'];
        $memoryEvents = [];

        $match = $this->receiver->estimateCapabilityMatch($task, $memoryEvents);

        $this->assertSame(0.5, $match);
    }

    public function testEstimateCapabilityMatchReturns05WithNoSignals(): void
    {
        $task = [];
        $memoryEvents = [
            ['type' => 'MemoryGraphEvent', 'kind' => 'outcome'],
        ];

        $match = $this->receiver->estimateCapabilityMatch($task, $memoryEvents);

        $this->assertSame(0.5, $match);
    }

    public function testEstimateCapabilityMatchWithSuccessfulHistory(): void
    {
        $task = ['signals' => 'error,syntax'];
        $memoryEvents = [
            [
                'type' => 'MemoryGraphEvent',
                'kind' => 'outcome',
                'signal' => ['signals' => ['error', 'syntax'], 'key' => 'error|syntax'],
                'outcome' => ['status' => 'success'],
            ],
            [
                'type' => 'MemoryGraphEvent',
                'kind' => 'outcome',
                'signal' => ['signals' => ['error', 'syntax'], 'key' => 'error|syntax'],
                'outcome' => ['status' => 'success'],
            ],
        ];

        $match = $this->receiver->estimateCapabilityMatch($task, $memoryEvents);

        $this->assertGreaterThan(0.5, $match);
    }

    // =========================================================================
    // Score Task Tests
    // =========================================================================

    public function testScoreTaskReturnsCorrectStructure(): void
    {
        $task = [
            'title' => 'Test task',
            'complexity_score' => 0.5,
            'bounty_amount' => 50,
            'historical_completion_rate' => 0.6,
        ];

        $result = $this->receiver->scoreTask($task, 0.5);

        $this->assertArrayHasKey('composite', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('roi', $result['factors']);
        $this->assertArrayHasKey('capability', $result['factors']);
        $this->assertArrayHasKey('completion', $result['factors']);
        $this->assertArrayHasKey('bounty', $result['factors']);
    }

    public function testScoreTaskCompositeIsBetween0And1(): void
    {
        $task = [
            'bounty_amount' => 100,
            'complexity_score' => 0.1,
        ];

        $result = $this->receiver->scoreTask($task, 1.0);

        $this->assertGreaterThanOrEqual(0, $result['composite']);
        $this->assertLessThanOrEqual(1, $result['composite']);
    }

    public function testScoreTaskHigherCapabilityIncreasesScore(): void
    {
        $task = ['bounty_amount' => 50, 'complexity_score' => 0.5];

        $lowCapability = $this->receiver->scoreTask($task, 0.1);
        $highCapability = $this->receiver->scoreTask($task, 0.9);

        $this->assertGreaterThan($lowCapability['composite'], $highCapability['composite']);
    }

    // =========================================================================
    // Select Best Task Tests
    // =========================================================================

    public function testSelectBestTaskReturnsNullWithEmptyArray(): void
    {
        $result = $this->receiver->selectBestTask([]);
        $this->assertNull($result);
    }

    public function testSelectBestTaskPrioritizesAlreadyClaimedTask(): void
    {
        $nodeId = (new GepA2AProtocol())->getNodeId();

        $tasks = [
            ['task_id' => 'task_1', 'status' => 'open', 'bounty_amount' => 1000],
            ['task_id' => 'task_2', 'status' => 'claimed', 'claimed_by' => $nodeId, 'bounty_amount' => 10],
        ];

        $selected = $this->receiver->selectBestTask($tasks);

        $this->assertSame('task_2', $selected['task_id']);
    }

    public function testSelectBestTaskReturnsNullWithNoOpenTasks(): void
    {
        $tasks = [
            ['task_id' => 'task_1', 'status' => 'completed'],
            ['task_id' => 'task_2', 'status' => 'claimed', 'claimed_by' => 'other_node'],
        ];

        $selected = $this->receiver->selectBestTask($tasks);

        $this->assertNull($selected);
    }

    public function testSelectBestTaskSelectsHighestScored(): void
    {
        $tasks = [
            ['task_id' => 'low', 'status' => 'open', 'bounty_amount' => 10, 'complexity_score' => 0.9],
            ['task_id' => 'high', 'status' => 'open', 'bounty_amount' => 100, 'complexity_score' => 0.1],
        ];

        $selected = $this->receiver->selectBestTask($tasks);

        // High bounty + low complexity should score higher
        $this->assertSame('high', $selected['task_id']);
    }

    // =========================================================================
    // Fetch Tasks Tests (Network Mock)
    // =========================================================================

    public function testFetchTasksReturnsEmptyArrayOnError(): void
    {
        // With invalid hub URL, should return empty
        $receiver = new TaskReceiver(null, '');

        $result = $receiver->fetchTasks();

        $this->assertArrayHasKey('tasks', $result);
    }

    // =========================================================================
    // Claim/Complete Task Tests (Network Mock)
    // =========================================================================

    public function testClaimTaskReturnsFalseWithEmptyId(): void
    {
        $result = $this->receiver->claimTask('');
        $this->assertFalse($result);
    }

    public function testCompleteTaskReturnsFalseWithEmptyParams(): void
    {
        $result = $this->receiver->completeTask('', 'asset_123');
        $this->assertFalse($result);

        $result = $this->receiver->completeTask('task_123', '');
        $this->assertFalse($result);
    }
}
