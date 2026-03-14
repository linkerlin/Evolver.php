<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Paths;
use Evolver\Reflection;
use PHPUnit\Framework\TestCase;

/**
 * Reflection tests.
 */
final class ReflectionTest extends TestCase
{
    private string $testDir;
    private ?string $originalEvolutionDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/evolver_reflection_test_' . uniqid();
        Paths::resetCache();
        $this->originalEvolutionDir = getenv('EVOLUTION_DIR') ?: null;
        putenv('EVOLUTION_DIR=' . $this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->testDir);
        Paths::resetCache();
        if ($this->originalEvolutionDir !== null) {
            putenv('EVOLUTION_DIR=' . $this->originalEvolutionDir);
        } else {
            putenv('EVOLUTION_DIR');
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function testShouldReflectReturnsFalseForLowCycleCount(): void
    {
        $this->assertFalse(Reflection::shouldReflect(1));
        $this->assertFalse(Reflection::shouldReflect(3));
        $this->assertFalse(Reflection::shouldReflect(4));
    }

    public function testShouldReflectReturnsTrueAtInterval(): void
    {
        // Ensure no reflection log exists
        $path = Reflection::getPath();
        if (file_exists($path)) {
            unlink($path);
        }

        // At cycle 5, should reflect
        $this->assertTrue(Reflection::shouldReflect(5));
        $this->assertTrue(Reflection::shouldReflect(10));
        $this->assertTrue(Reflection::shouldReflect(15));
    }

    public function testShouldReflectReturnsFalseBetweenIntervals(): void
    {
        $this->assertFalse(Reflection::shouldReflect(6));
        $this->assertFalse(Reflection::shouldReflect(7));
        $this->assertFalse(Reflection::shouldReflect(11));
    }

    public function testShouldReflectRespectsCooldown(): void
    {
        // Ensure no reflection log exists first
        $path = Reflection::getPath();
        if (file_exists($path)) {
            unlink($path);
        }

        // First reflection at cycle 5
        $this->assertTrue(Reflection::shouldReflect(5));

        // Record a reflection to trigger cooldown
        Reflection::record(['insights' => ['Test insight']]);

        // Immediately check again - should be in cooldown
        // Note: Since we can't easily mock time, we test that the file modification
        // is checked properly
        $this->assertFalse(Reflection::shouldReflect(10));
    }

    public function testBuildContextWithAllParameters(): void
    {
        $context = Reflection::buildContext([
            'recentEvents' => [
                ['intent' => 'repair', 'outcome' => ['status' => 'success'], 'genes_used' => ['gene_a']],
                ['intent' => 'optimize', 'outcome' => ['status' => 'failed'], 'genes_used' => ['gene_b']],
            ],
            'signals' => ['log_error', 'errsig:TypeError'],
            'memoryAdvice' => [
                'preferredGeneId' => 'gene_preferred',
                'bannedGeneIds' => ['gene_banned'],
                'explanation' => 'Test explanation',
            ],
            'narrative' => 'Recent evolution narrative content here.',
        ]);

        $this->assertStringContainsString('strategic reflection', $context);
        $this->assertStringContainsString('Recent Cycle Statistics', $context);
        $this->assertStringContainsString('Success: 1, Failed: 1', $context);
        $this->assertStringContainsString('Current Signals', $context);
        $this->assertStringContainsString('Memory Graph Advice', $context);
        $this->assertStringContainsString('gene_preferred', $context);
        $this->assertStringContainsString('gene_banned', $context);
        $this->assertStringContainsString('Recent Evolution Narrative', $context);
        $this->assertStringContainsString('Questions to Answer', $context);
        $this->assertStringContainsString('JSON object', $context);
    }

    public function testBuildContextWithMinimalParameters(): void
    {
        $context = Reflection::buildContext([]);

        $this->assertStringContainsString('strategic reflection', $context);
        $this->assertStringContainsString('Questions to Answer', $context);
    }

    public function testBuildContextStatisticsCalculation(): void
    {
        $context = Reflection::buildContext([
            'recentEvents' => [
                ['intent' => 'repair', 'outcome' => ['status' => 'success']],
                ['intent' => 'repair', 'outcome' => ['status' => 'success']],
                ['intent' => 'optimize', 'outcome' => ['status' => 'failed']],
                ['intent' => 'innovate', 'outcome' => ['status' => 'success']],
            ],
        ]);

        $this->assertStringContainsString('Success: 3, Failed: 1', $context);
        $this->assertStringContainsString('"repair":2', $context);
        $this->assertStringContainsString('"optimize":1', $context);
        $this->assertStringContainsString('"innovate":1', $context);
    }

    public function testRecordCreatesLogFile(): void
    {
        Reflection::record([
            'insights' => ['Test insight 1', 'Test insight 2'],
            'strategy_adjustment' => 'Adjust strategy',
            'priority_signals' => ['signal_a', 'signal_b'],
        ]);

        $this->assertFileExists(Reflection::getPath());
    }

    public function testRecordContainsExpectedFields(): void
    {
        Reflection::record([
            'insights' => ['Insight text'],
            'strategy_adjustment' => 'New strategy',
        ]);

        $content = file_get_contents(Reflection::getPath());
        $lines = explode("\n", trim($content));
        $lastLine = end($lines);
        $decoded = json_decode($lastLine, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('reflection', $decoded['type']);
        $this->assertArrayHasKey('ts', $decoded);
        $this->assertContains('Insight text', $decoded['insights']);
        $this->assertEquals('New strategy', $decoded['strategy_adjustment']);
    }

    public function testRecordAppendsToJsonl(): void
    {
        // Clean up any existing file first
        $path = Reflection::getPath();
        if (file_exists($path)) {
            unlink($path);
        }

        Reflection::record(['insights' => ['First']]);
        Reflection::record(['insights' => ['Second']]);
        Reflection::record(['insights' => ['Third']]);

        $content = file_get_contents(Reflection::getPath());
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(3, $lines);
    }

    public function testLoadRecentReturnsEmptyForMissingFile(): void
    {
        // Ensure no file exists
        $path = Reflection::getPath();
        if (file_exists($path)) {
            unlink($path);
        }

        $reflections = Reflection::loadRecent();
        $this->assertSame([], $reflections);
    }

    public function testLoadRecentReturnsReflections(): void
    {
        Reflection::record(['insights' => ['First insight'], 'strategy_adjustment' => 'A']);
        Reflection::record(['insights' => ['Second insight'], 'strategy_adjustment' => 'B']);
        Reflection::record(['insights' => ['Third insight'], 'strategy_adjustment' => 'C']);

        $reflections = Reflection::loadRecent(3);

        $this->assertCount(3, $reflections);
        $this->assertEquals('A', $reflections[0]['strategy_adjustment']);
        $this->assertEquals('B', $reflections[1]['strategy_adjustment']);
        $this->assertEquals('C', $reflections[2]['strategy_adjustment']);
    }

    public function testLoadRecentRespectsCount(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Reflection::record(['insights' => ["Insight {$i}"]]);
        }

        $reflections = Reflection::loadRecent(2);

        $this->assertCount(2, $reflections);
        // Should get the last 2
        $this->assertContains('Insight 4', $reflections[0]['insights']);
        $this->assertContains('Insight 5', $reflections[1]['insights']);
    }

    public function testParseResponseExtractsValidJson(): void
    {
        $response = '{"insights": ["Test insight"], "strategy_adjustment": "Adjust", "priority_signals": ["sig1"]}';

        $parsed = Reflection::parseResponse($response);

        $this->assertIsArray($parsed);
        $this->assertEquals(['Test insight'], $parsed['insights']);
        $this->assertEquals('Adjust', $parsed['strategy_adjustment']);
        $this->assertEquals(['sig1'], $parsed['priority_signals']);
    }

    public function testParseResponseExtractsJsonFromMarkdown(): void
    {
        $response = "Here is my reflection:\n\n```json\n{\"insights\": [\"Found pattern\"], \"strategy_adjustment\": \"Change approach\"}\n```\n\nThat's it.";

        $parsed = Reflection::parseResponse($response);

        $this->assertIsArray($parsed);
        $this->assertEquals(['Found pattern'], $parsed['insights']);
    }

    public function testParseResponseReturnsNullForInvalidJson(): void
    {
        $this->assertNull(Reflection::parseResponse('This is not JSON'));
        $this->assertNull(Reflection::parseResponse('{"incomplete":'));
    }

    public function testGetPathReturnsExpectedFormat(): void
    {
        $path = Reflection::getPath();
        $this->assertStringEndsWith('reflection_log.jsonl', $path);
    }

    public function testIntervalCyclesConstant(): void
    {
        $this->assertEquals(5, Reflection::INTERVAL_CYCLES);
    }

    public function testCooldownMsConstant(): void
    {
        $this->assertEquals(1800000, Reflection::COOLDOWN_MS);
    }
}
