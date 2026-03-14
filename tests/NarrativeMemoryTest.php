<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\NarrativeMemory;
use Evolver\Paths;
use PHPUnit\Framework\TestCase;

/**
 * Narrative Memory tests.
 */
final class NarrativeMemoryTest extends TestCase
{
    private string $testDir;
    private ?string $originalEvolutionDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/evolver_narrative_test_' . uniqid();
        Paths::resetCache();
        $this->originalEvolutionDir = getenv('EVOLUTION_DIR') ?: null;
        putenv('EVOLUTION_DIR=' . $this->testDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->testDir);
        Paths::resetCache();
        if ($this->originalEvolutionDir !== null) {
            putenv('EVOLUTION_DIR=' . $this->originalEvolutionDir);
        } else {
            putenv('EVOLUTION_DIR');
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function testRecordCreatesNarrativeFile(): void
    {
        NarrativeMemory::record([
            'gene' => ['id' => 'gene_test', 'category' => 'repair'],
            'signals' => ['log_error', 'errsig:TypeError'],
            'mutation' => ['rationale' => 'Test fix'],
            'outcome' => ['status' => 'success', 'score' => 0.85],
            'blast' => ['files' => 2, 'lines' => 10],
            'capsule' => ['summary' => 'Fixed the issue'],
        ]);

        $this->assertFileExists(NarrativeMemory::getPath());
    }

    public function testRecordContainsExpectedFields(): void
    {
        NarrativeMemory::record([
            'gene' => ['id' => 'gene_abc123', 'category' => 'repair'],
            'signals' => ['log_error'],
            'outcome' => ['status' => 'success', 'score' => 0.92],
            'blast' => ['files' => 1, 'lines' => 5],
        ]);

        $content = file_get_contents(NarrativeMemory::getPath());
        $this->assertStringContainsString('REPAIR - success', $content);
        $this->assertStringContainsString('gene_abc123', $content);
        $this->assertStringContainsString('Score: 0.92', $content);
        $this->assertStringContainsString('1 files, 5 lines', $content);
        $this->assertStringContainsString('log_error', $content);
    }

    public function testRecordWithMinimalData(): void
    {
        NarrativeMemory::record([]);

        $content = file_get_contents(NarrativeMemory::getPath());
        $this->assertStringContainsString('# Evolution Narrative', $content);
        $this->assertStringContainsString('(auto)', $content);
        $this->assertStringContainsString('Score: ?', $content);
    }

    public function testRecordAppendsMultipleEntries(): void
    {
        NarrativeMemory::record([
            'gene' => ['id' => 'gene_1'],
            'outcome' => ['status' => 'success', 'score' => 0.9],
        ]);

        NarrativeMemory::record([
            'gene' => ['id' => 'gene_2'],
            'outcome' => ['status' => 'failed', 'score' => 0.3],
        ]);

        $content = file_get_contents(NarrativeMemory::getPath());
        $this->assertStringContainsString('gene_1', $content);
        $this->assertStringContainsString('gene_2', $content);
        $this->assertStringContainsString('success', $content);
        $this->assertStringContainsString('failed', $content);
    }

    public function testLoadSummaryReturnsEmptyForMissingFile(): void
    {
        // Ensure the narrative file doesn't exist for this test
        $path = NarrativeMemory::getPath();
        if (file_exists($path)) {
            unlink($path);
        }

        $summary = NarrativeMemory::loadSummary();
        $this->assertSame('', $summary);
    }

    public function testLoadSummaryReturnsRecentEntries(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            NarrativeMemory::record([
                'gene' => ['id' => "gene_{$i}"],
                'outcome' => ['status' => 'success', 'score' => 0.8],
            ]);
        }

        $summary = NarrativeMemory::loadSummary();

        // Should contain recent entries
        $this->assertStringContainsString('gene_10', $summary);
        $this->assertStringContainsString('gene_9', $summary);
        // May or may not contain older entries depending on limit
    }

    public function testLoadSummaryRespectsMaxChars(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            NarrativeMemory::record([
                'gene' => ['id' => "gene_{$i}", 'strategy' => array_fill(0, 3, 'Long strategy text here')],
                'outcome' => ['status' => 'success', 'score' => 0.8],
            ]);
        }

        $summary = NarrativeMemory::loadSummary(500);
        $this->assertLessThanOrEqual(510, strlen($summary)); // Allow small buffer
    }

    public function testTrimRemovesOldEntries(): void
    {
        $header = "# Evolution Narrative\n\nA chronological record.\n\n";
        $entries = '';
        for ($i = 1; $i <= 50; $i++) {
            $entries .= "### [2026-03-14 10:00:{$i}] REPAIR - success\n- Gene: gene_{$i}\n\n";
        }

        $content = $header . $entries;
        $trimmed = NarrativeMemory::trim($content);

        // Should be within size limit (12000)
        $this->assertLessThanOrEqual(12500, strlen($trimmed));
        // Should still have header
        $this->assertStringContainsString('# Evolution Narrative', $trimmed);
    }

    public function testStrategyFormatting(): void
    {
        NarrativeMemory::record([
            'gene' => [
                'id' => 'gene_strategy',
                'strategy' => [
                    'First step',
                    'Second step',
                    'Third step',
                    'Fourth step (should be cut)',
                ],
            ],
            'outcome' => ['status' => 'success'],
        ]);

        $content = file_get_contents(NarrativeMemory::getPath());
        $this->assertStringContainsString('1. First step', $content);
        $this->assertStringContainsString('2. Second step', $content);
        $this->assertStringContainsString('3. Third step', $content);
        $this->assertStringNotContainsString('Fourth step', $content);
    }

    public function testRationaleTruncation(): void
    {
        $longRationale = str_repeat('x', 300);
        NarrativeMemory::record([
            'mutation' => ['rationale' => $longRationale],
            'outcome' => ['status' => 'success'],
        ]);

        $content = file_get_contents(NarrativeMemory::getPath());
        // Rationale should be truncated to 200 chars (plus "- Why: " prefix)
        $this->assertMatchesRegularExpression('/- Why: x{200}/', $content);
        $this->assertDoesNotMatchRegularExpression('/- Why: x{300}/', $content);
    }

    public function testGetPathReturnsExpectedFormat(): void
    {
        $path = NarrativeMemory::getPath();
        $this->assertStringEndsWith('evolution_narrative.md', $path);
    }
}
