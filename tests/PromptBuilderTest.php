<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\PromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Prompt Builder tests - extracted from EvolverTest.php
 */
final class PromptBuilderTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function testBuildGepPromptContainsKeyElements(): void
    {
        $input = [
            'context' => 'Test execution context with error',
            'signals' => ['log_error', 'php_error'],
            'selector' => ['action' => 'evolve', 'gene' => 'gene_test'],
            'genesPreview' => '- [repair] gene_test | signals: log_error',
            'capsulesPreview' => '- capsule_001 | gene: gene_test | confidence: 0.9',
        ];

        $prompt = $this->builder->buildGepPrompt($input);

        $this->assertStringContainsString('GEP -- EVOLUTION PROTOCOL', $prompt);
        $this->assertStringContainsString('Mutation', $prompt);
        $this->assertStringContainsString('PersonalityState', $prompt);
        $this->assertStringContainsString('EvolutionEvent', $prompt);
        $this->assertStringContainsString('Gene', $prompt);
        $this->assertStringContainsString('Capsule', $prompt);
        $this->assertStringContainsString('log_error', $prompt);
    }

    public function testBuildReusePromptContainsKeyElements(): void
    {
        $input = [
            'capsule' => [
                'id' => 'capsule_001',
                'summary' => 'Fix null pointer exception',
                'gene' => 'gene_null_fix',
                'confidence' => 0.95,
                'trigger' => ['null_pointer'],
            ],
            'signals' => ['null_pointer'],
        ];

        $prompt = $this->builder->buildReusePrompt($input);

        $this->assertStringContainsString('GEP -- REUSE MODE', $prompt);
        $this->assertStringContainsString('capsule_001', $prompt);
        $this->assertStringContainsString('Fix null pointer exception', $prompt);
        $this->assertStringContainsString('gene_null_fix', $prompt);
        $this->assertStringContainsString('0.95', $prompt);
    }

    public function testFormatGenesPreview(): void
    {
        $genes = [
            [
                'id' => 'gene_001',
                'category' => 'repair',
                'signals_match' => ['error', 'exception'],
            ],
            [
                'id' => 'gene_002',
                'category' => 'optimize',
                'signals_match' => ['slow', 'performance'],
            ],
        ];

        $preview = $this->builder->formatGenesPreview($genes, 5);

        $this->assertStringContainsString('gene_001', $preview);
        $this->assertStringContainsString('repair', $preview);
        $this->assertStringContainsString('gene_002', $preview);
        $this->assertStringContainsString('optimize', $preview);
    }

    public function testFormatGenesPreviewEmpty(): void
    {
        $preview = $this->builder->formatGenesPreview([]);
        $this->assertEquals('(no genes available)', $preview);
    }

    public function testFormatCapsulesPreview(): void
    {
        $capsules = [
            [
                'id' => 'capsule_001',
                'gene' => 'gene_001',
                'summary' => 'Fix for error X',
                'confidence' => 0.9,
            ],
            [
                'id' => 'capsule_002',
                'gene' => 'gene_002',
                'summary' => 'Optimization Y',
                'confidence' => 0.85,
            ],
        ];

        $preview = $this->builder->formatCapsulesPreview($capsules, 3);

        $this->assertStringContainsString('capsule_001', $preview);
        $this->assertStringContainsString('capsule_002', $preview);
        $this->assertStringContainsString('Fix for error X', $preview);
        $this->assertStringContainsString('0.9', $preview);
    }

    public function testContextTruncation(): void
    {
        // Create a very long context
        $longContext = str_repeat('A', 25000);

        $input = [
            'context' => $longContext,
            'signals' => ['test'],
            'selector' => [],
        ];

        $prompt = $this->builder->buildGepPrompt($input);

        // The prompt should be truncated
        $this->assertStringContainsString('[TRUNCATED_EXECUTION_CONTEXT]', $prompt);
        $this->assertLessThan(strlen($longContext) + 5000, strlen($prompt));
    }

    public function testBuildGepPromptWithAllParams(): void
    {
        $input = [
            'context' => 'Test context',
            'signals' => ['log_error'],
            'selector' => ['action' => 'evolve'],
            'parentEventId' => 'evt_123',
            'selectedGene' => [
                'id' => 'gene_test',
                'strategy' => ['Step 1', 'Step 2'],
            ],
            'capsuleCandidates' => [],
            'genesPreview' => 'gene_001',
            'capsulesPreview' => 'capsule_001',
            'cycleId' => 5,
            'recentHistory' => [],
            'failedCapsules' => [],
            'hubLessons' => [],
        ];

        $prompt = $this->builder->buildGepPrompt($input);

        $this->assertStringContainsString('Cycle #5', $prompt);
        $this->assertStringContainsString('ACTIVE STRATEGY', $prompt);
        $this->assertStringContainsString('Step 1', $prompt);
        $this->assertStringContainsString('Step 2', $prompt);
    }

    public function testPromptContainsEnvFingerprint(): void
    {
        $input = [
            'context' => 'Test',
            'signals' => ['test'],
            'selector' => [],
        ];

        $prompt = $this->builder->buildGepPrompt($input);

        // Should contain environment fingerprint
        $this->assertStringContainsString('php_version', $prompt);
        $this->assertStringContainsString('platform', $prompt);
    }
}
