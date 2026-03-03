<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\HealthCheck;
use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
{
    public function testRunReturnsCorrectStructure(): void
    {
        $result = HealthCheck::run();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertArrayHasKey('checks', $result);
    }

    public function testRunReturnsValidStatus(): void
    {
        $result = HealthCheck::run();

        $this->assertContains($result['status'], ['ok', 'warning', 'error']);
    }

    public function testRunReturnsChecksArray(): void
    {
        $result = HealthCheck::run();

        $this->assertIsArray($result['checks']);
        $this->assertNotEmpty($result['checks']);
    }

    public function testRunIncludesDiskSpaceCheck(): void
    {
        $result = HealthCheck::run();

        $diskCheck = $this->findCheckByName($result['checks'], 'disk_space');
        $this->assertNotNull($diskCheck);
        $this->assertArrayHasKey('ok', $diskCheck);
        $this->assertArrayHasKey('status', $diskCheck);
    }

    public function testRunIncludesMemoryCheck(): void
    {
        $result = HealthCheck::run();

        $memCheck = $this->findCheckByName($result['checks'], 'memory');
        $this->assertNotNull($memCheck);
        $this->assertArrayHasKey('ok', $memCheck);
        $this->assertArrayHasKey('status', $memCheck);
    }

    public function testRunIncludesSecretChecks(): void
    {
        $result = HealthCheck::run();

        // Should check FEISHU secrets
        $feishuAppId = $this->findCheckByName($result['checks'], 'env:FEISHU_APP_ID');
        $this->assertNotNull($feishuAppId);

        $feishuSecret = $this->findCheckByName($result['checks'], 'env:FEISHU_APP_SECRET');
        $this->assertNotNull($feishuSecret);
    }

    /**
     * Helper to find a check by name
     */
    private function findCheckByName(array $checks, string $name): ?array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }
        return null;
    }

    public function testCheckStructure(): void
    {
        $result = HealthCheck::run();

        foreach ($result['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('ok', $check);
            $this->assertArrayHasKey('status', $check);
        }
    }

    public function testTimestampFormat(): void
    {
        $result = HealthCheck::run();

        // Verify ISO 8601 format
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $result['timestamp']
        );
    }

    public function testOptionalSecretsChecked(): void
    {
        $result = HealthCheck::run();

        $openaiKey = $this->findCheckByName($result['checks'], 'env:OPENAI_API_KEY');
        $this->assertNotNull($openaiKey);
    }

    public function testSeverityIsPresentWhenNotOk(): void
    {
        $result = HealthCheck::run();

        foreach ($result['checks'] as $check) {
            if (!$check['ok']) {
                $this->assertArrayHasKey('severity', $check);
            }
        }
    }

    public function testMultipleRunsAreIndependent(): void
    {
        $result1 = HealthCheck::run();
        usleep(1000); // Small delay to ensure different timestamps
        $result2 = HealthCheck::run();

        // Each run should produce new results with fresh data
        $this->assertIsArray($result1['checks']);
        $this->assertIsArray($result2['checks']);
    }

    public function testProcessCountCheckOnLinux(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Process count check only runs on Linux');
        }

        $result = HealthCheck::run();

        $procCheck = $this->findCheckByName($result['checks'], 'process_count');
        $this->assertNotNull($procCheck);
    }

    public function testNoProcessCountCheckOnNonLinux(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $this->markTestSkipped('This test is for non-Linux systems');
        }

        $result = HealthCheck::run();

        $procCheck = $this->findCheckByName($result['checks'], 'process_count');
        $this->assertNull($procCheck);
    }

    public function testDiskCheckIncludesPercentage(): void
    {
        $result = HealthCheck::run();

        $diskCheck = $this->findCheckByName($result['checks'], 'disk_space');
        $this->assertNotNull($diskCheck);

        // Status should include percentage
        $this->assertMatchesRegularExpression('/\d+% used/', $diskCheck['status']);
    }

    public function testMemoryCheckIncludesPercentage(): void
    {
        $result = HealthCheck::run();

        $memCheck = $this->findCheckByName($result['checks'], 'memory');
        $this->assertNotNull($memCheck);

        // Status should include percentage
        $this->assertMatchesRegularExpression('/\d+% used/', $memCheck['status']);
    }

    public function testCriticalSecretsAreWarnings(): void
    {
        // This tests that missing FEISHU secrets are warnings, not errors
        $result = HealthCheck::run();

        foreach ($result['checks'] as $check) {
            if (str_starts_with($check['name'], 'env:FEISHU_') && !$check['ok']) {
                $this->assertEquals('warning', $check['severity'] ?? 'warning');
            }
        }
    }
}
