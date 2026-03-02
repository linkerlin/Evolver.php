<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\ContentHash;
use Evolver\Database;
use Evolver\EnvFingerprint;
use Evolver\GepA2AProtocol;
use Evolver\GepAssetStore;
use Evolver\GeneSelector;
use Evolver\SafetyController;
use Evolver\SignalExtractor;
use Evolver\PromptBuilder;
use Evolver\SolidifyEngine;
use Evolver\SourceProtector;
use Evolver\Ops\SignalDeduplicator;
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
    // EnvFingerprint tests
    // -------------------------------------------------------------------------

    public function testCaptureFingerprintStructure(): void
    {
        $fp = EnvFingerprint::capture();

        // Required keys must all be present
        $this->assertArrayHasKey('device_id', $fp);
        $this->assertArrayHasKey('php_version', $fp);
        $this->assertArrayHasKey('platform', $fp);
        $this->assertArrayHasKey('arch', $fp);
        $this->assertArrayHasKey('os_release', $fp);
        $this->assertArrayHasKey('hostname', $fp);
        $this->assertArrayHasKey('evolver_version', $fp);
        $this->assertArrayHasKey('client', $fp);
        $this->assertArrayHasKey('client_version', $fp);
        $this->assertArrayHasKey('region', $fp);
        $this->assertArrayHasKey('cwd', $fp);
        $this->assertArrayHasKey('container', $fp);
    }

    public function testCaptureFingerprintValues(): void
    {
        $fp = EnvFingerprint::capture();

        // php_version must start with "PHP/"
        $this->assertStringStartsWith('PHP/', $fp['php_version']);

        // platform must be one of the expected values
        $this->assertContains($fp['platform'], ['linux', 'darwin', 'win32', 'freebsd', 'openbsd']);

        // hostname must be a 12-char hex string (SHA-256 prefix)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $fp['hostname']);

        // device_id must be a hex string of 16–64 chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16,64}$/', $fp['device_id']);

        // cwd must be a non-empty string
        $this->assertIsString($fp['cwd']);
        $this->assertNotEmpty($fp['cwd']);

        // container must be a bool
        $this->assertIsBool($fp['container']);

        // client must default to the composer name or 'evolver-php'
        $this->assertIsString($fp['client']);
        $this->assertNotEmpty($fp['client']);
    }

    public function testFingerprintKeyIsDeterministic(): void
    {
        $fp = EnvFingerprint::capture();

        // Same fingerprint → same key
        $key1 = EnvFingerprint::key($fp);
        $key2 = EnvFingerprint::key($fp);
        $this->assertSame($key1, $key2);

        // Key must be 16 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $key1);
    }

    public function testFingerprintKeyChangesWhenFingerprintChanges(): void
    {
        $fp = EnvFingerprint::capture();
        $key1 = EnvFingerprint::key($fp);

        // Change one field → different key
        $modified = $fp;
        $modified['device_id'] = 'deadbeefdeadbeefdeadbeefdeadbeef';
        $key2 = EnvFingerprint::key($modified);

        $this->assertNotSame($key1, $key2);
    }

    public function testIsSameEnvClassTrue(): void
    {
        $fp = EnvFingerprint::capture();
        $this->assertTrue(EnvFingerprint::isSameEnvClass($fp, $fp));
    }

    public function testIsSameEnvClassFalse(): void
    {
        $fpA = EnvFingerprint::capture();
        $fpB = $fpA;
        $fpB['device_id'] = 'cafebabecafebabecafebabecafebabe';
        $this->assertFalse(EnvFingerprint::isSameEnvClass($fpA, $fpB));
    }

    public function testFingerprintKeyOnEmptyArray(): void
    {
        $key = EnvFingerprint::key([]);
        $this->assertSame('unknown', $key);
    }

    public function testGetDeviceIdIsCached(): void
    {
        // Two calls must return the same device ID (caching)
        $id1 = EnvFingerprint::getDeviceId();
        $id2 = EnvFingerprint::getDeviceId();
        $this->assertSame($id1, $id2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16,64}$/', $id1);
    }

    public function testIsContainerReturnsBool(): void
    {
        $result = EnvFingerprint::isContainer();
        $this->assertIsBool($result);
    }

    public function testPromptContainsEnvFingerprint(): void
    {
        $genes = $this->store->loadGenes();
        $prompt = $this->promptBuilder->buildGepPrompt([
            'context' => 'test context',
            'signals' => ['log_error'],
            'selector' => [],
            'selectedGene' => $genes[0] ?? null,
        ]);

        $this->assertStringContainsString('Env Fingerprint', $prompt);
        $this->assertStringContainsString('php_version', $prompt);
        $this->assertStringContainsString('device_id', $prompt);
        $this->assertStringContainsString('platform', $prompt);
    }

    public function testSolidifyEventContainsEnvFingerprint(): void
    {
        $genes = $this->store->loadGenes();
        $result = $this->solidifyEngine->solidify([
            'intent' => 'repair',
            'summary' => 'Test with fingerprint',
            'signals' => ['log_error'],
            'gene' => $genes[0],
            'blastRadius' => ['files' => 1, 'lines' => 3],
            'dryRun' => false,
        ]);

        $this->assertTrue($result['ok']);
        $event = $result['event'];
        $this->assertArrayHasKey('env_fingerprint', $event);
        $this->assertArrayHasKey('php_version', $event['env_fingerprint']);
        $this->assertArrayHasKey('device_id', $event['env_fingerprint']);
        $this->assertStringStartsWith('PHP/', $event['env_fingerprint']['php_version']);
    }

    // -------------------------------------------------------------------------
    // MCP Resources & Tools (via McpServer)
    // -------------------------------------------------------------------------

    /** Run a JSON-RPC sequence through the MCP server and collect responses. */
    private function runMcpSequence(array $messages, string $dbPath = ':memory:'): array
    {
        // Write messages to a temp file to pipe into the server
        $input = '';
        foreach ($messages as $msg) {
            $input .= json_encode($msg) . "\n";
        }

        $tmpIn  = tempnam(sys_get_temp_dir(), 'mcp_in_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'mcp_out_');
        file_put_contents($tmpIn, $input);

        $php = PHP_BINARY;
        $script = dirname(__DIR__) . '/evolver.php';
        shell_exec("{$php} {$script} --db " . escapeshellarg($dbPath) . " < " . escapeshellarg($tmpIn) . " > " . escapeshellarg($tmpOut) . " 2>/dev/null");

        $raw = file_get_contents($tmpOut);
        unlink($tmpIn);
        unlink($tmpOut);

        $responses = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $responses[] = $decoded;
                }
            }
        }
        return $responses;
    }

    public function testMcpResourcesList(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list', 'params' => new \stdClass()],
        ], $dbPath);
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');

        // Find the resources/list response (id=2)
        $listResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $listResp = $r;
                break;
            }
        }
        $this->assertNotNull($listResp, 'Expected resources/list response');
        $this->assertArrayHasKey('result', $listResp);
        $resources = $listResp['result']['resources'] ?? [];
        $this->assertNotEmpty($resources, 'resources/list must return non-empty list');

        $uris = array_column($resources, 'uri');
        $this->assertContains('gep://genes', $uris);
        $this->assertContains('gep://capsules', $uris);
        $this->assertContains('gep://events', $uris);
        $this->assertContains('gep://schema', $uris);
        $this->assertContains('gep://stats', $uris);
    }

    public function testMcpResourceReadGenes(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'gep://genes']],
        ], $dbPath);
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');

        $readResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $readResp = $r;
                break;
            }
        }
        $this->assertNotNull($readResp);
        $this->assertArrayHasKey('result', $readResp);
        $contents = $readResp['result']['contents'] ?? [];
        $this->assertNotEmpty($contents);
        $this->assertSame('gep://genes', $contents[0]['uri']);
        $data = json_decode($contents[0]['text'], true);
        $this->assertSame('GEP_Genes', $data['type']);
        $this->assertGreaterThan(0, $data['count']);
        $this->assertNotEmpty($data['genes']);
    }

    public function testMcpResourceReadSchema(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'gep://schema']],
        ], $dbPath);
        @unlink($dbPath);

        $readResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $readResp = $r;
                break;
            }
        }
        $this->assertNotNull($readResp);
        $data = json_decode($readResp['result']['contents'][0]['text'], true);
        $this->assertSame('GEP_Schema', $data['type']);
        $this->assertSame('1.6.0', $data['schema_version']);
        $this->assertCount(5, $data['objects']); // 5 GEP objects
        $types = array_column($data['objects'], 'type');
        $this->assertSame(['Mutation', 'PersonalityState', 'EvolutionEvent', 'Gene', 'Capsule'], $types);
    }

    public function testMcpResourceReadStats(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'gep://stats']],
        ], $dbPath);
        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');

        $readResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $readResp = $r;
                break;
            }
        }
        $this->assertNotNull($readResp);
        $data = json_decode($readResp['result']['contents'][0]['text'], true);
        $this->assertSame('GEP_Stats', $data['type']);
        $this->assertArrayHasKey('store', $data);
        $this->assertArrayHasKey('env_fingerprint', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('php_version', $data['env_fingerprint']);
    }

    public function testMcpResourceReadUnknownUri(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'gep://nonexistent']],
        ], $dbPath);
        @unlink($dbPath);

        $readResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $readResp = $r;
                break;
            }
        }
        $this->assertNotNull($readResp);
        // Should return an error response
        $this->assertArrayHasKey('error', $readResp);
    }

    public function testToolDeleteGene(): void
    {
        // Seed a gene first
        $this->store->upsertGene([
            'type' => 'Gene',
            'id' => 'gene_to_delete',
            'category' => 'repair',
            'signals_match' => ['test'],
            'strategy' => ['step 1'],
        ]);

        $genesBefore = $this->store->loadGenes();
        $countBefore = count($genesBefore);

        $this->store->deleteGene('gene_to_delete');
        $genesAfter = $this->store->loadGenes();
        $this->assertCount($countBefore - 1, $genesAfter);
        $this->assertNull($this->store->getGene('gene_to_delete'));
    }

    public function testMcpToolsListIncludesDeleteGene(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'gep_db_') . '.db';
        $responses = $this->runMcpSequence([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass()]],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => new \stdClass()],
        ], $dbPath);
        @unlink($dbPath);

        $listResp = null;
        foreach ($responses as $r) {
            if (($r['id'] ?? null) === 2) {
                $listResp = $r;
                break;
            }
        }
        $this->assertNotNull($listResp);
        $tools = $listResp['result']['tools'] ?? [];
        $toolNames = array_column($tools, 'name');
        $this->assertContains('evolver_delete_gene', $toolNames);
        // Verify the full expected set of tools
        $expectedTools = [
            'evolver_run', 'evolver_solidify', 'evolver_extract_signals',
            'evolver_list_genes', 'evolver_list_capsules', 'evolver_list_events',
            'evolver_upsert_gene', 'evolver_delete_gene', 'evolver_stats',
        ];
        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $toolNames, "Missing tool: {$expected}");
        }
    }

    // -------------------------------------------------------------------------
    // ContentHash tests
    // -------------------------------------------------------------------------

    public function testContentHashCanonicalize(): void
    {
        $obj = ['b' => 2, 'a' => 1];
        $canonical = ContentHash::canonicalize($obj);
        $this->assertSame('{"a":1,"b":2}', $canonical);
    }

    public function testContentHashComputeAssetId(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'category' => 'repair',
        ];
        $assetId = ContentHash::computeAssetId($gene);
        $this->assertStringStartsWith('sha256:', $assetId);
        $this->assertSame(71, strlen($assetId)); // 'sha256:' + 64 hex chars
    }

    public function testContentHashVerifyAssetId(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'category' => 'repair',
        ];
        $assetId = ContentHash::computeAssetId($gene);
        $gene['asset_id'] = $assetId;
        
        $this->assertTrue(ContentHash::verifyAssetId($gene));
        
        // Tamper with data
        $gene['category'] = 'optimize';
        $this->assertFalse(ContentHash::verifyAssetId($gene));
    }

    public function testContentHashExcludesAssetId(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'category' => 'repair',
        ];
        $assetId1 = ContentHash::computeAssetId($gene);
        
        // Add asset_id and recompute - should get same result
        $gene['asset_id'] = $assetId1;
        $assetId2 = ContentHash::computeAssetId($gene);
        
        $this->assertSame($assetId1, $assetId2);
    }

    // -------------------------------------------------------------------------
    // SourceProtector tests
    // -------------------------------------------------------------------------

    public function testSourceProtectorDetectsProtectedFiles(): void
    {
        $protector = new SourceProtector();
        
        // These should be protected
        $this->assertTrue($protector->isProtected('src/McpServer.php'));
        $this->assertTrue($protector->isProtected('src/Database.php'));
        $this->assertTrue($protector->isProtected('evolver.php'));
        
        // These should not be protected
        $this->assertFalse($protector->isProtected('src/SomeUserFile.php'));
        $this->assertFalse($protector->isProtected('user_script.php'));
    }

    public function testSourceProtectorValidatesFiles(): void
    {
        $protector = new SourceProtector();
        
        $result = $protector->validateFiles([
            'src/McpServer.php',  // protected
            'src/Database.php',   // protected
            'user_file.php',      // not protected
        ]);
        
        $this->assertFalse($result['ok']);
        $this->assertCount(2, $result['violations']);
    }

    public function testSourceProtectorBypassCheck(): void
    {
        // By default, bypass should not be available
        $this->assertFalse(SourceProtector::canBypass());
    }

    // -------------------------------------------------------------------------
    // SafetyController tests
    // -------------------------------------------------------------------------

    public function testSafetyControllerModes(): void
    {
        $controller = new SafetyController(SafetyController::MODE_NEVER);
        $this->assertSame(SafetyController::MODE_NEVER, $controller->getMode());
        $this->assertFalse($controller->isSelfModifyAllowed());
        $this->assertFalse($controller->isOperationAllowed('modify'));
        $this->assertTrue($controller->isOperationAllowed('diagnose'));

        $controller = new SafetyController(SafetyController::MODE_ALWAYS);
        $this->assertTrue($controller->isSelfModifyAllowed());
        $this->assertTrue($controller->isOperationAllowed('modify'));
    }

    public function testSafetyControllerValidatesModifications(): void
    {
        $controller = new SafetyController(SafetyController::MODE_NEVER);
        
        $result = $controller->validateModification([
            'files' => ['test.php'],
            'lines' => 10,
        ]);
        
        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('disabled', $result['reason']);
    }

    public function testSafetyControllerStatusReport(): void
    {
        $controller = new SafetyController(SafetyController::MODE_REVIEW);
        $report = $controller->getStatusReport();
        
        $this->assertSame(SafetyController::MODE_REVIEW, $report['mode']);
        $this->assertTrue($report['review_required']);
        $this->assertArrayHasKey('operations', $report);
    }

    // -------------------------------------------------------------------------
    // GepA2AProtocol tests
    // -------------------------------------------------------------------------

    public function testGepA2AProtocolBuildsMessage(): void
    {
        $protocol = new GepA2AProtocol();
        $message = $protocol->buildMessage('hello', ['test' => true]);
        
        $this->assertSame('gep-a2a', $message['protocol']);
        $this->assertSame('1.0.0', $message['protocol_version']);
        $this->assertSame('hello', $message['message_type']);
        $this->assertArrayHasKey('message_id', $message);
        $this->assertArrayHasKey('sender_id', $message);
        $this->assertArrayHasKey('timestamp', $message);
        $this->assertSame(['test' => true], $message['payload']);
    }

    public function testGepA2AProtocolBuildsHello(): void
    {
        $protocol = new GepA2AProtocol();
        $message = $protocol->buildHello(['geneCount' => 5]);
        
        $this->assertSame('hello', $message['message_type']);
        $this->assertSame(5, $message['payload']['gene_count']);
        $this->assertArrayHasKey('env_fingerprint', $message['payload']);
    }

    public function testGepA2AProtocolValidatesMessages(): void
    {
        $valid = [
            'protocol' => 'gep-a2a',
            'protocol_version' => '1.0.0',
            'message_type' => 'hello',
            'message_id' => 'msg_123',
            'sender_id' => 'node_abc',
            'timestamp' => '2026-02-25T14:21:20Z',
            'payload' => [],
        ];
        
        $this->assertTrue(GepA2AProtocol::isValidProtocolMessage($valid));
        
        $invalid = $valid;
        $invalid['protocol'] = 'invalid';
        $this->assertFalse(GepA2AProtocol::isValidProtocolMessage($invalid));
    }

    // -------------------------------------------------------------------------
    // SignalDeduplicator tests
    // -------------------------------------------------------------------------

    public function testSignalDeduplicatorSuppressesDuplicates(): void
    {
        $dedup = new SignalDeduplicator(3600);
        
        // First occurrence - should not suppress
        $result1 = $dedup->shouldSuppress('test_error');
        $this->assertFalse($result1['suppress']);
        $this->assertSame(1, $result1['count']);
        
        // Second occurrence - should suppress
        $result2 = $dedup->shouldSuppress('test_error');
        $this->assertTrue($result2['suppress']);
        $this->assertSame(2, $result2['count']);
    }

    public function testSignalDeduplicatorProcessSignal(): void
    {
        $dedup = new SignalDeduplicator(3600);
        
        $result = $dedup->processSignal('error_in_module', ['file' => 'test.php']);
        $this->assertSame('notify', $result['action']);
        
        // Same signal again
        $result = $dedup->processSignal('error_in_module', ['file' => 'test.php']);
        $this->assertSame('suppressed', $result['action']);
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
        $this->assertContains($lastEvent['outcome']['status'], ['success', 'partial']);
    }

    // -------------------------------------------------------------------------
    // GdiCalculator tests
    // -------------------------------------------------------------------------

    public function testGdiCalculatorComputesCapsuleScore(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $capsule = [
            'type' => 'Capsule',
            'outcome' => ['score' => 0.9],
            'confidence' => 0.8,
            'success_streak' => 3,
            'blast_radius' => ['files' => 2, 'lines' => 50],
            'content' => 'test content',
        ];
        
        $score = $calculator->computeCapsuleGdi($capsule);
        $this->assertGreaterThan(0.5, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testGdiCalculatorSortsCapsules(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $capsules = [
            ['outcome' => ['score' => 0.5], 'confidence' => 0.5],
            ['outcome' => ['score' => 0.9], 'confidence' => 0.9],
            ['outcome' => ['score' => 0.7], 'confidence' => 0.7],
        ];
        
        $sorted = $calculator->sortCapsulesByGdi($capsules);
        
        $this->assertSame(0.9, $sorted[0]['confidence']);
        $this->assertSame(0.7, $sorted[1]['confidence']);
        $this->assertSame(0.5, $sorted[2]['confidence']);
    }

    public function testGdiCalculatorFiltersByMinGdi(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $capsules = [
            ['outcome' => ['score' => 0.3], 'confidence' => 0.3],
            ['outcome' => ['score' => 0.7], 'confidence' => 0.7],
            ['outcome' => ['score' => 0.9], 'confidence' => 0.9],
        ];
        
        $filtered = $calculator->filterCapsulesByMinGdi($capsules, 0.5);
        
        $this->assertCount(2, $filtered);
    }

    public function testGdiCalculatorGetTopCapsules(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $capsules = [
            ['outcome' => ['score' => 0.3], 'confidence' => 0.3],
            ['outcome' => ['score' => 0.9], 'confidence' => 0.9],
            ['outcome' => ['score' => 0.7], 'confidence' => 0.7],
            ['outcome' => ['score' => 0.8], 'confidence' => 0.8],
        ];
        
        $top = $calculator->getTopCapsules($capsules, 2);
        
        $this->assertCount(2, $top);
        $this->assertSame(0.9, $top[0]['confidence']);
    }

    public function testGdiCalculatorGetGdiCategory(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $this->assertSame('excellent', $calculator->getGdiCategory(0.9));
        $this->assertSame('good', $calculator->getGdiCategory(0.7));
        $this->assertSame('average', $calculator->getGdiCategory(0.5));
        $this->assertSame('poor', $calculator->getGdiCategory(0.3));
        $this->assertSame('very_poor', $calculator->getGdiCategory(0.1));
    }

    public function testGdiCalculatorGetStats(): void
    {
        $calculator = new \Evolver\GdiCalculator();
        
        $capsules = [
            ['outcome' => ['score' => 0.9], 'confidence' => 0.9],
            ['outcome' => ['score' => 0.7], 'confidence' => 0.7],
            ['outcome' => ['score' => 0.5], 'confidence' => 0.5],
        ];
        
        $stats = $calculator->getGdiStats($capsules);
        
        $this->assertEquals(3, $stats['count']);
        $this->assertArrayHasKey('distribution', $stats);
    }

    // -------------------------------------------------------------------------
    // StrategyConfig tests
    // -------------------------------------------------------------------------

    public function testStrategyConfigDefaultValues(): void
    {
        $config = new \Evolver\StrategyConfig();
        
        $this->assertSame('balanced', $config->getStrategy());
        $this->assertIsArray($config->getMutationWeights());
        $this->assertIsArray($config->getQualityGates());
    }

    public function testStrategyConfigSetStrategy(): void
    {
        $config = new \Evolver\StrategyConfig();
        
        $config->setStrategy('innovate');
        $this->assertSame('innovate', $config->getStrategy());
        
        $weights = $config->getMutationWeights();
        $this->assertGreaterThan(0.4, $weights['innovate'] ?? 0);
    }

    public function testStrategyConfigMutationWeight(): void
    {
        $config = new \Evolver\StrategyConfig();
        
        $weight = $config->getMutationWeight('repair');
        $this->assertGreaterThan(0, $weight);
    }

    public function testStrategyConfigQualityGates(): void
    {
        $config = new \Evolver\StrategyConfig();
        
        $gates = $config->getQualityGates();
        $this->assertArrayHasKey('min_confidence', $gates);
    }

    public function testStrategyConfigPassesQualityGates(): void
    {
        $config = new \Evolver\StrategyConfig();
        
        $mutation = [
            'confidence' => 0.9,
            'gdi' => 0.8,
        ];
        
        $result = $config->passesQualityGates($mutation);
        $this->assertTrue($result['passed']);
    }

    public function testStrategyConfigGetAvailableStrategies(): void
    {
        $strategies = \Evolver\StrategyConfig::getAvailableStrategies();
        
        $this->assertContains('balanced', $strategies);
        $this->assertContains('innovate', $strategies);
        $this->assertContains('harden', $strategies);
        $this->assertContains('repair-only', $strategies);
    }

    // -------------------------------------------------------------------------
    // GepValidator tests
    // -------------------------------------------------------------------------

    public function testGepValidatorValidatesMutation(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $mutation = [
            'type' => 'Mutation',
            'description' => 'Test mutation',
            'risk_level' => 'low',
            'rationale' => 'Testing',
        ];
        
        $result = $validator->validateMutation($mutation);
        $this->assertTrue($result['valid']);
    }

    public function testGepValidatorValidatesPersonalityState(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $personality = [
            'type' => 'PersonalityState',
            'rigor' => 0.5,
            'creativity' => 0.5,
            'verbosity' => 0.5,
            'risk_tolerance' => 0.5,
            'obedience' => 0.5,
        ];
        
        $result = $validator->validatePersonalityState($personality);
        $this->assertTrue($result['valid']);
    }

    public function testGepValidatorValidatesEvolutionEvent(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $event = [
            'type' => 'EvolutionEvent',
            'id' => 'evt_test',
            'intent' => 'repair',
            'signals' => ['log_error'],
            'parent_id' => '',
            'genes_used' => ['gene_repair'],
            'blast_radius' => ['files' => 1, 'lines' => 10],
        ];
        
        $result = $validator->validateEvolutionEvent($event);
        $this->assertTrue($result['valid']);
    }

    public function testGepValidatorValidatesGene(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $gene = [
            'type' => 'Gene',
            'id' => 'gene_test',
            'category' => 'repair',
            'signals_match' => ['error'],
            'prompt_template' => 'Fix: {context}',
        ];
        
        $result = $validator->validateGene($gene);
        $this->assertTrue($result['valid']);
    }

    public function testGepValidatorValidatesCapsule(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $capsule = [
            'type' => 'Capsule',
            'id' => 'capsule_test',
            'trigger' => ['error'],
            'gene' => 'gene_repair',
            'summary' => 'Fixed error',
            'confidence' => 0.8,
            'blast_radius' => ['files' => 1, 'lines' => 5],
        ];
        
        $result = $validator->validateCapsule($capsule);
        $this->assertTrue($result['valid']);
    }

    public function testGepValidatorParseGepObjects(): void
    {
        $validator = new \Evolver\GepValidator();
        
        $output = '{"type":"Mutation","description":"test"}';
        
        $objects = $validator->parseGepObjects($output);
        $this->assertIsArray($objects);
    }

    // -------------------------------------------------------------------------
    // Ops tests - DiskCleaner
    // -------------------------------------------------------------------------

    public function testDiskCleanerCheckDiskSpace(): void
    {
        $cleaner = new \Evolver\Ops\DiskCleaner(__DIR__ . '/../data');
        
        $result = $cleaner->checkDiskSpace();
        
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('free_mb', $result);
    }

    public function testDiskCleanerGetStats(): void
    {
        $cleaner = new \Evolver\Ops\DiskCleaner(__DIR__ . '/../data');
        
        $stats = $cleaner->getStats();
        
        $this->assertArrayHasKey('disk_space', $stats);
    }

    // -------------------------------------------------------------------------
    // Ops tests - LifecycleManager
    // -------------------------------------------------------------------------

    public function testLifecycleManagerBasicOperations(): void
    {
        $manager = new \Evolver\Ops\LifecycleManager();
        
        $this->assertFalse($manager->isShuttingDown());
        $this->assertGreaterThanOrEqual(0, $manager->getUptime());
    }

    public function testLifecycleManagerMetrics(): void
    {
        $manager = new \Evolver\Ops\LifecycleManager();
        
        $manager->recordCycle(true);
        $manager->recordCycle(false);
        
        $metrics = $manager->getMetrics();
        
        $this->assertEquals(1, $metrics['cycles_completed']);
        $this->assertEquals(1, $metrics['cycles_failed']);
    }

    public function testLifecycleManagerHealth(): void
    {
        $manager = new \Evolver\Ops\LifecycleManager();
        
        $health = $manager->getHealth();
        
        $this->assertArrayHasKey('status', $health);
        $this->assertArrayHasKey('uptime_seconds', $health);
    }

    // -------------------------------------------------------------------------
    // Ops tests - SignalDeduplicator (extended)
    // -------------------------------------------------------------------------

    public function testSignalDeduplicatorHistorySize(): void
    {
        $dedup = new \Evolver\Ops\SignalDeduplicator(3600, 100);
        
        $dedup->processSignal('test_signal_1');
        $dedup->processSignal('test_signal_2');
        
        $this->assertEquals(2, $dedup->getHistorySize());
    }

    public function testSignalDeduplicatorClearHistory(): void
    {
        $dedup = new \Evolver\Ops\SignalDeduplicator();
        
        $dedup->processSignal('test_signal');
        $dedup->clearHistory();
        
        $this->assertEquals(0, $dedup->getHistorySize());
    }

    // -------------------------------------------------------------------------
    // Ops tests - OpsManager
    // -------------------------------------------------------------------------

    public function testOpsManagerListCommands(): void
    {
        $manager = new \Evolver\Ops\OpsManager(__DIR__ . '/../data');
        
        $commands = $manager->listCommands();
        
        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('health', $commands);
        $this->assertArrayHasKey('stats', $commands);
    }

    public function testOpsManagerRunHelp(): void
    {
        $manager = new \Evolver\Ops\OpsManager(__DIR__ . '/../data');
        
        $result = $manager->run('help');
        
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('commands', $result['result']);
    }

    public function testOpsManagerRunHealth(): void
    {
        $manager = new \Evolver\Ops\OpsManager(__DIR__ . '/../data');
        
        $result = $manager->run('health');
        
        $this->assertArrayHasKey('disk_space', $result['result']);
    }

    public function testOpsManagerRunStats(): void
    {
        $manager = new \Evolver\Ops\OpsManager(__DIR__ . '/../data');
        
        $result = $manager->run('stats');
        
        $this->assertArrayHasKey('disk', $result['result']);
    }

    public function testOpsManagerUnknownCommand(): void
    {
        $manager = new \Evolver\Ops\OpsManager(__DIR__ . '/../data');
        
        $result = $manager->run('nonexistent_command');
        
        $this->assertFalse($result['ok']);
    }

    // -------------------------------------------------------------------------
    // EvoMapClient tests
    // -------------------------------------------------------------------------

    public function testEvoMapClientDefaultConfiguration(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $this->assertTrue($client->isConfigured());
    }

    public function testEvoMapClientGetNodeId(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $nodeId = $client->getNodeId();
        
        $this->assertNotEmpty($nodeId);
        $this->assertIsString($nodeId);
    }

    public function testEvoMapClientSendHello(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $result = $client->sendHello(['capability_test'], 5, 10);
        
        $this->assertArrayHasKey('ok', $result);
    }

    public function testEvoMapClientFetchAssets(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $result = $client->fetchAssets('gene');
        
        $this->assertArrayHasKey('ok', $result);
    }

    public function testEvoMapClientSearchAssets(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $result = $client->searchAssets(['error', 'repair'], 10);
        
        $this->assertArrayHasKey('ok', $result);
    }

    public function testEvoMapClientHeartbeatStats(): void
    {
        $client = new \Evolver\EvoMapClient();
        
        $stats = $client->getHeartbeatStats();
        
        $this->assertArrayHasKey('running', $stats);
    }

    // -------------------------------------------------------------------------
    // EvolutionLoop tests
    // -------------------------------------------------------------------------

    public function testEvolutionLoopBasicOperations(): void
    {
        $loop = new \Evolver\EvolutionLoop($this->db, 30);
        
        $this->assertFalse($loop->isRunning());
    }

    public function testEvolutionLoopGetStats(): void
    {
        $loop = new \Evolver\EvolutionLoop($this->db, 60);
        
        $stats = $loop->getStats();
        
        $this->assertArrayHasKey('running', $stats);
        $this->assertArrayHasKey('cycles_completed', $stats);
        $this->assertArrayHasKey('cycles_failed', $stats);
    }

    // -------------------------------------------------------------------------
    // Database extended tests
    // -------------------------------------------------------------------------

    public function testDatabaseQuery(): void
    {
        $result = $this->db->query('SELECT 1 as test');
        $this->assertNotFalse($result);
    }

    public function testDatabaseExec(): void
    {
        $result = $this->db->exec('CREATE TABLE IF NOT EXISTS test_table (id INTEGER)');
        $this->assertTrue($result);
    }

    public function testDatabaseFetchOne(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_fetchone (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec('INSERT INTO test_fetchone (name) VALUES (?)', ['test']);
        
        $result = $this->db->fetchOne('SELECT * FROM test_fetchone WHERE name = ?', ['test']);
        $this->assertNotNull($result);
        $this->assertSame('test', $result['name']);
    }

    public function testDatabaseLastInsertRowId(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS test_insert (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->exec('INSERT INTO test_insert (name) VALUES (?)', ['test']);
        
        $rowId = $this->db->lastInsertRowId();
        $this->assertGreaterThan(0, $rowId);
    }

    public function testDatabaseGetMigrationLog(): void
    {
        $log = $this->db->getMigrationLog();
        $this->assertIsArray($log);
    }

    public function testDatabaseGetHealthStatus(): void
    {
        $health = $this->db->getHealthStatus();
        $this->assertArrayHasKey('path', $health);
    }

    public function testDatabaseGetDb(): void
    {
        $db = $this->db->getDb();
        $this->assertInstanceOf(\SQLite3::class, $db);
    }

    public function testDatabaseClose(): void
    {
        $testDb = new \Evolver\Database(':memory:');
        $testDb->close();
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // StructuredLogger tests
    // -------------------------------------------------------------------------

    public function testStructuredLoggerBasic(): void
    {
        $logger = new \Evolver\Ops\StructuredLogger('/tmp/test_evolver.log');
        
        $logger->info('test message', ['key' => 'value']);
        
        $this->assertFileExists('/tmp/test_evolver.log');
    }

    public function testStructuredLoggerGetLogFiles(): void
    {
        $logger = new \Evolver\Ops\StructuredLogger('/tmp/test_evolver2.log');
        
        $files = $logger->getLogFiles();
        
        $this->assertIsArray($files);
    }

    public function testStructuredLoggerGetLogSize(): void
    {
        $logger = new \Evolver\Ops\StructuredLogger('/tmp/test_evolver3.log');
        
        $size = $logger->getLogSize();
        
        $this->assertIsArray($size);
    }

    // -------------------------------------------------------------------------
    // GitSelfRepair tests
    // -------------------------------------------------------------------------

    public function testGitSelfRepairBasic(): void
    {
        $repair = new \Evolver\Ops\GitSelfRepair();
        
        $this->assertTrue(true);
    }

    public function testGitSelfRepairRepair(): void
    {
        $repair = new \Evolver\Ops\GitSelfRepair();
        
        $result = $repair->repair();
        
        $this->assertArrayHasKey('ok', $result);
    }

    public function testGitSelfRepairGetLastResult(): void
    {
        $repair = new \Evolver\Ops\GitSelfRepair();
        
        $repair->repair();
        
        $result = $repair->getLastResult();
        $this->assertIsArray($result);
    }

    // -------------------------------------------------------------------------
    // DaemonManager tests
    // -------------------------------------------------------------------------

    public function testDaemonManagerBasic(): void
    {
        $manager = new \Evolver\Ops\DaemonManager();
        
        $this->assertFalse($manager->isRunning());
    }

    public function testDaemonManagerGetPid(): void
    {
        $manager = new \Evolver\Ops\DaemonManager();
        
        $pid = $manager->getPid();
        
        $this->assertNull($pid);
    }

    // -------------------------------------------------------------------------
    // SignalExtractor extended tests
    // -------------------------------------------------------------------------

    public function testSignalExtractorMultipleSignals(): void
    {
        $extractor = new \Evolver\SignalExtractor();
        
        $signals = $extractor->extract([
            'context' => '[ERROR] Fatal error in module test. [WARN] Memory high. [INFO] Running.',
        ]);
        
        $this->assertNotEmpty($signals);
    }

    // -------------------------------------------------------------------------
    // GeneSelector extended tests
    // -------------------------------------------------------------------------

    public function testGeneSelectorWithEmptyCapsules(): void
    {
        $selector = new \Evolver\GeneSelector();
        
        $genes = [
            ['id' => 'gene1', 'signals_match' => ['error']],
        ];
        
        $result = $selector->selectGeneAndCapsule([
            'genes' => $genes,
            'capsules' => [],
            'signals' => ['error'],
        ]);
        
        $this->assertIsArray($result);
    }

    public function testGeneSelectorComputeSignalOverlap(): void
    {
        $selector = new \Evolver\GeneSelector();
        
        $overlap = $selector->computeSignalOverlap(['error', 'bug'], ['error', 'fix']);
        
        $this->assertGreaterThan(0, $overlap);
    }

    public function testGeneSelectorBuildDecision(): void
    {
        $selector = new \Evolver\GeneSelector();
        
        $decision = $selector->buildSelectorDecision([
            'genes' => [],
            'capsules' => [],
            'signals' => ['error'],
        ]);
        
        $this->assertIsArray($decision);
    }

    // -------------------------------------------------------------------------
    // PromptBuilder extended tests
    // -------------------------------------------------------------------------

    public function testPromptBuilderWithMinGdi(): void
    {
        $builder = new \Evolver\PromptBuilder();
        
        $prompt = $builder->buildGepPrompt([
            'context' => 'test',
            'signals' => ['error'],
            'selector' => ['strategy' => 'balanced'],
            'selectedGene' => ['id' => 'gene1', 'type' => 'Gene'],
            'min_gdi' => 0.5,
        ]);
        
        $this->assertStringContainsString('GEP', $prompt);
    }

    // -------------------------------------------------------------------------
    // SafetyController extended tests
    // -------------------------------------------------------------------------

    public function testSafetyControllerGetMode(): void
    {
        $controller = new \Evolver\SafetyController();
        
        $mode = $controller->getMode();
        $this->assertNotEmpty($mode);
    }

    public function testSafetyControllerIsOperationAllowed(): void
    {
        $controller = new \Evolver\SafetyController();
        
        $result = $controller->isOperationAllowed('evolver_run');
        $this->assertIsBool($result);
    }

    public function testSafetyControllerGetStatusReport(): void
    {
        $controller = new \Evolver\SafetyController();
        
        $report = $controller->getStatusReport();
        $this->assertIsArray($report);
    }

    public function testSafetyControllerCreateReviewRequest(): void
    {
        $controller = new \Evolver\SafetyController();
        
        $request = $controller->createReviewRequest([
            'intent' => 'repair',
            'files' => ['test.php'],
        ]);
        
        $this->assertIsArray($request);
    }

    // -------------------------------------------------------------------------
    // GepA2AProtocol extended tests
    // -------------------------------------------------------------------------

    public function testGepA2AProtocolBuildPublish(): void
    {
        $protocol = new \Evolver\GepA2AProtocol();
        
        $message = $protocol->buildPublish(['type' => 'Gene', 'id' => 'gene1', 'content' => 'test']);
        
        $this->assertIsArray($message);
        $this->assertArrayHasKey('message_type', $message);
    }

    public function testGepA2AProtocolBuildFetch(): void
    {
        $protocol = new \Evolver\GepA2AProtocol();
        
        $message = $protocol->buildFetch(['type' => 'gene']);
        
        $this->assertIsArray($message);
    }

    public function testGepA2AProtocolBuildDecision(): void
    {
        $protocol = new \Evolver\GepA2AProtocol();
        
        $message = $protocol->buildDecision('accept', ['asset_id' => 'test']);
        
        $this->assertIsArray($message);
    }

    public function testGepA2AProtocolBuildHeartbeat(): void
    {
        $protocol = new \Evolver\GepA2AProtocol();
        
        $message = $protocol->buildHeartbeat(1000);
        
        $this->assertIsArray($message);
    }

    // -------------------------------------------------------------------------
    // GepAssetStore extended tests
    // -------------------------------------------------------------------------

    public function testGepAssetStoreLoadTopCapsules(): void
    {
        $capsules = $this->store->loadTopCapsules(5);
        $this->assertIsArray($capsules);
    }

    public function testGepAssetStoreLoadCapsulesByMinGdi(): void
    {
        $capsules = $this->store->loadCapsulesByMinGdi(0.5);
        $this->assertIsArray($capsules);
    }

    public function testGepAssetStoreGetCapsulesGdiStats(): void
    {
        $stats = $this->store->getCapsulesGdiStats();
        $this->assertIsArray($stats);
    }

    public function testGepAssetStoreComputeSuccessStreak(): void
    {
        $streak = $this->store->computeSuccessStreak('gene_repair', ['error']);
        $this->assertIsInt($streak);
    }

    public function testGepAssetStoreGetPendingSyncAssets(): void
    {
        $assets = $this->store->getPendingSyncAssets('gene', 10);
        $this->assertIsArray($assets);
    }

    public function testGepAssetStoreGetStats(): void
    {
        $stats = $this->store->getStats();
        $this->assertIsArray($stats);
    }

    public function testGepAssetStoreGetGeneByAssetId(): void
    {
        $gene = $this->store->getGeneByAssetId('test_asset_id');
        $this->assertNull($gene);
    }

    public function testGepAssetStoreGetCapsuleByAssetId(): void
    {
        $capsule = $this->store->getCapsuleByAssetId('test_asset_id');
        $this->assertNull($capsule);
    }

    // -------------------------------------------------------------------------
    // ContentHash extended tests
    // -------------------------------------------------------------------------

    public function testContentHashMultipleTypes(): void
    {
        $hash1 = \Evolver\ContentHash::computeAssetId(['type' => 'Gene']);
        $hash2 = \Evolver\ContentHash::computeAssetId(['type' => 'Capsule']);
        
        $this->assertNotSame($hash1, $hash2);
    }

    // -------------------------------------------------------------------------
    // SourceProtector extended tests
    // -------------------------------------------------------------------------

    public function testSourceProtectorGetProtectedPaths(): void
    {
        $protector = new \Evolver\SourceProtector();
        
        $paths = $protector->getProtectedPaths();
        $this->assertIsArray($paths);
    }

    public function testSourceProtectorGetProtectionReport(): void
    {
        $protector = new \Evolver\SourceProtector();
        
        $report = $protector->getProtectionReport();
        $this->assertIsArray($report);
    }

    public function testSourceProtectorAddProtectedPaths(): void
    {
        $protector = new \Evolver\SourceProtector();
        
        $protector->addProtectedPaths(['/test/path']);
        
        $this->assertTrue(true);
    }
}
