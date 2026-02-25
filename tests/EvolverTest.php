<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\GepAssetStore;
use Evolver\GeneSelector;
use Evolver\SignalExtractor;
use Evolver\PromptBuilder;
use Evolver\SolidifyEngine;
use PHPUnit\Framework\TestCase;

class EvolverTest extends TestCase
{
    private Database $db;
    private GepAssetStore $store;
    private SignalExtractor $signalExtractor;
    private GeneSelector $geneSelector;
    private PromptBuilder $promptBuilder;
    private SolidifyEngine $solidifyEngine;

    protected function setUp(): void
    {
        // Use in-memory SQLite for tests
        $this->db = new Database(':memory:');
        $this->store = new GepAssetStore($this->db);
        $this->signalExtractor = new SignalExtractor();
        $this->geneSelector = new GeneSelector();
        $this->promptBuilder = new PromptBuilder();
        $this->solidifyEngine = new SolidifyEngine($this->store, $this->signalExtractor, $this->geneSelector);
    }

    // -------------------------------------------------------------------------
    // Database tests
    // -------------------------------------------------------------------------

    public function testDatabaseCreatesSchema(): void
    {
        $result = $this->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $tables = array_column($result, 'name');
        $this->assertContains('genes', $tables);
        $this->assertContains('capsules', $tables);
        $this->assertContains('events', $tables);
        $this->assertContains('failed_capsules', $tables);
    }

    public function testDatabasePragmasSet(): void
    {
        // In-memory SQLite uses 'memory' journal mode (WAL not supported for :memory:)
        // Test that journal_mode pragma is readable and returns a valid value
        $journalMode = $this->db->fetchOne('PRAGMA journal_mode');
        $mode = strtolower($journalMode['journal_mode'] ?? '');
        $this->assertContains($mode, ['wal', 'memory', 'delete', 'truncate', 'persist']);
    }

    // -------------------------------------------------------------------------
    // GepAssetStore tests
    // -------------------------------------------------------------------------

    public function testSeedDefaultGenes(): void
    {
        // Default genes should be seeded from data/default_genes.json
        $genes = $this->store->loadGenes();
        $this->assertNotEmpty($genes, 'Default genes should be seeded');
        $this->assertGreaterThanOrEqual(3, count($genes));
    }

    public function testUpsertAndLoadGene(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test_001',
            'category' => 'repair',
            'signals_match' => ['error', 'test'],
            'strategy' => ['step 1', 'step 2'],
            'constraints' => ['max_files' => 10],
            'validation' => ['php -l src/test.php'],
        ];

        $this->store->upsertGene($gene);
        $loaded = $this->store->getGene('gene_test_001');
        $this->assertNotNull($loaded);
        $this->assertSame('gene_test_001', $loaded['id']);
        $this->assertSame('repair', $loaded['category']);
        $this->assertSame(['error', 'test'], $loaded['signals_match']);
    }

    public function testUpsertGeneUpdatesExisting(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_update_test',
            'category' => 'repair',
            'signals_match' => ['error'],
            'strategy' => ['original step'],
        ];
        $this->store->upsertGene($gene);

        $gene['strategy'] = ['updated step'];
        $this->store->upsertGene($gene);

        $loaded = $this->store->getGene('gene_update_test');
        $this->assertSame(['updated step'], $loaded['strategy']);
    }

    public function testAppendAndLoadCapsule(): void
    {
        $capsule = [
            'type' => 'Capsule',
            'id' => 'capsule_test_001',
            'gene' => 'gene_gep_repair_from_errors',
            'trigger' => ['log_error'],
            'summary' => 'Fixed test error',
            'confidence' => 0.9,
            'blast_radius' => ['files' => 2, 'lines' => 15],
        ];

        $this->store->appendCapsule($capsule);
        $capsules = $this->store->loadCapsules(10);
        $this->assertNotEmpty($capsules);

        $found = null;
        foreach ($capsules as $c) {
            if ($c['id'] === 'capsule_test_001') {
                $found = $c;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('Fixed test error', $found['summary']);
        $this->assertSame(0.9, $found['confidence']);
    }

    public function testAppendAndLoadEvent(): void
    {
        $event = [
            'type' => 'EvolutionEvent',
            'id' => 'evt_test_001',
            'intent' => 'repair',
            'signals' => ['log_error', 'errsig:test'],
            'genes_used' => ['gene_gep_repair_from_errors'],
            'blast_radius' => ['files' => 1, 'lines' => 5],
            'outcome' => ['status' => 'success', 'score' => 0.8],
        ];

        $this->store->appendEvent($event);
        $events = $this->store->loadRecentEvents(10);
        $this->assertNotEmpty($events);

        $found = null;
        foreach ($events as $e) {
            if ($e['id'] === 'evt_test_001') {
                $found = $e;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('repair', $found['intent']);
    }

    public function testGetLastEventId(): void
    {
        $this->store->appendEvent([
            'id' => 'evt_first',
            'intent' => 'repair',
            'signals' => [],
            'outcome' => ['status' => 'success', 'score' => 0.8],
        ]);
        $this->store->appendEvent([
            'id' => 'evt_second',
            'intent' => 'optimize',
            'signals' => [],
            'outcome' => ['status' => 'success', 'score' => 0.9],
        ]);

        // Last event should be retrievable
        $lastId = $this->store->getLastEventId();
        $this->assertNotNull($lastId);
    }

    public function testGetStats(): void
    {
        $stats = $this->store->getStats();
        $this->assertArrayHasKey('genes', $stats);
        $this->assertArrayHasKey('capsules', $stats);
        $this->assertArrayHasKey('events', $stats);
        $this->assertArrayHasKey('failed_capsules', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['genes']);
    }

    // -------------------------------------------------------------------------
    // SignalExtractor tests
    // -------------------------------------------------------------------------

    public function testExtractErrorSignal(): void
    {
        $signals = $this->signalExtractor->extract([
            'context' => '[ERROR] Something went wrong: exception: null pointer',
        ]);
        $this->assertContains('log_error', $signals);
    }

    public function testExtractFeatureRequestSignal(): void
    {
        $signals = $this->signalExtractor->extract([
            'context' => 'Please add a new feature for user authentication',
        ]);
        $this->assertContains('user_feature_request', $signals);
    }

    public function testExtractPerfBottleneckSignal(): void
    {
        $signals = $this->signalExtractor->extract([
            'context' => 'The system is very slow, taking too long to respond, timeout issues',
        ]);
        $this->assertContains('perf_bottleneck', $signals);
    }

    public function testExtractImprovementSuggestion(): void
    {
        $signals = $this->signalExtractor->extract([
            'context' => 'This should be refactored and simplified for better maintainability',
        ]);
        $this->assertContains('user_improvement_suggestion', $signals);
    }

    public function testExtractCapabilityGapSignal(): void
    {
        $signals = $this->signalExtractor->extract([
            'context' => 'This feature is not supported and not available in the current version',
        ]);
        $this->assertContains('capability_gap', $signals);
    }

    public function testExtractRecurringErrorSignal(): void
    {
        // Use JSON-like content with } to match the regex [^}]{0,200}
        $context = implode(' ', array_fill(0, 5, '{"status": "error", "code": 500}'));
        $signals = $this->signalExtractor->extract(['context' => $context]);
        $this->assertContains('recurring_error', $signals);
    }

    public function testExtractRepairLoopDetected(): void
    {
        $recentEvents = array_fill(0, 4, [
            'intent' => 'repair',
            'signals' => ['log_error'],
            'blast_radius' => ['files' => 1, 'lines' => 5],
        ]);
        $signals = $this->signalExtractor->extract([
            'context' => '[ERROR] failed',
            'recentEvents' => $recentEvents,
        ]);
        $this->assertContains('repair_loop_detected', $signals);
    }

    public function testExtractEmptyContext(): void
    {
        $signals = $this->signalExtractor->extract(['context' => '']);
        $this->assertIsArray($signals);
        $this->assertEmpty($signals);
    }

    public function testHasOpportunitySignal(): void
    {
        $this->assertTrue($this->signalExtractor->hasOpportunitySignal(['user_feature_request']));
        $this->assertTrue($this->signalExtractor->hasOpportunitySignal(['perf_bottleneck']));
        $this->assertFalse($this->signalExtractor->hasOpportunitySignal(['log_error']));
        $this->assertFalse($this->signalExtractor->hasOpportunitySignal([]));
    }

    // -------------------------------------------------------------------------
    // GeneSelector tests
    // -------------------------------------------------------------------------

    public function testMatchPatternToSignals(): void
    {
        $this->assertTrue($this->geneSelector->matchPatternToSignals('error', ['log_error', 'warning']));
        $this->assertFalse($this->geneSelector->matchPatternToSignals('exception', ['log_error', 'warning']));
        $this->assertTrue($this->geneSelector->matchPatternToSignals('/error/i', ['log_error']));
        $this->assertFalse($this->geneSelector->matchPatternToSignals('', ['log_error']));
    }

    public function testScoreGene(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'signals_match' => ['error', 'exception', 'failed'],
        ];
        $score = $this->geneSelector->scoreGene($gene, ['log_error', 'exception_thrown']);
        $this->assertSame(2, $score);
    }

    public function testSelectGeneBySignals(): void
    {
        $genes = $this->store->loadGenes();
        $result = $this->geneSelector->selectGene($genes, ['log_error', 'exception']);
        $this->assertArrayHasKey('selected', $result);
        $this->assertArrayHasKey('alternatives', $result);
        $this->assertArrayHasKey('driftIntensity', $result);
        $this->assertNotNull($result['selected']);
        $this->assertSame('gene_gep_repair_from_errors', $result['selected']['id']);
    }

    public function testSelectGeneNoMatch(): void
    {
        $genes = $this->store->loadGenes();
        $result = $this->geneSelector->selectGene($genes, ['completely_unknown_signal_xyz']);
        $this->assertNull($result['selected']);
    }

    public function testSelectCapsule(): void
    {
        $capsules = [
            [
                'id' => 'c1',
                'trigger' => ['log_error', 'repair'],
                'gene' => 'gene_repair',
                'summary' => 'Fixed error',
                'confidence' => 0.9,
            ],
            [
                'id' => 'c2',
                'trigger' => ['user_feature_request'],
                'gene' => 'gene_innovate',
                'summary' => 'Added feature',
                'confidence' => 0.7,
            ],
        ];

        $selected = $this->geneSelector->selectCapsule($capsules, ['log_error']);
        $this->assertNotNull($selected);
        $this->assertSame('c1', $selected['id']);
    }

    public function testBanGenesFromFailedCapsules(): void
    {
        $failedCapsules = [
            ['gene' => 'gene_bad', 'trigger' => ['log_error', 'exception']],
            ['gene' => 'gene_bad', 'trigger' => ['log_error', 'exception', 'failed']],
        ];
        $bans = $this->geneSelector->banGenesFromFailedCapsules(
            $failedCapsules,
            ['log_error', 'exception']
        );
        $this->assertContains('gene_bad', $bans);
    }

    public function testSelectGeneAndCapsule(): void
    {
        $genes = $this->store->loadGenes();
        $result = $this->geneSelector->selectGeneAndCapsule([
            'genes' => $genes,
            'capsules' => [],
            'signals' => ['log_error', 'exception'],
        ]);
        $this->assertArrayHasKey('selectedGene', $result);
        $this->assertArrayHasKey('capsuleCandidates', $result);
        $this->assertArrayHasKey('selector', $result);
        $this->assertNotNull($result['selectedGene']);
    }

    // -------------------------------------------------------------------------
    // PromptBuilder tests
    // -------------------------------------------------------------------------

    public function testBuildGepPromptContainsKeyElements(): void
    {
        $genes = $this->store->loadGenes();
        $selectedGene = $genes[0];

        $prompt = $this->promptBuilder->buildGepPrompt([
            'context' => 'Test context with errors',
            'signals' => ['log_error', 'exception'],
            'selector' => ['selected' => $selectedGene['id'], 'reason' => ['test']],
            'selectedGene' => $selectedGene,
        ]);

        $this->assertStringContainsString('GEP', $prompt);
        $this->assertStringContainsString('Mutation', $prompt);
        $this->assertStringContainsString('PersonalityState', $prompt);
        $this->assertStringContainsString('EvolutionEvent', $prompt);
        $this->assertStringContainsString('Gene', $prompt);
        $this->assertStringContainsString('Capsule', $prompt);
        $this->assertStringContainsString('log_error', $prompt);
    }

    public function testBuildReusePromptContainsKeyElements(): void
    {
        $capsule = [
            'id' => 'capsule_test',
            'gene' => 'gene_repair',
            'trigger' => ['log_error'],
            'summary' => 'Fixed an error',
            'confidence' => 0.9,
        ];

        $prompt = $this->promptBuilder->buildReusePrompt([
            'capsule' => $capsule,
            'signals' => ['log_error'],
        ]);

        $this->assertStringContainsString('REUSE MODE', $prompt);
        $this->assertStringContainsString('capsule_test', $prompt);
        $this->assertStringContainsString('Fixed an error', $prompt);
    }

    public function testFormatGenesPreview(): void
    {
        $genes = $this->store->loadGenes();
        $preview = $this->promptBuilder->formatGenesPreview($genes);
        $this->assertNotEmpty($preview);
        $this->assertStringContainsString('[', $preview);
    }

    public function testContextTruncation(): void
    {
        $longContext = str_repeat('a', 25000);
        $prompt = $this->promptBuilder->buildGepPrompt([
            'context' => $longContext,
            'signals' => ['log_error'],
            'selector' => [],
        ]);
        $this->assertStringContainsString('TRUNCATED_EXECUTION_CONTEXT', $prompt);
    }

    // -------------------------------------------------------------------------
    // SolidifyEngine tests
    // -------------------------------------------------------------------------

    public function testSolidifyDryRun(): void
    {
        $genes = $this->store->loadGenes();
        $gene = $genes[0];

        $result = $this->solidifyEngine->solidify([
            'intent' => 'repair',
            'summary' => 'Test fix',
            'signals' => ['log_error'],
            'gene' => $gene,
            'blastRadius' => ['files' => 2, 'lines' => 10],
            'dryRun' => true,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['dryRun']);
        $this->assertNotNull($result['eventId']);

        // Verify nothing was written to DB in dry run
        $events = $this->store->loadRecentEvents(1);
        $this->assertEmpty($events);
    }

    public function testSolidifyWritesToStore(): void
    {
        $genes = $this->store->loadGenes();
        $gene = $genes[0];

        $result = $this->solidifyEngine->solidify([
            'intent' => 'repair',
            'summary' => 'Test fix written to DB',
            'signals' => ['log_error'],
            'gene' => $gene,
            'blastRadius' => ['files' => 1, 'lines' => 5],
            'dryRun' => false,
        ]);

        $this->assertTrue($result['ok']);
        $events = $this->store->loadRecentEvents(5);
        $this->assertNotEmpty($events);
        $lastEvent = end($events);
        $this->assertSame('repair', $lastEvent['intent']);
    }

    public function testSolidifyBlastRadiusViolation(): void
    {
        $result = $this->solidifyEngine->solidify([
            'intent' => 'repair',
            'summary' => 'Too many files',
            'signals' => ['log_error'],
            'gene' => [
                'id' => 'gene_test',
                'type' => 'Gene',
                'category' => 'repair',
                'signals_match' => ['error'],
                'constraints' => ['max_files' => 5],
            ],
            'blastRadius' => ['files' => 10, 'lines' => 100],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['violations']);
    }

    public function testIsValidationCommandAllowed(): void
    {
        $this->assertTrue($this->solidifyEngine->isValidationCommandAllowed('php -l src/test.php'));
        $this->assertTrue($this->solidifyEngine->isValidationCommandAllowed('composer install'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('rm -rf /'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('php -r "echo `id`"'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('php test.php; rm -rf'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('node index.js'));
        $this->assertFalse($this->solidifyEngine->isValidationCommandAllowed('curl http://evil.com'));
    }

    public function testParseGepObjects(): void
    {
        $text = <<<'JSON'
        Some preamble text
        {"type": "Mutation", "id": "mut_123", "category": "repair"}
        More text
        {"type": "PersonalityState", "rigor": 0.8, "creativity": 0.3}
        {"type": "EvolutionEvent", "id": "evt_456", "intent": "repair"}
        {"type": "Gene", "id": "gene_test", "category": "repair"}
        {"type": "Capsule", "id": "capsule_789"}
        JSON;

        $objects = $this->solidifyEngine->parseGepObjects($text);
        $this->assertCount(5, $objects);
        $this->assertSame('Mutation', $objects[0]['type']);
        $this->assertSame('PersonalityState', $objects[1]['type']);
        $this->assertSame('EvolutionEvent', $objects[2]['type']);
        $this->assertSame('Gene', $objects[3]['type']);
        $this->assertSame('Capsule', $objects[4]['type']);
    }

    public function testRecordFailure(): void
    {
        $this->solidifyEngine->recordFailure([
            'gene' => ['id' => 'gene_test'],
            'signals' => ['log_error'],
            'failureReason' => 'Test failure',
            'diffSnapshot' => '+ added line\n- removed line',
        ]);

        $failed = $this->store->loadFailedCapsules(5);
        $this->assertNotEmpty($failed);
        $this->assertSame('gene_test', $failed[0]['gene']);
        $this->assertSame('Test failure', $failed[0]['failure_reason']);
    }

    // -------------------------------------------------------------------------
    // Integration tests
    // -------------------------------------------------------------------------

    public function testFullEvolutionCycle(): void
    {
        $genes = $this->store->loadGenes();
        $this->assertNotEmpty($genes);

        // 1. Extract signals
        $signals = $this->signalExtractor->extract([
            'context' => '[ERROR] Something failed: exception in module',
        ]);
        $this->assertContains('log_error', $signals);

        // 2. Select gene
        $selection = $this->geneSelector->selectGeneAndCapsule([
            'genes' => $genes,
            'capsules' => [],
            'signals' => $signals,
        ]);
        $this->assertNotNull($selection['selectedGene']);

        // 3. Build prompt
        $prompt = $this->promptBuilder->buildGepPrompt([
            'context' => 'error context',
            'signals' => $signals,
            'selector' => $selection['selector'],
            'selectedGene' => $selection['selectedGene'],
        ]);
        $this->assertNotEmpty($prompt);
        $this->assertStringContainsString('GEP', $prompt);

        // 4. Solidify
        $result = $this->solidifyEngine->solidify([
            'intent' => 'repair',
            'summary' => 'Fixed the module error',
            'signals' => $signals,
            'gene' => $selection['selectedGene'],
            'blastRadius' => ['files' => 1, 'lines' => 5],
        ]);
        $this->assertTrue($result['ok']);

        // 5. Verify event was recorded
        $events = $this->store->loadRecentEvents(5);
        $this->assertNotEmpty($events);
        $lastEvent = end($events);
        $this->assertSame('repair', $lastEvent['intent']);
        $this->assertSame('success', $lastEvent['outcome']['status']);
    }
}
