<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Analyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerTest extends TestCase
{
    private string $tempFile;

    protected function tearDown(): void
    {
        if (isset($this->tempFile) && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testAnalyzeFailuresWithNoMemory(): void
    {
        $result = Analyzer::analyzeFailures('/nonexistent/path/MEMORY.md');

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('no_memory', $result['reason']);
    }

    public function testAnalyzeFailuresWithMemoryFile(): void
    {
        $content = <<<'MD'
# Memory

| ID | Type | Summary | Detail | Outcome |
|----|------|---------|--------|---------|
| **F1** | Fix | Fix bug in parser | **Added null check** (success) |
| **F2** | Fix | Memory leak | **Fixed cleanup** (partial) |
| **S1** | Success | Improved performance | **Optimized loop** (success) |
MD;

        $this->tempFile = tempnam(sys_get_temp_dir(), 'memory_');
        file_put_contents($this->tempFile, $content);

        $result = Analyzer::analyzeFailures($this->tempFile);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['failures']);
    }

    public function testAnalyzeSuccessesWithMemoryFile(): void
    {
        $content = <<<'MD'
# Memory

| ID | Type | Summary | Detail | Outcome |
|----|------|---------|--------|---------|
| **S1** | Success | Improved performance | **Optimized loop** (success) |
| **S2** | Success | Added caching | **Implemented Redis** (success) |
| **F1** | Fix | Fix bug | **Added check** (success) |
MD;

        $this->tempFile = tempnam(sys_get_temp_dir(), 'memory_');
        file_put_contents($this->tempFile, $content);

        $result = Analyzer::analyzeSuccesses($this->tempFile);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(2, $result['count']);
        $this->assertCount(2, $result['successes']);
    }

    public function testGetFullAnalysis(): void
    {
        $content = <<<'MD'
# Memory

| ID | Type | Summary | Detail | Outcome |
|----|------|---------|--------|---------|
| **F1** | Fix | Bug fix | **Fixed** (success) |
| **S1** | Success | Feature | **Added** (success) |
MD;

        $this->tempFile = tempnam(sys_get_temp_dir(), 'memory_');
        file_put_contents($this->tempFile, $content);

        $result = Analyzer::getFullAnalysis($this->tempFile);

        $this->assertArrayHasKey('failures', $result);
        $this->assertArrayHasKey('successes', $result);
        $this->assertEquals('success', $result['failures']['status']);
        $this->assertEquals('success', $result['successes']['status']);
    }

    public function testAnalyzeFailuresLimitsToThreeResults(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = "| **F{$i}** | Fix | Fix {$i} | **Detail {$i}** (success) |";
        }

        $content = "# Memory\n\n| ID | Type | Summary | Detail | Outcome |\n|----|------|---------|--------|---------|\n" . implode("\n", $rows);

        $this->tempFile = tempnam(sys_get_temp_dir(), 'memory_');
        file_put_contents($this->tempFile, $content);

        $result = Analyzer::analyzeFailures($this->tempFile);

        $this->assertEquals(5, $result['count']);
        $this->assertCount(3, $result['failures']); // Limited to 3
    }
}
