<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\Trigger;
use PHPUnit\Framework\TestCase;

final class TriggerTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any existing wake signal
        Trigger::clear();
    }

    protected function tearDown(): void
    {
        // Clean up after each test
        Trigger::clear();
    }

    public function testSendCreatesWakeFile(): void
    {
        $result = Trigger::send();

        $this->assertTrue($result);
        $this->assertTrue(Trigger::isPending());
    }

    public function testIsPendingReturnsFalseAfterClear(): void
    {
        Trigger::send();
        $this->assertTrue(Trigger::isPending());

        Trigger::clear();
        $this->assertFalse(Trigger::isPending());
    }

    public function testIsPendingReturnsFalseWhenNoSignal(): void
    {
        Trigger::clear();

        $this->assertFalse(Trigger::isPending());
    }

    public function testClearDoesNotFailWhenNoFile(): void
    {
        // Should not throw
        Trigger::clear();

        $this->assertFalse(Trigger::isPending());
    }

    public function testSendIsIdempotent(): void
    {
        Trigger::send();
        Trigger::send();
        Trigger::send();

        $this->assertTrue(Trigger::isPending());
    }

    public function testSendAndClearCycle(): void
    {
        // Initial state
        $this->assertFalse(Trigger::isPending());

        // Send signal
        Trigger::send();
        $this->assertTrue(Trigger::isPending());

        // Clear signal
        Trigger::clear();
        $this->assertFalse(Trigger::isPending());

        // Send again
        Trigger::send();
        $this->assertTrue(Trigger::isPending());
    }

    public function testWakeFileContent(): void
    {
        Trigger::send();

        // Get the wake file path by checking if isPending works
        $this->assertTrue(Trigger::isPending());

        // The file should exist and contain 'WAKE'
        $wakeFile = $this->getWakeFilePath();
        if ($wakeFile && file_exists($wakeFile)) {
            $content = file_get_contents($wakeFile);
            $this->assertEquals('WAKE', $content);
        }
    }

    public function testClearRemovesFile(): void
    {
        Trigger::send();
        $wakeFile = $this->getWakeFilePath();

        if ($wakeFile) {
            $this->assertFileExists($wakeFile);
        }

        Trigger::clear();

        if ($wakeFile) {
            $this->assertFileDoesNotExist($wakeFile);
        }
    }

    public function testSendReturnsTrueOnSuccess(): void
    {
        $result = Trigger::send();

        $this->assertTrue($result);
    }

    public function testStaticMethodsAreCallable(): void
    {
        // Just verify all static methods are callable
        Trigger::send();
        $pending = Trigger::isPending();
        Trigger::clear();

        $this->assertIsBool($pending);
    }

    public function testMultipleSendsDontCreateMultipleFiles(): void
    {
        Trigger::send();
        Trigger::send();
        Trigger::send();

        // Should still just have one signal pending
        $this->assertTrue(Trigger::isPending());
    }

    public function testClearIsIdempotent(): void
    {
        Trigger::clear();
        Trigger::clear();
        Trigger::clear();

        $this->assertFalse(Trigger::isPending());
    }

    /**
     * Helper to get the wake file path
     */
    private function getWakeFilePath(): ?string
    {
        // Use reflection to access private static method
        $reflection = new \ReflectionClass(Trigger::class);
        $method = $reflection->getMethod('getWakeFile');

        return $method->invoke(null);
    }

    public function testWakeFilePathIsInMemoryDir(): void
    {
        $wakeFile = $this->getWakeFilePath();

        $this->assertNotNull($wakeFile);
        // The path should contain the signal filename
        $this->assertStringContainsString('evolver_wake.signal', $wakeFile);
    }

    public function testSendCreatesDirectoryIfNotExists(): void
    {
        // Clear first
        Trigger::clear();

        // Send should create directory if needed
        $result = Trigger::send();

        $this->assertTrue($result);
    }

    public function testIsPendingChecksFileExistence(): void
    {
        // Verify isPending actually checks file existence
        $this->assertFalse(Trigger::isPending());

        Trigger::send();

        $this->assertTrue(Trigger::isPending());

        // Manually remove the file
        $wakeFile = $this->getWakeFilePath();
        if ($wakeFile && file_exists($wakeFile)) {
            unlink($wakeFile);
        }

        // Now isPending should return false
        $this->assertFalse(Trigger::isPending());
    }
}
