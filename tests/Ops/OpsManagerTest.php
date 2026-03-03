<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Database;
use Evolver\GepAssetStore;
use Evolver\Ops\OpsManager;
use PHPUnit\Framework\TestCase;

final class OpsManagerTest extends TestCase
{
    private string $tempDir;
    private OpsManager $manager;
    private ?Database $db = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_ops_test_' . uniqid();
        mkdir($this->tempDir . '/logs', 0755, true);
        mkdir($this->tempDir . '/archive', 0755, true);

        // Create in-memory database for testing
        $this->db = new Database(':memory:');
        $this->manager = new OpsManager($this->tempDir, $this->db);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->recursiveDelete($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function testListCommandsReturnsArray(): void
    {
        $commands = $this->manager->listCommands();

        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);
    }

    public function testListCommandsIncludesExpectedCommands(): void
    {
        $commands = $this->manager->listCommands();

        $this->assertArrayHasKey('cleanup', $commands);
        $this->assertArrayHasKey('health', $commands);
        $this->assertArrayHasKey('stats', $commands);
        $this->assertArrayHasKey('gc', $commands);
        $this->assertArrayHasKey('help', $commands);
    }

    public function testRunUnknownCommand(): void
    {
        $result = $this->manager->run('nonexistent');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('available_commands', $result);
    }

    public function testRunHelpCommand(): void
    {
        $result = $this->manager->run('help');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('commands', $result['result']);
        $this->assertArrayHasKey('usage', $result['result']);
        $this->assertArrayHasKey('examples', $result['result']);
    }

    public function testRunHealthCommand(): void
    {
        $result = $this->manager->run('health');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('timestamp', $result['result']);
        $this->assertArrayHasKey('disk_space', $result['result']);
        $this->assertArrayHasKey('database', $result['result']);
    }

    public function testRunStatsCommand(): void
    {
        $result = $this->manager->run('stats');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('timestamp', $result['result']);
        $this->assertArrayHasKey('disk', $result['result']);
        $this->assertArrayHasKey('assets', $result['result']);
    }

    public function testRunCleanupDryRun(): void
    {
        $result = $this->manager->run('cleanup', ['dry-run' => true]);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('result', $result);
        $this->assertArrayHasKey('message', $result['result']);
        $this->assertStringContainsString('Dry run', $result['result']['message']);
    }

    public function testRunCleanupDryRunUnderscore(): void
    {
        $result = $this->manager->run('cleanup', ['dry_run' => true]);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Dry run', $result['result']['message']);
    }

    public function testRunCleanupActual(): void
    {
        $result = $this->manager->run('cleanup');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('result', $result);
    }

    public function testRunGcWithoutDatabase(): void
    {
        $manager = new OpsManager($this->tempDir, null);
        $result = $manager->run('gc');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Database not available', $result['error']);
    }

    public function testRunGcWithDatabase(): void
    {
        $result = $this->manager->run('gc', ['dry_run' => true]);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('result', $result);
        $this->assertTrue($result['result']['dry_run']);
    }

    public function testRunDedupeCommand(): void
    {
        $result = $this->manager->run('dedupe');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('result', $result);
    }

    public function testRunDedupeAlias(): void
    {
        $result = $this->manager->run('deduplicate');

        $this->assertTrue($result['ok']);
    }

    public function testFormatOutputWithError(): void
    {
        $result = ['error' => 'Test error'];
        $output = OpsManager::formatOutput($result);

        $this->assertStringContainsString('Error', $output);
        $this->assertStringContainsString('Test error', $output);
    }

    public function testFormatOutputWithUnknownCommand(): void
    {
        $result = [
            'error' => 'Unknown command',
            'available_commands' => ['help', 'cleanup']
        ];
        $output = OpsManager::formatOutput($result);

        // When there's an 'error' key, it only shows the error
        $this->assertStringContainsString('Error', $output);
        $this->assertStringContainsString('Unknown command', $output);
    }

    public function testFormatOutputWithAvailableCommands(): void
    {
        // Without 'error' key, but with 'available_commands'
        $result = [
            'available_commands' => ['help', 'cleanup']
        ];
        $output = OpsManager::formatOutput($result);

        $this->assertStringContainsString('Unknown command', $output);
        $this->assertStringContainsString('help', $output);
        $this->assertStringContainsString('cleanup', $output);
    }

    public function testFormatOutputWithResult(): void
    {
        $result = ['ok' => true, 'result' => ['key' => 'value']];
        $output = OpsManager::formatOutput($result);

        $this->assertStringContainsString('key', $output);
        $this->assertStringContainsString('value', $output);
    }

    public function testFormatOutputReturnsJson(): void
    {
        $result = ['ok' => true, 'result' => ['test' => 123]];
        $output = OpsManager::formatOutput($result);

        // Should be valid JSON
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    public function testHealthCheckIncludesDatabaseInfo(): void
    {
        $result = $this->manager->run('health');

        $this->assertNotNull($result['result']['database']);
        $this->assertArrayHasKey('ok', $result['result']['database']);
    }

    public function testStatsIncludesAssetsInfo(): void
    {
        $result = $this->manager->run('stats');

        $this->assertIsArray($result['result']['assets']);
    }

    public function testGcRespectsMaxAgeDays(): void
    {
        $result = $this->manager->run('gc', ['dry_run' => true, 'max_age_days' => 30]);

        $this->assertTrue($result['ok']);
    }

    public function testGcRespectsMaxEvents(): void
    {
        $result = $this->manager->run('gc', ['dry_run' => true, 'max_events' => 500]);

        $this->assertTrue($result['ok']);
    }

    public function testDedupeRespectsSinceOption(): void
    {
        $result = $this->manager->run('dedupe', ['since' => 7200]);

        $this->assertTrue($result['ok']);
    }

    public function testConstructorWithoutDatabase(): void
    {
        $manager = new OpsManager($this->tempDir);

        $result = $manager->run('health');
        $this->assertNull($result['result']['database']['ok']);
    }

    public function testConstructorWithNullDataDir(): void
    {
        $manager = new OpsManager(null, $this->db);

        $commands = $manager->listCommands();
        $this->assertNotEmpty($commands);
    }
}
