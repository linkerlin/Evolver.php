<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SkillDistiller;
use PHPUnit\Framework\TestCase;

final class SkillDistillerTest extends TestCase
{
    private string $tempDir;
    private SkillDistiller $distiller;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/evolver_distiller_test_' . uniqid();
        mkdir($this->tempDir . '/memory', 0755, true);
        mkdir($this->tempDir . '/gep/assets', 0755, true);
        mkdir($this->tempDir . '/evolution', 0755, true);

        $this->distiller = new SkillDistiller($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up recursively
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->recursiveDelete($file) : unlink($file);
        }
        rmdir($dir);
    }

    // =========================================================================
    // Constants Tests
    // =========================================================================

    public function testGetDistilledIdPrefixReturnsCorrectValue(): void
    {
        $this->assertSame('gene_distilled_', SkillDistiller::getDistilledIdPrefix());
    }

    public function testGetDistilledMaxFilesReturnsCorrectValue(): void
    {
        $this->assertSame(12, SkillDistiller::getDistilledMaxFiles());
    }

    // =========================================================================
    // Collect Distillation Data Tests
    // =========================================================================

    public function testCollectDistillationDataWithNoData(): void
    {
        $data = $this->distiller->collectDistillationData();

        $this->assertEmpty($data['successCapsules']);
        $this->assertArrayHasKey('grouped', $data);
        $this->assertArrayHasKey('dataHash', $data);
    }

    public function testCollectDistillationDataWithCapsulesFile(): void
    {
        // Create a test capsules file
        $capsules = [
            'capsules' => [
                [
                    'id' => 'cap_1',
                    'gene' => 'gene_test',
                    'outcome' => ['status' => 'success', 'score' => 0.9],
                    'trigger' => ['error'],
                    'summary' => 'Fixed issue',
                ],
                [
                    'id' => 'cap_2',
                    'gene' => 'gene_test',
                    'outcome' => ['status' => 'failed'],
                    'trigger' => ['error'],
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/gep/assets/capsules.json',
            json_encode($capsules)
        );

        $data = $this->distiller->collectDistillationData();

        $this->assertCount(1, $data['successCapsules']); // Only the successful one
        $this->assertArrayHasKey('gene_test', $data['grouped']);
    }

    // =========================================================================
    // Analyze Patterns Tests
    // =========================================================================

    public function testAnalyzePatternsReturnsCorrectStructure(): void
    {
        $data = [
            'successCapsules' => [],
            'allCapsules' => [],
            'grouped' => [],
            'events' => [],
        ];

        $report = $this->distiller->analyzePatterns($data);

        $this->assertArrayHasKey('high_frequency', $report);
        $this->assertArrayHasKey('strategy_drift', $report);
        $this->assertArrayHasKey('coverage_gaps', $report);
        $this->assertArrayHasKey('total_success', $report);
        $this->assertArrayHasKey('success_rate', $report);
    }

    public function testAnalyzePatternsIdentifiesHighFrequencyGenes(): void
    {
        $data = [
            'successCapsules' => [
                ['gene' => 'popular_gene', 'outcome' => ['status' => 'success', 'score' => 0.9], 'trigger' => ['a']],
                ['gene' => 'popular_gene', 'outcome' => ['status' => 'success', 'score' => 0.9], 'trigger' => ['b']],
                ['gene' => 'popular_gene', 'outcome' => ['status' => 'success', 'score' => 0.9], 'trigger' => ['c']],
                ['gene' => 'popular_gene', 'outcome' => ['status' => 'success', 'score' => 0.9], 'trigger' => ['d']],
                ['gene' => 'popular_gene', 'outcome' => ['status' => 'success', 'score' => 0.9], 'trigger' => ['e']],
            ],
            'allCapsules' => [],
            'grouped' => [
                'popular_gene' => [
                    'gene_id' => 'popular_gene',
                    'capsules' => [],
                    'total_count' => 5,
                    'total_score' => 4.5,
                    'triggers' => [['a'], ['b'], ['c'], ['d'], ['e']],
                    'summaries' => ['Fix 1', 'Fix 2', 'Fix 3'],
                ],
            ],
            'events' => [],
        ];

        $report = $this->distiller->analyzePatterns($data);

        $this->assertCount(1, $report['high_frequency']);
        $this->assertSame('popular_gene', $report['high_frequency'][0]['gene_id']);
    }

    // =========================================================================
    // Extract JSON from LLM Response Tests
    // =========================================================================

    public function testExtractJsonFromLlmResponseWithValidGene(): void
    {
        $response = 'Here is the gene: {"type":"Gene","id":"test_gene","category":"repair","signals_match":["error"],"strategy":["fix"]}';

        $gene = $this->distiller->extractJsonFromLlmResponse($response);

        $this->assertNotNull($gene);
        $this->assertSame('Gene', $gene['type']);
        $this->assertSame('test_gene', $gene['id']);
    }

    public function testExtractJsonFromLlmResponseWithNoGene(): void
    {
        $response = 'No gene here, just text.';

        $gene = $this->distiller->extractJsonFromLlmResponse($response);

        $this->assertNull($gene);
    }

    public function testExtractJsonFromLlmResponseWithMultipleObjects(): void
    {
        $response = '{"type":"Other"}{"type":"Gene","id":"real_gene"}';

        $gene = $this->distiller->extractJsonFromLlmResponse($response);

        $this->assertNotNull($gene);
        $this->assertSame('real_gene', $gene['id']);
    }

    // =========================================================================
    // Build Distillation Prompt Tests
    // =========================================================================

    public function testBuildDistillationPromptContainsRequiredElements(): void
    {
        $analysis = ['high_frequency' => [], 'strategy_drift' => []];
        $existingGenes = [['id' => 'gene_1', 'category' => 'repair']];
        $sampleCapsules = [
            ['gene' => 'gene_1', 'trigger' => ['error'], 'summary' => 'Fixed'],
        ];

        $prompt = $this->distiller->buildDistillationPrompt($analysis, $existingGenes, $sampleCapsules);

        $this->assertStringContainsString('Gene synthesis engine', $prompt);
        $this->assertStringContainsString('gene_distilled_', $prompt);
        $this->assertStringContainsString('constraints.max_files', $prompt);
    }

    // =========================================================================
    // Validate Synthesized Gene Tests
    // =========================================================================

    public function testValidateSynthesizedGeneWithValidGene(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_distilled_test',
            'category' => 'repair',
            'signals_match' => ['error', 'syntax'],
            'strategy' => ['Fix the syntax error'],
            'constraints' => [
                'max_files' => 5,
                'forbidden_paths' => ['.git', 'vendor'],
            ],
        ];

        $result = $this->distiller->validateSynthesizedGene($gene, []);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateSynthesizedGeneWithMissingType(): void
    {
        $gene = [
            'id' => 'gene_test',
            'category' => 'repair',
            'signals_match' => ['error'],
            'strategy' => ['Fix'],
        ];

        $result = $this->distiller->validateSynthesizedGene($gene, []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateSynthesizedGeneAddsIdPrefix(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'test_gene',
            'category' => 'repair',
            'signals_match' => ['error'],
            'strategy' => ['Fix'],
            'constraints' => ['forbidden_paths' => ['.git'], 'max_files' => 5],
        ];

        $result = $this->distiller->validateSynthesizedGene($gene, []);

        $this->assertSame('gene_distilled_test_gene', $result['gene']['id']);
    }

    public function testValidateSynthesizedGeneAddsDefaultConstraints(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_distilled_test',
            'category' => 'repair',
            'signals_match' => ['error'],
            'strategy' => ['Fix'],
        ];

        $result = $this->distiller->validateSynthesizedGene($gene, []);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('constraints', $result['gene']);
        $this->assertContains('.git', $result['gene']['constraints']['forbidden_paths']);
    }

    public function testValidateSynthesizedGeneRejectsDuplicateSignals(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_distilled_new',
            'category' => 'repair',
            'signals_match' => ['error', 'syntax'],
            'strategy' => ['Fix'],
            'constraints' => ['forbidden_paths' => ['.git'], 'max_files' => 5],
        ];

        $existingGenes = [
            [
                'id' => 'existing_gene',
                'signals_match' => ['error', 'syntax'], // Exact overlap
            ],
        ];

        $result = $this->distiller->validateSynthesizedGene($gene, $existingGenes);

        $this->assertFalse($result['valid']);
    }

    // =========================================================================
    // Should Distill Tests
    // =========================================================================

    public function testShouldDistillReturnsFalseWhenDisabled(): void
    {
        $original = getenv('SKILL_DISTILLER');
        putenv('SKILL_DISTILLER=false');

        $should = $this->distiller->shouldDistill();

        // Restore
        if ($original !== false) {
            putenv("SKILL_DISTILLER={$original}");
        } else {
            putenv('SKILL_DISTILLER');
        }

        $this->assertFalse($should);
    }

    public function testShouldDistillReturnsFalseWithNoCapsules(): void
    {
        $original = getenv('SKILL_DISTILLER');
        putenv('SKILL_DISTILLER=true');

        $should = $this->distiller->shouldDistill();

        // Restore
        if ($original !== false) {
            putenv("SKILL_DISTILLER={$original}");
        } else {
            putenv('SKILL_DISTILLER');
        }

        $this->assertFalse($should);
    }

    // =========================================================================
    // Prepare Distillation Tests
    // =========================================================================

    public function testPrepareDistillationReturnsInsufficientDataWithNoCapsules(): void
    {
        $result = $this->distiller->prepareDistillation();

        $this->assertFalse($result['ok']);
        $this->assertSame('insufficient_data', $result['reason']);
    }

    // =========================================================================
    // Complete Distillation Tests
    // =========================================================================

    public function testCompleteDistillationReturnsNoRequestWithoutPrepare(): void
    {
        $response = '{"type":"Gene","id":"test"}';
        $result = $this->distiller->completeDistillation($response);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_request', $result['reason']);
    }
}
