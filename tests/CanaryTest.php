<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Canary;
use PHPUnit\Framework\TestCase;

final class CanaryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/canary_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    public function testCheckWithValidPhpFile(): void
    {
        $validFile = $this->tempDir . '/valid.php';
        file_put_contents($validFile, '<?php echo "Hello";');

        $result = Canary::check($validFile);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
    }

    public function testCheckWithNonexistentFile(): void
    {
        $result = Canary::check('/nonexistent/file.php');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', $result['error']);
    }

    public function testCheckWithSyntaxError(): void
    {
        $invalidFile = $this->tempDir . '/invalid.php';
        file_put_contents($invalidFile, '<?php echo "missing semicolon"');

        $result = Canary::check($invalidFile);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Syntax error', $result['error']);
    }

    public function testCheckMultipleAllValid(): void
    {
        $file1 = $this->tempDir . '/file1.php';
        $file2 = $this->tempDir . '/file2.php';
        file_put_contents($file1, '<?php echo "1";');
        file_put_contents($file2, '<?php echo "2";');

        $result = Canary::checkMultiple([$file1, $file2]);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function testCheckMultipleWithOneError(): void
    {
        $validFile = $this->tempDir . '/valid.php';
        $invalidFile = $this->tempDir . '/invalid.php';
        file_put_contents($validFile, '<?php echo "ok";');
        file_put_contents($invalidFile, '<?php echo "missing');

        $result = Canary::checkMultiple([$validFile, $invalidFile]);

        $this->assertFalse($result['ok']);
        $this->assertCount(1, $result['errors']);
        $this->assertArrayHasKey($invalidFile, $result['errors']);
    }

    public function testCheckMultipleWithNonexistentFile(): void
    {
        $result = Canary::checkMultiple(['/nonexistent/file.php']);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('/nonexistent/file.php', $result['errors']);
    }

    public function testCheckMultipleEmptyArray(): void
    {
        $result = Canary::checkMultiple([]);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }
}
