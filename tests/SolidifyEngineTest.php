<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\GepAssetStore;
use Evolver\GeneSelector;
use Evolver\SignalExtractor;
use Evolver\SolidifyEngine;
use PHPUnit\Framework\TestCase;

/**
 * Solidify Engine tests - extracted from EvolverTest.php
 */
final class SolidifyEngineTest extends TestCase
{
    private Database $db;
    private GepAssetStore $store;
    private SignalExtractor $signalExtractor;
    private GeneSelector $geneSelector;
    private SolidifyEngine $solidifyEngine;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->store = new GepAssetStore($this->db);
        $this->signalExtractor = new SignalExtractor();
        $this->geneSelector = new GeneSelector();
        $this->solidifyEngine = new SolidifyEngine($this->store, $this->signalExtractor, $this->geneSelector);
    }

    public function testSolidifyDryRun(): void
    {
        $input = [
            'intent' => 'repair',
            'summary' => 'Test solidify',
            'signals' => ['log_error'],
            'gene' => [
                'type' => 'Gene',
                'id' => 'gene_test',
                'category' => 'repair',
                'signals_match' => ['log_error'],
                'strategy' => ['Fix the error'],
                'constraints' => ['max_files' => 10, 'forbidden_paths' => []],
            ],
            'capsule' => [
                'type' => 'Capsule',
                'id' => 'capsule_test',
                'trigger' => ['log_error'],
                'gene' => 'gene_test',
                'summary' => 'Fixed the error',
                'confidence' => 0.9,
            ],
            'event' => [
                'type' => 'EvolutionEvent',
                'id' => 'evt_001',
                'intent' => 'repair',
                'signals' => ['log_error'],
                'genes_used' => ['gene_test'],
                'outcome' => ['status' => 'success', 'score' => 0.9],
            ],
            'blastRadius' => ['files' => 2, 'lines' => 10],
            'dryRun' => true,
        ];

        $result = $this->solidifyEngine->solidify($input);

        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('gene', $result);
        $this->assertArrayHasKey('capsule', $result);
    }

    public function testSolidifyWritesToStore(): void
    {
        $input = [
            'intent' => 'repair',
            'summary' => 'Test solidify write',
            'signals' => ['test_signal'],
            'gene' => [
                'type' => 'Gene',
                'id' => 'gene_new',
                'category' => 'repair',
                'signals_match' => ['test_signal'],
                'strategy' => ['Analyze', 'Fix'],
                'constraints' => ['max_files' => 5, 'forbidden_paths' => []],
            ],
            'capsule' => [
                'type' => 'Capsule',
                'id' => 'capsule_new',
                'trigger' => ['test_signal'],
                'gene' => 'gene_new',
                'summary' => 'Test capsule',
                'confidence' => 0.9,
            ],
            'event' => [
                'type' => 'EvolutionEvent',
                'id' => 'evt_002',
                'intent' => 'repair',
                'signals' => ['test_signal'],
                'genes_used' => ['gene_new'],
                'outcome' => ['status' => 'success', 'score' => 0.95],
            ],
            'blastRadius' => ['files' => 1, 'lines' => 5],
            'dryRun' => false,
        ];

        $result = $this->solidifyEngine->solidify($input);

        $this->assertArrayHasKey('event', $result);
    }

    public function testSolidifyBlastRadiusViolation(): void
    {
        $input = [
            'intent' => 'repair',
            'summary' => 'Test blast radius',
            'signals' => ['error'],
            'gene' => [
                'type' => 'Gene',
                'id' => 'gene_test',
                'category' => 'repair',
                'signals_match' => ['error'],
                'constraints' => ['max_files' => 5, 'forbidden_paths' => []],
            ],
            'blastRadius' => ['files' => 100, 'lines' => 50000], // Exceeds limits
            'dryRun' => false,
        ];

        $result = $this->solidifyEngine->solidify($input);

        // Should have violations
        $this->assertArrayHasKey('violations', $result);
        $this->assertNotEmpty($result['violations']);
    }

    public function testSolidifyEventContainsEnvFingerprint(): void
    {
        $input = [
            'intent' => 'repair',
            'summary' => 'Test env fingerprint',
            'signals' => ['error'],
            'gene' => [
                'type' => 'Gene',
                'id' => 'gene_fp',
                'category' => 'repair',
                'signals_match' => ['error'],
            ],
            'event' => [
                'type' => 'EvolutionEvent',
                'id' => 'evt_fp',
                'env_fingerprint' => [
                    'php_version' => '8.3.0',
                    'platform' => 'linux',
                ],
                'outcome' => ['status' => 'success', 'score' => 0.9],
            ],
            'blastRadius' => ['files' => 1, 'lines' => 5],
            'dryRun' => true,
        ];

        $result = $this->solidifyEngine->solidify($input);

        $this->assertArrayHasKey('event', $result);
    }

    public function testIsCriticalProtectedPath(): void
    {
        // Protected paths
        $this->assertTrue(SolidifyEngine::isCriticalProtectedPath('skills/feishu-evolver-wrapper/index.php'));
        $this->assertTrue(SolidifyEngine::isCriticalProtectedPath('MEMORY.md'));
        $this->assertTrue(SolidifyEngine::isCriticalProtectedPath('composer.json'));

        // Non-protected paths
        $this->assertFalse(SolidifyEngine::isCriticalProtectedPath('src/MyClass.php'));
        $this->assertFalse(SolidifyEngine::isCriticalProtectedPath('user_script.php'));
    }

    public function testValidateCommand(): void
    {
        // Whitelisted commands
        $this->assertTrue($this->solidifyEngine->isValidationCommandAllowed('php --version'));
        $this->assertTrue($this->solidifyEngine->isValidationCommandAllowed('composer validate'));
        $this->assertTrue($this->solidifyEngine->isValidationCommandAllowed('phpunit tests/'));

        // Commands with shell operators should be blocked
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('php script.php; rm -rf /'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('php script.php && rm -rf /'));
    }
}
