<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\StructuredLogger;
use PHPUnit\Framework\TestCase;

final class StructuredLoggerTest extends TestCase
{
    private string $tempDir;
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_structlog_test_' . uniqid();
        $this->logger = new StructuredLogger(
            $this->tempDir,
            10 * 1024 * 1024, // 10MB max size
            5, // max files
            StructuredLogger::LEVEL_DEBUG // Log everything
        );
    }

    protected function tearDown(): void
    {
        // Clean up all log files
        $patterns = [
            $this->tempDir . '/evolver.log*',
            $this->tempDir . '/evolution.log*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testConstructorCreatesDirectory(): void
    {
        $newDir = sys_get_temp_dir() . '/evolver_structlog_new_' . uniqid();
        new StructuredLogger($newDir);

        $this->assertDirectoryExists($newDir);
        rmdir($newDir);
    }

    public function testLogInfo(): void
    {
        $this->logger->info('Test message', ['key' => 'value']);

        $logFile = $this->tempDir . '/evolver.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Test message', $content);
        $this->assertStringContainsString('info', $content);
    }

    public function testLogDebug(): void
    {
        $this->logger->debug('Debug message');

        $logFile = $this->tempDir . '/evolver.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Debug message', $content);
    }

    public function testLogWarning(): void
    {
        $this->logger->warning('Warning message');

        $logFile = $this->tempDir . '/evolver.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Warning message', $content);
        $this->assertStringContainsString('warning', $content);
    }

    public function testLogError(): void
    {
        $this->logger->error('Error message');

        $logFile = $this->tempDir . '/evolver.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Error message', $content);
        $this->assertStringContainsString('error', $content);
    }

    public function testLogCritical(): void
    {
        $this->logger->critical('Critical message');

        $logFile = $this->tempDir . '/evolver.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('Critical message', $content);
        $this->assertStringContainsString('critical', $content);
    }

    public function testLogEvolutionSeparateFile(): void
    {
        $this->logger->logEvolution(['event' => 'mutation', 'data' => ['gene' => 'test']]);

        $evoFile = $this->tempDir . '/evolution.log';
        $this->assertFileExists($evoFile);

        $content = file_get_contents($evoFile);
        $this->assertStringContainsString('mutation', $content);
    }

    public function testLogEntryStructure(): void
    {
        $this->logger->info('Test', ['context_key' => 'context_value']);

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $entry = json_decode(trim($content), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('level', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('context', $entry);
        $this->assertArrayHasKey('pid', $entry);
    }

    public function testMinLevelFiltering(): void
    {
        $logger = new StructuredLogger(
            $this->tempDir,
            10 * 1024 * 1024,
            5,
            StructuredLogger::LEVEL_WARNING
        );

        $logger->debug('Should not log');
        $logger->info('Should not log');
        $logger->warning('Should log');
        $logger->error('Should log');

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(2, $lines);
    }

    public function testGetLogFiles(): void
    {
        $files = $this->logger->getLogFiles();

        $this->assertArrayHasKey('main', $files);
        $this->assertArrayHasKey('evolution', $files);
        $this->assertEquals($this->tempDir . '/evolver.log', $files['main']);
        $this->assertEquals($this->tempDir . '/evolution.log', $files['evolution']);
    }

    public function testGetLogSize(): void
    {
        $this->logger->info('Test message');

        $sizes = $this->logger->getLogSize();

        $this->assertArrayHasKey('main', $sizes);
        $this->assertArrayHasKey('evolution', $sizes);
        $this->assertGreaterThan(0, $sizes['main']);
        $this->assertEquals(0, $sizes['evolution']);
    }

    public function testGetLogSizeBeforeLogging(): void
    {
        $logger = new StructuredLogger($this->tempDir);
        $sizes = $logger->getLogSize();

        $this->assertEquals(0, $sizes['main']);
        $this->assertEquals(0, $sizes['evolution']);
    }

    public function testCleanOldLogs(): void
    {
        $this->logger->info('Test message');

        // Clean with 0 days attempts to remove old logs
        // Logs created just now won't be removed
        $count = $this->logger->cleanOldLogs(0);

        // Just verify the method runs and returns a count
        $this->assertIsInt($count);
    }

    public function testCleanOldLogsKeepsRecent(): void
    {
        $this->logger->info('Test message');

        // Clean with 30 days should keep recent logs
        $count = $this->logger->cleanOldLogs(30);

        $this->assertEquals(0, $count);
        $this->assertFileExists($this->tempDir . '/evolver.log');
    }

    public function testLogLevelConstants(): void
    {
        $this->assertEquals('debug', StructuredLogger::LEVEL_DEBUG);
        $this->assertEquals('info', StructuredLogger::LEVEL_INFO);
        $this->assertEquals('warning', StructuredLogger::LEVEL_WARNING);
        $this->assertEquals('error', StructuredLogger::LEVEL_ERROR);
        $this->assertEquals('critical', StructuredLogger::LEVEL_CRITICAL);
    }

    public function testContextIsPreserved(): void
    {
        $context = [
            'key1' => 'value1',
            'key2' => ['nested' => 'data'],
            'key3' => 123,
        ];

        $this->logger->info('Test', $context);

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $entry = json_decode(trim($content), true);

        $this->assertEquals($context, $entry['context']);
    }

    public function testPidIsIncluded(): void
    {
        $this->logger->info('Test');

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $entry = json_decode(trim($content), true);

        $this->assertEquals(getmypid(), $entry['pid']);
    }

    public function testTimestampFormat(): void
    {
        $this->logger->info('Test');

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $entry = json_decode(trim($content), true);

        // ATOM format (ISO 8601)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $entry['timestamp']
        );
    }

    public function testEvolutionLogEntryStructure(): void
    {
        $this->logger->logEvolution(['type' => 'mutation']);

        $content = file_get_contents($this->tempDir . '/evolution.log');
        $entry = json_decode(trim($content), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('event', $entry);
    }

    public function testMultipleLogEntries(): void
    {
        $this->logger->info('First');
        $this->logger->info('Second');
        $this->logger->info('Third');

        $content = file_get_contents($this->tempDir . '/evolver.log');
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(3, $lines);
    }

    public function testUnicodeHandling(): void
    {
        $this->logger->info('Unicode test: 你好世界 🎉');

        $content = file_get_contents($this->tempDir . '/evolver.log');

        $this->assertStringContainsString('你好世界', $content);
    }
}
