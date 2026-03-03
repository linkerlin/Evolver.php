<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\PromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Prompt Builder Enhanced Tests - Task 1.3 Hub matching and health report
 */
final class PromptBuilderEnhancedTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    // -------------------------------------------------------------------------
    // Hub matched block tests
    // -------------------------------------------------------------------------

    public function testBuildHubMatchedBlock(): void
    {
        $hubResults = [
            [
                'id' => 'capsule_001',
                'gene' => 'gene_error_fix',
                'summary' => 'Fix for null pointer exception',
                'confidence' => 0.95,
                'source_node' => 'node_abc123',
            ],
            [
                'id' => 'capsule_002',
                'gene' => 'gene_perf_opt',
                'summary' => 'Performance optimization for loops',
                'confidence' => 0.87,
                'source_node' => 'node_def456',
            ],
        ];

        $result = $this->builder->buildHubMatchedBlock($hubResults);

        $this->assertStringContainsString('Hub Matched Solutions', $result);
        $this->assertStringContainsString('capsule_001', $result);
        $this->assertStringContainsString('gene_error_fix', $result);
        $this->assertStringContainsString('0.95', $result);
        $this->assertStringContainsString('node_abc123', $result);
    }

    public function testBuildHubMatchedBlockEmpty(): void
    {
        $result = $this->builder->buildHubMatchedBlock([]);

        $this->assertEquals('(no hub match)', $result);
    }

    public function testBuildHubMatchedBlockLimitsResults(): void
    {
        $hubResults = [];
        for ($i = 0; $i < 10; $i++) {
            $hubResults[] = [
                'id' => 'capsule_' . $i,
                'gene' => 'gene_' . $i,
                'summary' => 'Summary ' . $i,
                'confidence' => 0.5 + ($i * 0.05),
                'source_node' => 'node_' . $i,
            ];
        }

        $result = $this->builder->buildHubMatchedBlock($hubResults);

        // Should show only first 3 + ellipsis
        $this->assertStringContainsString('(7 more matches)', $result);
    }

    // -------------------------------------------------------------------------
    // Health report tests
    // -------------------------------------------------------------------------

    public function testBuildHealthReport(): void
    {
        $health = [
            'status' => 'healthy',
            'success_rate' => 0.85,
            'recent_failures' => 2,
            'active_cycles' => 10,
            'warnings' => ['High memory usage', 'Slow response time'],
        ];

        $result = $this->builder->buildHealthReport($health);

        $this->assertStringContainsString('System Health Report', $result);
        $this->assertStringContainsString('Status: healthy', $result);
        $this->assertStringContainsString('85%', $result);
        $this->assertStringContainsString('Recent Failures: 2', $result);
        $this->assertStringContainsString('High memory usage', $result);
    }

    public function testBuildHealthReportDefaults(): void
    {
        $result = $this->builder->buildHealthReport([]);

        $this->assertStringContainsString('System Health Report', $result);
        $this->assertStringContainsString('Status: unknown', $result);
        $this->assertStringContainsString('0%', $result);
    }

    public function testBuildHealthReportNoWarnings(): void
    {
        $health = [
            'status' => 'healthy',
            'success_rate' => 0.95,
        ];

        $result = $this->builder->buildHealthReport($health);

        $this->assertStringContainsString('System Health Report', $result);
        $this->assertStringNotContainsString('Warnings:', $result);
    }

    // -------------------------------------------------------------------------
    // Mutation directive tests
    // -------------------------------------------------------------------------

    public function testBuildMutationDirectiveRepairLoop(): void
    {
        $signals = ['log_error'];
        $context = ['repair_loop' => true];

        $result = $this->builder->buildMutationDirective($signals, $context);

        $this->assertStringContainsString('REPAIR LOOP DETECTED', $result);
        $this->assertStringContainsString('INNOVATE', $result);
    }

    public function testBuildMutationDirectiveStagnation(): void
    {
        $signals = [];
        $context = ['stagnation' => true];

        $result = $this->builder->buildMutationDirective($signals, $context);

        $this->assertStringContainsString('STAGNATION DETECTED', $result);
    }

    public function testBuildMutationDirectiveSaturation(): void
    {
        $signals = [];
        $context = ['saturation' => true];

        $result = $this->builder->buildMutationDirective($signals, $context);

        $this->assertStringContainsString('EVOLUTION SATURATION', $result);
    }

    public function testBuildMutationDirectiveOpportunity(): void
    {
        $signals = ['user_feature_request:add new button'];
        $context = [];

        $result = $this->builder->buildMutationDirective($signals, $context);

        $this->assertStringContainsString('OPPORTUNITY DETECTED', $result);
    }

    public function testBuildMutationDirectiveDefault(): void
    {
        $signals = ['log_error'];
        $context = [];

        $result = $this->builder->buildMutationDirective($signals, $context);

        $this->assertStringContainsString('Standard evolution', $result);
    }

    // -------------------------------------------------------------------------
    // Mood block tests
    // -------------------------------------------------------------------------

    public function testBuildMoodBlockCautious(): void
    {
        $personality = ['rigor' => 0.8, 'creativity' => 0.4, 'risk_tolerance' => 0.3];

        $result = $this->builder->buildMoodBlock($personality);

        $this->assertStringContainsString('Current Mood: cautious', $result);
        $this->assertStringContainsString('High rigor, low risk tolerance', $result);
    }

    public function testBuildMoodBlockExploratory(): void
    {
        $personality = ['rigor' => 0.4, 'creativity' => 0.8, 'risk_tolerance' => 0.7];

        $result = $this->builder->buildMoodBlock($personality);

        $this->assertStringContainsString('Current Mood: exploratory', $result);
    }

    public function testBuildMoodBlockRelaxed(): void
    {
        $personality = ['rigor' => 0.3, 'creativity' => 0.5, 'risk_tolerance' => 0.5];

        $result = $this->builder->buildMoodBlock($personality);

        $this->assertStringContainsString('Current Mood: relaxed', $result);
    }

    public function testBuildMoodBlockBalanced(): void
    {
        $personality = ['rigor' => 0.5, 'creativity' => 0.5, 'risk_tolerance' => 0.5];

        $result = $this->builder->buildMoodBlock($personality);

        $this->assertStringContainsString('Current Mood: balanced', $result);
    }

    // -------------------------------------------------------------------------
    // Skills list tests
    // -------------------------------------------------------------------------

    public function testFormatSkillsList(): void
    {
        $skills = [
            ['name' => 'git-sync', 'category' => 'dev', 'status' => 'active'],
            ['name' => 'feishu-post', 'category' => 'integration', 'status' => 'active'],
            ['name' => 'image-processor', 'category' => 'media', 'status' => 'beta'],
        ];

        $result = $this->builder->formatSkillsList($skills);

        $this->assertStringContainsString('git-sync [dev] (active)', $result);
        $this->assertStringContainsString('feishu-post [integration] (active)', $result);
        $this->assertStringContainsString('image-processor [media] (beta)', $result);
    }

    public function testFormatSkillsListEmpty(): void
    {
        $result = $this->builder->formatSkillsList([]);

        $this->assertEquals('(no skills available)', $result);
    }

    public function testFormatSkillsListLimitsResults(): void
    {
        $skills = [];
        for ($i = 0; $i < 20; $i++) {
            $skills[] = ['name' => 'skill_' . $i, 'category' => 'general'];
        }

        $result = $this->builder->formatSkillsList($skills);

        $this->assertStringContainsString('(10 more)', $result);
    }
}
