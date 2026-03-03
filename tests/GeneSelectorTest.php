<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\GeneSelector;
use PHPUnit\Framework\TestCase;

/**
 * Gene Selector tests - extracted from EvolverTest.php
 */
final class GeneSelectorTest extends TestCase
{
    private GeneSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new GeneSelector();
    }

    public function testMatchPatternToSignals(): void
    {
        $signals = ['log_error', 'php_error'];
        $this->assertTrue($this->selector->matchPatternToSignals('log_error', $signals));
        // Substring match (lowercase)
        $this->assertTrue($this->selector->matchPatternToSignals('error', $signals));
        $this->assertFalse($this->selector->matchPatternToSignals('network', $signals));
    }

    public function testScoreGene(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'signals_match' => ['log_error', '*warning*'],
            'category' => 'repair',
        ];
        $signals = ['log_error', 'php_warning'];

        $score = $this->selector->scoreGene($gene, $signals);

        $this->assertGreaterThan(0, $score);
    }

    public function testSelectGeneBySignals(): void
    {
        $genes = [
            [
                'type' => 'Gene',
                'id' => 'gene_error_handler',
                'category' => 'repair',
                'signals_match' => ['log_error'],
                'preconditions' => [],
                'strategy' => ['Analyze error', 'Fix issue'],
                'constraints' => ['max_files' => 5, 'forbidden_paths' => []],
                'validation' => ['php lint'],
            ],
            [
                'type' => 'Gene',
                'id' => 'gene_other',
                'category' => 'optimize',
                'signals_match' => ['perf_bottleneck'],
                'preconditions' => [],
                'strategy' => ['Optimize'],
                'constraints' => ['max_files' => 10, 'forbidden_paths' => []],
            ],
        ];

        $signals = ['log_error', 'php_parse_error'];
        $result = $this->selector->selectGene($genes, $signals);

        $this->assertNotNull($result['selected']);
        $this->assertEquals('gene_error_handler', $result['selected']['id']);
    }

    public function testSelectGeneNoMatch(): void
    {
        $genes = [
            [
                'type' => 'Gene',
                'id' => 'gene_test',
                'signals_match' => ['unknown_signal'],
            ],
        ];
        // Signal that doesn't match any gene pattern
        $signals = ['totally_different_signal'];
        $result = $this->selector->selectGene($genes, $signals);

        // Should return null when no matching gene found
        $this->assertNull($result['selected']);
    }

    public function testSelectGeneWithBannedGenes(): void
    {
        // Use many genes to keep driftIntensity low (< 0.15) so banned gene filtering is applied
        // With 50 genes, driftIntensity = 1/sqrt(50) ≈ 0.14, which is < 0.15
        $genes = [];
        for ($i = 0; $i < 48; $i++) {
            $genes[] = [
                'type' => 'Gene',
                'id' => 'gene_other_' . $i,
                'signals_match' => ['other_signal'], // Doesn't match our test signal
            ];
        }

        // Add the genes we actually care about
        $genes[] = [
            'type' => 'Gene',
            'id' => 'gene_allowed',
            'signals_match' => ['error'], // Matches = score 1
        ];
        $genes[] = [
            'type' => 'Gene',
            'id' => 'gene_banned',
            'signals_match' => ['error'], // Matches = score 1
        ];

        $signals = ['error'];
        $opts = ['bannedGeneIds' => ['gene_banned']];
        $result = $this->selector->selectGene($genes, $signals, $opts);

        // With low drift intensity, banned genes should be filtered out
        $this->assertNotNull($result['selected']);
        $this->assertNotEquals('gene_banned', $result['selected']['id']);
    }

    public function testSelectGeneWithPreferredGene(): void
    {
        $genes = [
            [
                'type' => 'Gene',
                'id' => 'gene_first',
                'signals_match' => ['error'],
            ],
            [
                'type' => 'Gene',
                'id' => 'gene_preferred',
                'signals_match' => ['error'],
            ],
        ];

        $signals = ['error'];
        $opts = ['preferredGeneId' => 'gene_preferred'];
        $result = $this->selector->selectGene($genes, $signals, $opts);

        $this->assertNotNull($result['selected']);
        $this->assertEquals('gene_preferred', $result['selected']['id']);
    }

    public function testDriftIntensityCalculation(): void
    {
        $genes = [
            ['type' => 'Gene', 'id' => 'gene_1', 'signals_match' => ['signal']],
        ];
        $signals = ['signal'];

        // With drift enabled
        $result = $this->selector->selectGene($genes, $signals, ['driftEnabled' => true]);
        $this->assertGreaterThan(0, $result['driftIntensity']);

        // Without drift
        $result = $this->selector->selectGene($genes, $signals, ['driftEnabled' => false]);
        $this->assertGreaterThanOrEqual(0, $result['driftIntensity']);
    }
}
