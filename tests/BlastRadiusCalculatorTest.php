<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\BlastRadiusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BlastRadiusCalculator.
 */
final class BlastRadiusCalculatorTest extends TestCase
{
    private string $tempDir;
    private string $repoDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_test_' . uniqid();
        $this->repoDir = $this->tempDir . '/repo';
        
        // Create a mock git repository
        mkdir($this->repoDir . '/.git', 0755, true);
        mkdir($this->repoDir . '/src', 0755, true);
        mkdir($this->repoDir . '/tests', 0755, true);
        
        // Create some test files
        file_put_contents($this->repoDir . '/src/test.php', "<?php\n// Test file\n\$x = 1;\n");
        file_put_contents($this->repoDir . '/tests/test.php', "<?php\n// Test file\n\$y = 2;\n");
        file_put_contents($this->repoDir . '/README.md', "# Test\n");
    }

    protected function tearDown(): void
    {
        // Cleanup
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    $path = $dir . "/" . $object;
                    if (is_dir($path)) {
                        $this->recursiveDelete($path);
                    } else {
                        unlink($path);
                    }
                }
            }
            rmdir($dir);
        }
    }

    public function testConstructor(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        $this->assertInstanceOf(BlastRadiusCalculator::class, $calculator);
        
        $policy = $calculator->getPolicy();
        $this->assertIsArray($policy);
        $this->assertArrayHasKey('excludePrefixes', $policy);
        $this->assertArrayHasKey('includeExtensions', $policy);
    }

    public function testNormalizeRelPath(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        // Use reflection to test private method (setAccessible not needed in PHP 8.1+)
        $method = new \ReflectionMethod($calculator, 'normalizeRelPath');
        
        $this->assertEquals('src/test.php', $method->invoke($calculator, 'src/test.php'));
        $this->assertEquals('src/test.php', $method->invoke($calculator, '.\\src\\test.php'));
        $this->assertEquals('src/test.php', $method->invoke($calculator, './src/test.php'));
    }

    public function testMatchAnyPrefix(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'matchAnyPrefix');
        
        $prefixes = ['src/', 'tests/'];
        
        $this->assertTrue($method->invoke($calculator, 'src/test.php', $prefixes));
        $this->assertTrue($method->invoke($calculator, 'tests/test.php', $prefixes));
        $this->assertFalse($method->invoke($calculator, 'vendor/test.php', $prefixes));
    }

    public function testMatchAnyExact(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'matchAnyExact');
        
        $exacts = ['composer.json', 'package.json'];
        
        $this->assertTrue($method->invoke($calculator, 'composer.json', $exacts));
        $this->assertFalse($method->invoke($calculator, 'other.json', $exacts));
    }

    public function testMatchAnyRegex(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'matchAnyRegex');
        
        $patterns = ['capsule', 'events?\\.jsonl$'];
        
        $this->assertTrue($method->invoke($calculator, 'capsule_123.json', $patterns));
        $this->assertTrue($method->invoke($calculator, 'events.jsonl', $patterns));
        $this->assertTrue($method->invoke($calculator, 'event.jsonl', $patterns));
        $this->assertFalse($method->invoke($calculator, 'other.json', $patterns));
    }

    public function testIsConstraintCountedPath(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'isConstraintCountedPath');
        
        // PHP files should be counted
        $this->assertTrue($method->invoke($calculator, 'src/test.php'));
        $this->assertTrue($method->invoke($calculator, 'tests/test.php'));
        
        // Excluded paths should not be counted
        $this->assertFalse($method->invoke($calculator, 'vendor/test.php'));
        $this->assertFalse($method->invoke($calculator, 'node_modules/test.js'));
        
        // composer.json should be counted (includeExact)
        $this->assertTrue($method->invoke($calculator, 'composer.json'));
    }

    public function testCountFileLines(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'countFileLines');
        
        // Test file content: "<?php\n// Test file\n\$x = 1;\n" (3 lines with newline at end)
        $lineCount = $method->invoke($calculator, $this->repoDir . '/src/test.php');
        $this->assertEquals(3, $lineCount);
        
        // Non-existent file returns 0
        $this->assertEquals(0, $method->invoke($calculator, '/nonexistent/file.php'));
    }

    public function testAnalyzeDirectoryBreakdown(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'analyzeDirectoryBreakdown');
        
        $files = [
            'src/Controller.php',
            'src/Model.php',
            'src/Service/Helper.php',
            'tests/ControllerTest.php',
            'tests/ModelTest.php',
            'config/app.php',
        ];
        
        $result = $method->invoke($calculator, $files, 5);
        
        $this->assertIsArray($result);
        // With the test files, we expect:
        // - src/Controller.php, src/Model.php -> src/ (2 files, but grouped by first 2 segments)
        // - src/Service/Helper.php -> src/Service (1 file)
        // - tests/ControllerTest.php, tests/ModelTest.php -> tests/ (2 files)
        // - config/app.php -> config/ (1 file)
        $this->assertGreaterThanOrEqual(3, count($result));
        
        // Check structure
        $this->assertArrayHasKey('dir', $result[0]);
        $this->assertArrayHasKey('files', $result[0]);
    }

    public function testParseNumstatRows(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'parseNumstatRows');
        
        $input = "10\t5\tsrc/test.php\n20\t10\tsrc/other.php\n";
        $result = $method->invoke($calculator, $input);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        
        $this->assertEquals('src/test.php', $result[0]['file']);
        $this->assertEquals(10, $result[0]['added']);
        $this->assertEquals(5, $result[0]['deleted']);
    }

    public function testParseNumstatRowsWithRename(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $method = new \ReflectionMethod($calculator, 'parseNumstatRows');
        $method->setAccessible(true);
        
        // Git rename format
        $input = "10\t5\t{old => new}.php\n";
        $result = $method->invoke($calculator, $input);
        
        $this->assertCount(1, $result);
        $this->assertEquals('new.php', $result[0]['file']);
    }

    public function testGetAndSetPolicy(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $originalPolicy = $calculator->getPolicy();
        $this->assertIsArray($originalPolicy);
        
        $customPolicy = [
            'excludePrefixes' => ['custom/'],
        ];
        
        $calculator->setPolicy($customPolicy);
        
        $newPolicy = $calculator->getPolicy();
        $this->assertEquals(['custom/'], $newPolicy['excludePrefixes']);
        // Other values should remain
        $this->assertArrayHasKey('includeExtensions', $newPolicy);
    }

    public function testComputeReturnsNullWithoutGit(): void
    {
        // Create directory without .git
        $noGitDir = $this->tempDir . '/no_git';
        mkdir($noGitDir, 0755, true);
        
        $calculator = new BlastRadiusCalculator($noGitDir);
        
        // Compute should still work but git commands will fail
        $result = $calculator->compute();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('lines', $result);
    }

    public function testComputeStructure(): void
    {
        $calculator = new BlastRadiusCalculator($this->repoDir);
        
        $result = $calculator->compute();
        
        $this->assertIsArray($result);
        
        // Check all expected keys
        $expectedKeys = [
            'files', 'lines', 'linesAdded', 'linesDeleted',
            'changedFiles', 'ignoredFiles', 'allChangedFiles',
            'directoryBreakdown', 'topDirectories',
            'unstagedChurn', 'stagedChurn', 'untrackedLines',
        ];
        
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        
        // Type checks
        $this->assertIsInt($result['files']);
        $this->assertIsInt($result['lines']);
        $this->assertIsInt($result['linesAdded']);
        $this->assertIsInt($result['linesDeleted']);
        $this->assertIsArray($result['changedFiles']);
        $this->assertIsArray($result['directoryBreakdown']);
        $this->assertIsArray($result['topDirectories']);
    }
}
