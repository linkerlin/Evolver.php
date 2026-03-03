<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\DeviceId;
use PHPUnit\Framework\TestCase;

final class DeviceIdTest extends TestCase
{
    protected function tearDown(): void
    {
        DeviceId::reset();
    }

    public function testGetDeviceIdReturnsString(): void
    {
        $id = DeviceId::getDeviceId();

        $this->assertIsString($id);
        $this->assertGreaterThanOrEqual(16, strlen($id));
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $id);
    }

    public function testGetDeviceIdReturnsCachedValue(): void
    {
        $id1 = DeviceId::getDeviceId();
        $id2 = DeviceId::getDeviceId();

        $this->assertEquals($id1, $id2);
    }

    public function testGetDeviceIdWithEnvOverride(): void
    {
        $testId = '0123456789abcdef0123456789abcdef';
        putenv('EVOMAP_DEVICE_ID=' . $testId);
        DeviceId::reset();

        $id = DeviceId::getDeviceId();

        $this->assertEquals($testId, $id);

        putenv('EVOMAP_DEVICE_ID');
        DeviceId::reset();
    }

    public function testGetDeviceIdIgnoresInvalidEnvOverride(): void
    {
        putenv('EVOMAP_DEVICE_ID=invalid');
        DeviceId::reset();

        $id = DeviceId::getDeviceId();

        // Should fall back to generated ID
        $this->assertNotEquals('invalid', $id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16,64}$/', $id);

        putenv('EVOMAP_DEVICE_ID');
        DeviceId::reset();
    }

    public function testIsContainerReturnsBool(): void
    {
        $result = DeviceId::isContainer();

        $this->assertIsBool($result);
    }

    public function testResetClearsCache(): void
    {
        $id1 = DeviceId::getDeviceId();
        DeviceId::reset();
        $id2 = DeviceId::getDeviceId();

        // Should return same ID since it's persisted
        $this->assertEquals($id1, $id2);
    }

    public function testDeviceIdFormat(): void
    {
        $id = DeviceId::getDeviceId();

        // Should be lowercase hex
        $this->assertEquals(strtolower($id), $id);

        // Should be at least 16 chars (64-bit minimum)
        $this->assertGreaterThanOrEqual(16, strlen($id));
    }
}
