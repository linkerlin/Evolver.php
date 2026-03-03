<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SourceProtector;
use PHPUnit\Framework\TestCase;

/**
 * Source Protector tests - extracted from EvolverTest.php
 */
final class SourceProtectorTest extends TestCase
{
    private SourceProtector $protector;

    protected function setUp(): void
    {
        $this->protector = new SourceProtector();
    }

    public function testSourceProtectorIsProtected(): void
    {
        // Core files should be protected
        $this->assertTrue($this->protector->isProtected('src/McpServer.php'));
        $this->assertTrue($this->protector->isProtected('src/Database.php'));
        $this->assertTrue($this->protector->isProtected('evolver.php'));

        // Pattern matches
        $this->assertTrue($this->protector->isProtected('vendor/autoload.php'));
        $this->assertTrue($this->protector->isProtected('src/Ops/DiskCleaner.php'));

        // User files should not be protected
        $this->assertFalse($this->protector->isProtected('user_file.php'));
        $this->assertFalse($this->protector->isProtected('app/MyClass.php'));
    }

    public function testSourceProtectorGetProjectRoot(): void
    {
        $root = $this->protector->getProjectRoot();

        $this->assertNotNull($root);
        $this->assertDirectoryExists($root);
        $this->assertDirectoryExists($root . '/src');
    }

    public function testValidateFilesAllowsSafe(): void
    {
        $files = ['user_script.php', 'config/settings.php'];
        $result = $this->protector->validateFiles($files);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['violations']);
    }

    public function testValidateFilesDetectsProtected(): void
    {
        $files = ['user_script.php', 'src/McpServer.php'];
        $result = $this->protector->validateFiles($files);

        $this->assertFalse($result['ok']);
        $this->assertContains('src/McpServer.php', $result['violations']);
    }

    public function testAssertSafeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected');

        $this->protector->assertSafe(['src/Database.php']);
    }

    public function testAssertSafePasses(): void
    {
        // Should not throw for safe files
        $this->protector->assertSafe(['user_file.php']);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testCanBypass(): void
    {
        // By default, bypass should not be allowed
        putenv('EVOLVER_BYPASS_PROTECTION');
        $this->assertFalse(SourceProtector::canBypass());

        // Enable bypass
        putenv('EVOLVER_BYPASS_PROTECTION=1');
        $this->assertTrue(SourceProtector::canBypass());

        // Reset
        putenv('EVOLVER_BYPASS_PROTECTION');
    }

    public function testAddProtectedPaths(): void
    {
        // Add a protected path - note: addProtectedPaths normalizes to absolute paths
        // so we need to use a path that will match correctly
        $this->protector->addProtectedPaths(['/custom_protected_file.php']);

        // The path should now be protected
        $this->assertTrue($this->protector->isProtected('/custom_protected_file.php'));
    }

    public function testGetProtectedPaths(): void
    {
        $paths = $this->protector->getProtectedPaths();

        $this->assertIsArray($paths);
        $this->assertNotEmpty($paths);
        $this->assertContains('src/McpServer.php', $paths);
    }

    public function testGetProtectionReport(): void
    {
        $report = $this->protector->getProtectionReport();

        $this->assertArrayHasKey('project_root', $report);
        $this->assertArrayHasKey('protected_paths', $report);
        $this->assertArrayHasKey('protected_patterns', $report);
        $this->assertArrayHasKey('bypass_available', $report);
    }
}
