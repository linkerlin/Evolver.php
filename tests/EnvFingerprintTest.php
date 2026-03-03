<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\EnvFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Environment Fingerprint tests - extracted from EvolverTest.php
 */
final class EnvFingerprintTest extends TestCase
{
    public function testCaptureFingerprintStructure(): void
    {
        $fingerprint = EnvFingerprint::capture();

        $this->assertIsArray($fingerprint);
        $this->assertArrayHasKey('device_id', $fingerprint);
        $this->assertArrayHasKey('php_version', $fingerprint);
        $this->assertArrayHasKey('platform', $fingerprint);
        $this->assertArrayHasKey('arch', $fingerprint);
        $this->assertArrayHasKey('cwd', $fingerprint);
        $this->assertArrayHasKey('container', $fingerprint);
    }

    public function testCaptureFingerprintValues(): void
    {
        $fingerprint = EnvFingerprint::capture();

        $this->assertStringStartsWith('PHP/', $fingerprint['php_version']);
        $this->assertContains($fingerprint['platform'], ['linux', 'darwin', 'win32', 'freebsd', 'openbsd']);
        $this->assertNotEmpty($fingerprint['arch']);
        $this->assertNotEmpty($fingerprint['cwd']);
        $this->assertIsBool($fingerprint['container']);
    }

    public function testKeyIsDeterministic(): void
    {
        $fp1 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];
        $fp2 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];

        $key1 = EnvFingerprint::key($fp1);
        $key2 = EnvFingerprint::key($fp2);

        $this->assertEquals($key1, $key2);
        $this->assertEquals(16, strlen($key1)); // 16-char hex
    }

    public function testKeyChangesWhenFingerprintChanges(): void
    {
        $fp1 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];
        $fp2 = ['device_id' => 'def', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];

        $key1 = EnvFingerprint::key($fp1);
        $key2 = EnvFingerprint::key($fp2);

        $this->assertNotEquals($key1, $key2);
    }

    public function testIsSameEnvClassTrue(): void
    {
        $fp1 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];
        $fp2 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];

        $this->assertTrue(EnvFingerprint::isSameEnvClass($fp1, $fp2));
    }

    public function testIsSameEnvClassFalse(): void
    {
        $fp1 = ['device_id' => 'abc', 'php_version' => '8.3.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];
        $fp2 = ['device_id' => 'def', 'php_version' => '8.2.0', 'platform' => 'Linux', 'arch' => 'x64', 'hostname' => 'test', 'client' => 'evolver', 'client_version' => '1.0'];

        $this->assertFalse(EnvFingerprint::isSameEnvClass($fp1, $fp2));
    }

    public function testKeyOnEmptyArray(): void
    {
        $key = EnvFingerprint::key([]);

        $this->assertEquals('unknown', $key);
    }

    public function testGetDeviceIdIsCached(): void
    {
        $id1 = EnvFingerprint::getDeviceId();
        $id2 = EnvFingerprint::getDeviceId();

        $this->assertEquals($id1, $id2);
        $this->assertGreaterThanOrEqual(16, strlen($id1));
    }

    public function testIsContainerReturnsBool(): void
    {
        $isContainer = EnvFingerprint::isContainer();

        $this->assertIsBool($isContainer);
    }
}
