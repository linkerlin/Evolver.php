<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\DaemonManager;
use PHPUnit\Framework\TestCase;

final class DaemonManagerTest extends TestCase
{
    private string $tempDir;
    private DaemonManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_daemon_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->manager = new DaemonManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (file_exists($this->tempDir . '/evolver.pid')) {
            @unlink($this->tempDir . '/evolver.pid');
        }
        if (file_exists($this->tempDir . '/daemon.log')) {
            @unlink($this->tempDir . '/daemon.log');
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testConstructorCreatesDirectory(): void
    {
        $newDir = sys_get_temp_dir() . '/evolver_daemon_new_' . uniqid();
        new DaemonManager($newDir);

        $this->assertDirectoryExists($newDir);
        rmdir($newDir);
    }

    public function testIsRunningReturnsFalseWhenNoPidFile(): void
    {
        $this->assertFalse($this->manager->isRunning());
    }

    public function testGetPidReturnsNullWhenNoPidFile(): void
    {
        $this->assertNull($this->manager->getPid());
    }

    public function testGetStatusReturnsCorrectStructure(): void
    {
        $status = $this->manager->getStatus();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('pid', $status);
        $this->assertArrayHasKey('pid_file', $status);
        $this->assertArrayHasKey('log_file', $status);
        $this->assertFalse($status['running']);
        $this->assertNull($status['pid']);
    }

    public function testWritePidFile(): void
    {
        $result = $this->manager->writePidFile(12345);

        $this->assertTrue($result);
        $this->assertEquals(12345, $this->manager->getPid());
        $this->assertFileExists($this->tempDir . '/evolver.pid');
    }

    public function testWritePidFileCreatesCorrectContent(): void
    {
        $this->manager->writePidFile(54321);

        $content = file_get_contents($this->tempDir . '/evolver.pid');
        $this->assertEquals('54321', $content);
    }

    public function testRemovePidFile(): void
    {
        $this->manager->writePidFile(12345);
        $this->assertFileExists($this->tempDir . '/evolver.pid');

        $result = $this->manager->removePidFile();

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->tempDir . '/evolver.pid');
    }

    public function testRemovePidFileWhenNotExists(): void
    {
        $result = $this->manager->removePidFile();

        $this->assertTrue($result);
    }

    public function testGetLogReturnsEmptyWhenNoLogFile(): void
    {
        $log = $this->manager->getLog();

        $this->assertIsArray($log);
        $this->assertArrayHasKey('lines', $log);
        $this->assertArrayHasKey('total', $log);
        $this->assertEmpty($log['lines']);
        $this->assertEquals(0, $log['total']);
    }

    public function testGetLogReturnsContent(): void
    {
        file_put_contents($this->tempDir . '/daemon.log', "Line 1\nLine 2\nLine 3\n");

        $log = $this->manager->getLog(10);

        $this->assertEquals(3, $log['total']);
        $this->assertCount(3, $log['lines']);
    }

    public function testGetLogRespectsLineLimit(): void
    {
        file_put_contents($this->tempDir . '/daemon.log', implode("\n", range(1, 100)) . "\n");

        $log = $this->manager->getLog(10);

        $this->assertEquals(100, $log['total']);
        $this->assertCount(10, $log['lines']);
    }

    public function testGetPidReturnsNullForInvalidPidFile(): void
    {
        file_put_contents($this->tempDir . '/evolver.pid', 'invalid');

        $this->assertNull($this->manager->getPid());
    }

    public function testGetPidReturnsNullForZeroPid(): void
    {
        file_put_contents($this->tempDir . '/evolver.pid', '0');

        $this->assertNull($this->manager->getPid());
    }

    public function testGetPidReturnsNullForNegativePid(): void
    {
        file_put_contents($this->tempDir . '/evolver.pid', '-1');

        $this->assertNull($this->manager->getPid());
    }

    public function testIsRunningReturnsFalseForNonExistentProcess(): void
    {
        // Write a PID that definitely doesn't exist
        $this->manager->writePidFile(99999999);

        // On most systems, this PID won't exist
        $this->assertFalse($this->manager->isRunning());
    }

    public function testStartFailsWhenAlreadyRunning(): void
    {
        // Write a fake PID file
        $this->manager->writePidFile(getmypid());

        // This should fail because isRunning will return true (our own PID)
        $result = $this->manager->start();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals(getmypid(), $result['pid']);
    }

    public function testStopFailsWhenNotRunning(): void
    {
        $result = $this->manager->stop();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testStopFailsWhenNoPid(): void
    {
        // PID file exists but invalid
        file_put_contents($this->tempDir . '/evolver.pid', '');

        $result = $this->manager->stop();

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testPidFilePathIsCorrect(): void
    {
        $status = $this->manager->getStatus();

        $this->assertEquals($this->tempDir . '/evolver.pid', $status['pid_file']);
    }

    public function testLogFilePathIsCorrect(): void
    {
        $status = $this->manager->getStatus();

        $this->assertEquals($this->tempDir . '/daemon.log', $status['log_file']);
    }

    public function testRestartCallsStopAndStart(): void
    {
        // When no daemon is running, start should work (but will likely fail due to proc_open)
        // We just verify the method structure
        $result = $this->manager->restart();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
    }

    public function testDefaultDataDirIsUsed(): void
    {
        $manager = new DaemonManager();

        $status = $manager->getStatus();

        $this->assertStringContainsString('data', $status['pid_file']);
    }
}
