<?php

declare(strict_types=1);

namespace Evolver;

use Evolver\Ops\LifecycleManager;
use Evolver\Ops\DiskCleaner;
use Evolver\Ops\SignalDeduplicator;

/**
 * MCP stdio server implementing the Model Context Protocol.
 * Handles JSON-RPC 2.0 messages over stdin/stdout.
 * Updated for GEP 1.6.0 protocol compliance.
 */
final class McpServer
{
    private const MCP_VERSION = '2024-11-05';
    private const SERVER_NAME = 'evolver-php';
    private const SERVER_VERSION = '1.1.0'; // Updated for GEP 1.6.0

    private GepAssetStore $store;
    private SignalExtractor $signalExtractor;
    private GeneSelector $geneSelector;
    private PromptBuilder $promptBuilder;
    private SolidifyEngine $solidifyEngine;
    private SafetyController $safetyController;
    private SourceProtector $sourceProtector;
    private ?EvoMapClient $evoMapClient;
    private ?LifecycleManager $lifecycleManager;
    private ?DiskCleaner $diskCleaner;
    private ?SignalDeduplicator $signalDeduplicator;

    /** @var resource */
    private $stdin;
    /** @var resource */
    private $stdout;

    private bool $initialized = false;

    public function __construct(private readonly Database $db)
    {
        $this->store = new GepAssetStore($db);
        $this->signalExtractor = new SignalExtractor();
        $this->geneSelector = new GeneSelector();
        $this->promptBuilder = new PromptBuilder();
        $this->solidifyEngine = new SolidifyEngine($this->store, $this->signalExtractor, $this->geneSelector);
        
        // Initialize safety and protection
        $this->sourceProtector = new SourceProtector();
        $this->safetyController = SafetyController::fromEnvironment($this->sourceProtector);
        
        // Initialize optional components
        $this->evoMapClient = new EvoMapClient();
        $this->lifecycleManager = new LifecycleManager();
        $this->diskCleaner = new DiskCleaner();
        $this->signalDeduplicator = new SignalDeduplicator();

        $this->stdin = STDIN;
        $this->stdout = STDOUT;

        // Register lifecycle handlers
        $this->registerLifecycleHandlers();
    }

    /**
     * Register lifecycle event handlers.
     */
    private function registerLifecycleHandlers(): void
    {
        if ($this->lifecycleManager) {
            $this->lifecycleManager->onStartup(function() {
                error_log('[McpServer] Server starting up');
                return ['status' => 'started'];
            });

            $this->lifecycleManager->onShutdown(function($reason) {
                error_log('[McpServer] Server shutting down: ' . $reason);
                $this->db->close();
                return ['status' => 'stopped'];
            });
        }
    }

    /**
     * Run the MCP server, reading from stdin and writing to stdout.
     */
    public function run(): void
    {
        // Execute startup sequence
        if ($this->lifecycleManager) {
            $this->lifecycleManager->startup();
        }

        // Disable output buffering for immediate responses
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        while (!feof($this->stdin)) {
            $line = fgets($this->stdin);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $message = json_decode($line, true);
            if (!is_array($message)) {
                $this->sendError(null, -32700, 'Parse error', null);
                continue;
            }

            $this->handleMessage($message);
        }
    }

    private function handleMessage(array $message): void
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = $message['params'] ?? [];

        if ($method === null) {
            // This is a response or notification we don't need to handle
            return;
        }

        try {
            $result = $this->dispatch($method, $params, $id);
            if ($id !== null) {
                $this->sendResult($id, $result);
            }
        } catch (\InvalidArgumentException $e) {
            if ($id !== null) {
                $this->sendError($id, -32602, 'Invalid params: ' . $e->getMessage(), null);
            }
        } catch (\RuntimeException $e) {
            if ($id !== null) {
                $this->sendError($id, -32603, $e->getMessage(), null);
            }
        } catch (\Throwable $e) {
            error_log('[McpServer] Unexpected error: ' . $e->getMessage());
            if ($id !== null) {
                $this->sendError($id, -32603, 'Internal error', null);
            }
        }
    }

    private function dispatch(string $method, array $params, mixed $id): mixed
    {
        return match ($method) {
            'initialize' => $this->handleInitialize($params),
            'initialized' => null, // notification, no response
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            'resources/list' => $this->handleResourcesList(),
            'resources/read' => $this->handleResourcesRead($params),
            'prompts/list' => ['prompts' => []],
            'ping' => [],
            default => throw new \RuntimeException("Method not found: {$method}"),
        };
    }

    private function handleInitialize(array $params): array
    {
        $this->initialized = true;

        // Note: We don't send 'notifications/initialized' here because some MCP clients
        // (like Kimi CLI) have validation issues with this notification.
        // The client can infer initialization completion from the initialize response.

        return [
            'protocolVersion' => self::MCP_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts' => ['listChanged' => false],
                'logging' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return [
            'tools' => $this->getToolDefinitions(),
        ];
    }

    private function handleToolsCall(array $params): array
    {
        // Debug: Log received params
        error_log('[McpServer] tools/call params: ' . json_encode($params));
        
        $toolName = $params['name'] ?? throw new \InvalidArgumentException('Tool name required');
        
        // Handle different parameter formats from various MCP clients
        // Some clients use 'arguments', others use 'input'
        $arguments = $params['arguments'] ?? $params['input'] ?? [];
        
        // Ensure arguments is an array
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $result = match ($toolName) {
                'evolver_run' => $this->toolEvolverRun($arguments),
                'evolver_solidify' => $this->toolEvolverSolidify($arguments),
                'evolver_extract_signals' => $this->toolEvolverExtractSignals($arguments),
                'evolver_list_genes' => $this->toolEvolverListGenes($arguments),
                'evolver_list_capsules' => $this->toolEvolverListCapsules($arguments),
                'evolver_list_events' => $this->toolEvolverListEvents($arguments),
                'evolver_upsert_gene' => $this->toolEvolverUpsertGene($arguments),
                'evolver_delete_gene' => $this->toolEvolverDeleteGene($arguments),
                'evolver_stats' => $this->toolEvolverStats(),
                'evolver_safety_status' => $this->toolEvolverSafetyStatus(),
                'evolver_cleanup' => $this->toolEvolverCleanup(),
                'evolver_sync_to_hub' => $this->toolEvolverSyncToHub(),
                default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
            };
        } catch (\InvalidArgumentException $e) {
            // Return proper MCP error for invalid arguments
            error_log('[McpServer] Invalid argument: ' . $e->getMessage());
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'ok' => false,
                            'error' => 'Invalid argument: ' . $e->getMessage(),
                        ]),
                    ],
                ],
                'isError' => true,
            ];
        } catch (\Throwable $e) {
            error_log('[McpServer] Tool execution error: ' . $e->getMessage());
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'ok' => false,
                            'error' => $e->getMessage(),
                        ]),
                    ],
                ],
                'isError' => true,
            ];
        }

        // Build MCP-compliant response
        // Note: Some clients expect specific fields, so we include both standard and extended formats
        $response = [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
        
        // Add isError flag if result indicates error
        if (is_array($result) && isset($result['ok']) && $result['ok'] === false) {
            $response['isError'] = true;
        }
        
        return $response;
    }

    // -------------------------------------------------------------------------
    // Tool implementations
    // -------------------------------------------------------------------------

    private function toolEvolverRun(array $args): array
    {
        // Check safety mode
        if (!$this->safetyController->isOperationAllowed('propose')) {
            return [
                'ok' => false,
                'error' => 'Self-modification is disabled (EVOLVE_ALLOW_SELF_MODIFY=never)',
                'safety_status' => $this->safetyController->getStatusReport(),
            ];
        }

        $context = $args['context'] ?? '';
        $strategy = $args['strategy'] ?? 'balanced';
        $driftEnabled = (bool)($args['driftEnabled'] ?? false);
        $cycleId = $args['cycleId'] ?? null;

        // Signal deduplication
        if ($this->signalDeduplicator) {
            $dedupResult = $this->signalDeduplicator->processSignal('evolver_run', [
                'context_hash' => substr(hash('sha256', $context), 0, 16),
                'strategy' => $strategy,
            ]);
            
            if ($dedupResult['action'] === 'suppressed') {
                return [
                    'ok' => true,
                    'suppressed' => true,
                    'reason' => $dedupResult['summary']['reason'] ?? 'Signal suppressed',
                ];
            }
        }

        // Load assets
        $genes = $this->store->loadGenes();
        $capsules = $this->store->loadCapsules(50);
        $recentEvents = $this->store->loadRecentEvents(20);
        $failedCapsules = $this->store->loadFailedCapsules(20);

        // Extract signals
        $signals = $this->signalExtractor->extract([
            'context' => $context,
            'recentEvents' => $recentEvents,
        ]);

        // Adjust signals based on strategy
        if ($strategy === 'repair-only') {
            $signals = array_filter($signals, fn($s) => in_array($s, ['log_error', 'recurring_error', 'repair_loop_detected']) || str_starts_with($s, 'errsig:'));
            $signals = array_values($signals);
            if (empty($signals)) {
                $signals = ['log_error']; // force repair gene
            }
        } elseif ($strategy === 'innovate') {
            if (!$this->signalExtractor->hasOpportunitySignal($signals)) {
                $signals[] = 'user_feature_request';
            }
        } elseif ($strategy === 'harden') {
            $signals[] = 'security';
            $signals[] = 'harden';
        }

        // Select gene and capsule
        $selection = $this->geneSelector->selectGeneAndCapsule([
            'genes' => $genes,
            'capsules' => $capsules,
            'signals' => $signals,
            'failedCapsules' => $failedCapsules,
            'driftEnabled' => $driftEnabled,
        ]);

        $selectedGene = $selection['selectedGene'];
        $capsuleCandidates = $selection['capsuleCandidates'];
        $selector = $selection['selector'];

        // Build prompt
        $parentEventId = $this->store->getLastEventId();
        $nowIso = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $genesPreview = $this->promptBuilder->formatGenesPreview($genes);
        $capsulesPreview = $this->promptBuilder->formatCapsulesPreview($capsules);

        $prompt = $this->promptBuilder->buildGepPrompt([
            'nowIso' => $nowIso,
            'context' => $context,
            'signals' => $signals,
            'selector' => $selector,
            'parentEventId' => $parentEventId,
            'selectedGene' => $selectedGene,
            'capsuleCandidates' => $capsuleCandidates,
            'genesPreview' => $genesPreview,
            'capsulesPreview' => $capsulesPreview,
            'cycleId' => $cycleId,
            'failedCapsules' => $failedCapsules,
        ]);

        // If we have a strong capsule candidate, also build a reuse prompt
        $reusePrompt = null;
        if (!empty($capsuleCandidates) && ($capsuleCandidates[0]['confidence'] ?? 0) >= 0.8) {
            $reusePrompt = $this->promptBuilder->buildReusePrompt([
                'capsule' => $capsuleCandidates[0],
                'signals' => $signals,
                'nowIso' => $nowIso,
            ]);
        }

        return [
            'ok' => true,
            'signals' => $signals,
            'selectedGene' => $selectedGene ? ['id' => $selectedGene['id'], 'category' => $selectedGene['category'], 'asset_id' => $selectedGene['asset_id'] ?? null] : null,
            'selector' => $selector,
            'prompt' => $prompt,
            'reusePrompt' => $reusePrompt,
            'safety_mode' => $this->safetyController->getMode(),
            'stats' => [
                'genesAvailable' => count($genes),
                'capsulesAvailable' => count($capsules),
                'recentEventsAnalyzed' => count($recentEvents),
            ],
        ];
    }

    private function toolEvolverSolidify(array $args): array
    {
        // Check safety mode for modifications
        $blastRadius = $args['blastRadius'] ?? ['files' => 0, 'lines' => 0];
        $files = $args['modifiedFiles'] ?? [];
        
        $modificationCheck = $this->safetyController->validateModification([
            'files' => $files,
            'lines' => $blastRadius['lines'] ?? 0,
            'gene' => $args['gene'] ?? null,
            'mutation' => $args['mutation'] ?? null,
        ]);

        if (!$modificationCheck['allowed']) {
            return [
                'ok' => false,
                'error' => $modificationCheck['reason'] ?? 'Modification not allowed',
                'violations' => $modificationCheck['violations'] ?? [],
                'safety_status' => $this->safetyController->getStatusReport(),
            ];
        }

        $intent = $args['intent'] ?? 'repair';
        $summary = $args['summary'] ?? '';
        $signals = $args['signals'] ?? [];
        $geneData = $args['gene'] ?? null;
        $capsuleData = $args['capsule'] ?? null;
        $eventData = $args['event'] ?? null;
        $mutationData = $args['mutation'] ?? null;
        $personalityState = $args['personalityState'] ?? null;
        $dryRun = (bool)($args['dryRun'] ?? false);
        $gepOutput = $args['gepOutput'] ?? null;

        // If gepOutput is provided, parse it to extract GEP objects
        if ($gepOutput !== null) {
            $gepObjects = $this->solidifyEngine->parseGepObjects((string)$gepOutput);
            foreach ($gepObjects as $obj) {
                $type = $obj['type'] ?? '';
                if ($type === 'Gene' && $geneData === null) $geneData = $obj;
                if ($type === 'Capsule' && $capsuleData === null) $capsuleData = $obj;
                if ($type === 'EvolutionEvent' && $eventData === null) $eventData = $obj;
                if ($type === 'Mutation' && $mutationData === null) $mutationData = $obj;
                if ($type === 'PersonalityState' && $personalityState === null) $personalityState = $obj;
            }
            // Extract signals from event if not provided
            if (empty($signals) && $eventData !== null) {
                $signals = $eventData['signals'] ?? [];
            }
            // Extract intent from event if not provided
            if ($intent === 'repair' && $eventData !== null) {
                $intent = $eventData['intent'] ?? 'repair';
            }
        }

        $result = $this->solidifyEngine->solidify([
            'intent' => $intent,
            'summary' => $summary,
            'signals' => $signals,
            'gene' => $geneData,
            'capsule' => $capsuleData,
            'event' => $eventData,
            'mutation' => $mutationData,
            'personalityState' => $personalityState,
            'blastRadius' => $blastRadius,
            'dryRun' => $dryRun,
            'mutationsTried' => $args['mutationsTried'] ?? 1,
            'totalCycles' => $args['totalCycles'] ?? 1,
        ]);

        // Record cycle in lifecycle manager
        if ($this->lifecycleManager && !$dryRun) {
            $this->lifecycleManager->recordCycle($result['ok']);
        }

        return $result;
    }

    private function toolEvolverExtractSignals(array $args): array
    {
        $logContent = $args['logContent'] ?? '';
        $context = $args['context'] ?? $logContent;
        $includeHistory = (bool)($args['includeHistory'] ?? true);

        $recentEvents = [];
        if ($includeHistory) {
            $recentEvents = $this->store->loadRecentEvents(10);
        }

        $signals = $this->signalExtractor->extract([
            'context' => $context,
            'recentEvents' => $recentEvents,
        ]);

        $hasOpportunity = $this->signalExtractor->hasOpportunitySignal($signals);

        return [
            'ok' => true,
            'signals' => $signals,
            'count' => count($signals),
            'hasOpportunitySignal' => $hasOpportunity,
            'hasErrorSignal' => in_array('log_error', $signals) || in_array('recurring_error', $signals),
        ];
    }

    private function toolEvolverListGenes(array $args): array
    {
        $category = $args['category'] ?? null;
        $genes = $this->store->loadGenes();

        if ($category !== null) {
            $genes = array_filter($genes, fn($g) => ($g['category'] ?? '') === $category);
            $genes = array_values($genes);
        }

        return [
            'ok' => true,
            'genes' => $genes,
            'count' => count($genes),
        ];
    }

    private function toolEvolverListCapsules(array $args): array
    {
        $limit = min((int)($args['limit'] ?? 20), 100);
        $capsules = $this->store->loadCapsules($limit);

        return [
            'ok' => true,
            'capsules' => $capsules,
            'count' => count($capsules),
        ];
    }

    private function toolEvolverListEvents(array $args): array
    {
        $limit = min((int)($args['limit'] ?? 20), 100);
        $events = $this->store->loadRecentEvents($limit);

        return [
            'ok' => true,
            'events' => $events,
            'count' => count($events),
        ];
    }

    private function toolEvolverUpsertGene(array $args): array
    {
        // Check if modification is allowed
        if (!$this->safetyController->isOperationAllowed('modify')) {
            return [
                'ok' => false,
                'error' => 'Gene modification is not allowed in current safety mode',
                'safety_mode' => $this->safetyController->getMode(),
            ];
        }

        $gene = $args['gene'] ?? null;
        if (!is_array($gene) || empty($gene['id'])) {
            throw new \InvalidArgumentException('Gene object with id required');
        }

        $gene['type'] = 'Gene';
        
        // Compute asset_id if not present
        if (!isset($gene['asset_id'])) {
            $gene['asset_id'] = ContentHash::computeAssetId($gene);
        }
        
        $this->store->upsertGene($gene);

        return [
            'ok' => true,
            'geneId' => $gene['id'],
            'assetId' => $gene['asset_id'],
            'message' => 'Gene stored successfully',
        ];
    }

    private function toolEvolverDeleteGene(array $args): array
    {
        if (!$this->safetyController->isOperationAllowed('modify')) {
            return [
                'ok' => false,
                'error' => 'Gene deletion is not allowed in current safety mode',
                'safety_mode' => $this->safetyController->getMode(),
            ];
        }

        $geneId = $args['geneId'] ?? null;
        if (empty($geneId) || !is_string($geneId)) {
            throw new \InvalidArgumentException('geneId (string) required');
        }

        $existing = $this->store->getGene($geneId);
        if ($existing === null) {
            return [
                'ok' => false,
                'geneId' => $geneId,
                'message' => 'Gene not found',
            ];
        }

        $this->store->deleteGene($geneId);

        return [
            'ok' => true,
            'geneId' => $geneId,
            'message' => 'Gene deleted successfully',
        ];
    }

    private function toolEvolverStats(): array
    {
        $stats = $this->store->getStats();
        
        return [
            'ok' => true,
            'stats' => $stats,
            'safety_status' => $this->safetyController->getStatusReport(),
            'node_id' => $this->evoMapClient ? $this->evoMapClient->getNodeId() : null,
        ];
    }

    private function toolEvolverSafetyStatus(): array
    {
        return [
            'ok' => true,
            'safety_status' => $this->safetyController->getStatusReport(),
            'source_protection' => $this->sourceProtector->getProtectionReport(),
        ];
    }

    private function toolEvolverCleanup(): array
    {
        if (!$this->diskCleaner) {
            return ['ok' => false, 'error' => 'Disk cleaner not available'];
        }

        $results = $this->diskCleaner->cleanup();
        $results['ok'] = true;
        
        return $results;
    }

    private function toolEvolverSyncToHub(): array
    {
        if (!$this->evoMapClient || !$this->evoMapClient->isConfigured()) {
            return [
                'ok' => false,
                'error' => 'EvoMap Hub not configured. Set A2A_HUB_URL environment variable.',
            ];
        }

        // Send hello to register
        $stats = $this->store->getStats();
        $helloResult = $this->evoMapClient->sendHello([], $stats['genes'], $stats['capsules']);

        if (!$helloResult['ok']) {
            return [
                'ok' => false,
                'error' => 'Failed to register with hub: ' . ($helloResult['error'] ?? 'unknown'),
            ];
        }

        // Sync pending assets
        $pendingGenes = $this->store->getPendingSyncAssets('gene', 10);
        $pendingCapsules = $this->store->getPendingSyncAssets('capsule', 10);

        $synced = ['genes' => 0, 'capsules' => 0, 'errors' => []];

        foreach ($pendingGenes as $item) {
            $gene = $this->store->getGene($item['local_id']);
            if ($gene) {
                $result = $this->evoMapClient->publishAsset($gene);
                if ($result['ok']) {
                    $this->store->updateSyncStatus('gene', $item['local_id'], $gene['asset_id'] ?? null, 'synced');
                    $synced['genes']++;
                } else {
                    $synced['errors'][] = 'Gene ' . $item['local_id'] . ': ' . ($result['error'] ?? 'unknown');
                }
            }
        }

        foreach ($pendingCapsules as $item) {
            $capsule = $this->store->getCapsule($item['local_id']);
            if ($capsule) {
                $result = $this->evoMapClient->publishAsset($capsule);
                if ($result['ok']) {
                    $this->store->updateSyncStatus('capsule', $item['local_id'], $capsule['asset_id'] ?? null, 'synced');
                    $synced['capsules']++;
                } else {
                    $synced['errors'][] = 'Capsule ' . $item['local_id'] . ': ' . ($result['error'] ?? 'unknown');
                }
            }
        }

        return [
            'ok' => true,
            'hub_url' => $this->evoMapClient ? $this->getHubUrlFromEnv() : null,
            'synced' => $synced,
            'node_id' => $this->evoMapClient->getNodeId(),
        ];
    }

    private function getHubUrlFromEnv(): string
    {
        return getenv('A2A_HUB_URL') ?: getenv('EVOMAP_HUB_URL') ?: 'https://evomap.ai';
    }

    // -------------------------------------------------------------------------
    // MCP Resources
    // -------------------------------------------------------------------------

    private function handleResourcesList(): array
    {
        return ['resources' => $this->getResourceDefinitions()];
    }

    private function handleResourcesRead(array $params): array
    {
        $uri = $params['uri'] ?? throw new \InvalidArgumentException('uri required for resources/read');

        $content = match ($uri) {
            'gep://genes'    => $this->resourceGenes(),
            'gep://capsules' => $this->resourceCapsules(),
            'gep://events'   => $this->resourceEvents(),
            'gep://schema'   => $this->resourceSchema(),
            'gep://stats'    => $this->resourceStats(),
            'gep://safety'   => $this->resourceSafety(),
            'gep://health'   => $this->resourceHealth(),
            default          => throw new \InvalidArgumentException("Unknown resource URI: {$uri}"),
        };

        return [
            'contents' => [
                [
                    'uri'      => $uri,
                    'mimeType' => 'application/json',
                    'text'     => json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }

    private function getResourceDefinitions(): array
    {
        return [
            [
                'uri'         => 'gep://genes',
                'name'        => 'GEP Genes',
                'description' => '🧬 All evolution Gene templates stored in the local GEP asset store. '
                    . 'Genes are reusable strategy blueprints matched against extracted signals.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://capsules',
                'name'        => 'GEP Capsules',
                'description' => '💊 All successful evolution Capsule snapshots. '
                    . 'Capsules record past successful fixes/innovations for reuse.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://events',
                'name'        => 'GEP Evolution Events',
                'description' => '📜 Recent EvolutionEvent audit trail (last 50). '
                    . 'Each event records intent, signals, gene used, blast radius, and outcome.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://schema',
                'name'        => 'GEP Protocol Schema',
                'description' => '📐 The Genome Evolution Protocol (GEP) schema definition — '
                    . 'the 5 mandatory output objects (Mutation, PersonalityState, EvolutionEvent, Gene, Capsule) '
                    . 'and their field specifications.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://stats',
                'name'        => 'GEP Store Statistics',
                'description' => '📊 Gene, Capsule, Event, and FailedCapsule counts, plus the current environment fingerprint.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://safety',
                'name'        => 'Safety Status',
                'description' => '🛡️ Current safety configuration including self-modification mode and source protection.',
                'mimeType'    => 'application/json',
            ],
            [
                'uri'         => 'gep://health',
                'name'        => 'Database Health',
                'description' => '💓 Database health status including integrity check, schema version, and migration history.',
                'mimeType'    => 'application/json',
            ],
        ];
    }

    private function resourceGenes(): array
    {
        $genes = $this->store->loadGenes();
        return [
            'type'    => 'GEP_Genes',
            'version' => ContentHash::SCHEMA_VERSION,
            'count'   => count($genes),
            'genes'   => $genes,
        ];
    }

    private function resourceCapsules(): array
    {
        $capsules = $this->store->loadCapsules(100);
        return [
            'type'     => 'GEP_Capsules',
            'version'  => ContentHash::SCHEMA_VERSION,
            'count'    => count($capsules),
            'capsules' => $capsules,
        ];
    }

    private function resourceEvents(): array
    {
        $events = $this->store->loadRecentEvents(50);
        return [
            'type'    => 'GEP_EvolutionEvents',
            'version' => ContentHash::SCHEMA_VERSION,
            'count'   => count($events),
            'events'  => $events,
        ];
    }

    private function resourceSchema(): array
    {
        return [
            'type'           => 'GEP_Schema',
            'schema_version' => ContentHash::SCHEMA_VERSION,
            'description'    => 'Genome Evolution Protocol — mandatory output objects',
            'objects'        => [
                [
                    'index'       => 0,
                    'type'        => 'Mutation',
                    'role'        => 'The Trigger — MUST be first',
                    'required'    => true,
                    'fields'      => [
                        'type'            => 'string (literal "Mutation")',
                        'id'              => 'string (mut_<timestamp>)',
                        'category'        => 'repair | optimize | innovate',
                        'trigger_signals' => 'string[]',
                        'target'          => 'string (module or gene_id)',
                        'expected_effect' => 'string',
                        'risk_level'      => 'low | medium | high',
                        'rationale'       => 'string',
                    ],
                ],
                [
                    'index'    => 1,
                    'type'     => 'PersonalityState',
                    'role'     => 'The Mood',
                    'required' => true,
                    'fields'   => [
                        'type'           => 'string (literal "PersonalityState")',
                        'rigor'          => 'float 0.0–1.0',
                        'creativity'     => 'float 0.0–1.0',
                        'verbosity'      => 'float 0.0–1.0',
                        'risk_tolerance' => 'float 0.0–1.0',
                        'obedience'      => 'float 0.0–1.0',
                    ],
                ],
                [
                    'index'    => 2,
                    'type'     => 'EvolutionEvent',
                    'role'     => 'The Record',
                    'required' => true,
                    'fields'   => [
                        'type'              => 'string (literal "EvolutionEvent")',
                        'schema_version'    => 'string (e.g. "1.6.0")',
                        'id'                => 'string (evt_<timestamp>)',
                        'asset_id'          => 'string (sha256:<hex>)',
                        'parent'            => 'string | null',
                        'intent'            => 'repair | optimize | innovate',
                        'signals'           => 'string[]',
                        'genes_used'        => 'string[]',
                        'mutation_id'       => 'string',
                        'personality_state' => 'PersonalityState object',
                        'blast_radius'      => '{ "files": int, "lines": int }',
                        'outcome'           => '{ "status": "success|failed", "score": float }',
                        'env_fingerprint'   => 'EnvFingerprint object (PHP runtime info)',
                        'mutations_tried'   => 'int',
                        'total_cycles'      => 'int',
                    ],
                ],
                [
                    'index'    => 3,
                    'type'     => 'Gene',
                    'role'     => 'The Knowledge — reuse/update existing ID if possible',
                    'required' => true,
                    'fields'   => [
                        'type'             => 'string (literal "Gene")',
                        'schema_version'   => 'string',
                        'id'               => 'string (gene_<name>)',
                        'asset_id'         => 'string (sha256:<hex>)',
                        'category'         => 'repair | optimize | innovate',
                        'signals_match'    => 'string[] (substring or /regex/)',
                        'preconditions'    => 'string[]',
                        'strategy'         => 'string[] (ordered steps)',
                        'constraints'      => '{ "max_files": int, "forbidden_paths": string[] }',
                        'validation'       => 'string[] (allowed: php/composer/phpunit/phpcs/phpstan)',
                        'epigenetic_marks' => 'string[] (optional)',
                    ],
                ],
                [
                    'index'    => 4,
                    'type'     => 'Capsule',
                    'role'     => 'The Result — only on success',
                    'required' => true,
                    'fields'   => [
                        'type'            => 'string (literal "Capsule")',
                        'schema_version'  => 'string',
                        'id'              => 'string (capsule_<timestamp>)',
                        'asset_id'        => 'string (sha256:<hex>)',
                        'trigger'         => 'string[] (signals that triggered this)',
                        'gene'            => 'string (gene_id used)',
                        'summary'         => 'string (one sentence)',
                        'content'         => 'string (optional, max 8000 chars)',
                        'confidence'      => 'float 0.0–1.0',
                        'blast_radius'    => '{ "files": int, "lines": int }',
                        'outcome'         => '{ "status": "success|failed", "score": float }',
                        'env_fingerprint' => 'EnvFingerprint object',
                        'success_streak'  => 'int',
                    ],
                ],
            ],
            'rules' => [
                'Output RAW JSON ONLY — no markdown code blocks',
                'Output separate JSON objects — DO NOT wrap in a single array',
                'Missing any object = PROTOCOL FAILURE',
                'Validate JSON syntax before output',
                'Objects must appear in order: Mutation → PersonalityState → EvolutionEvent → Gene → Capsule',
            ],
            'safety' => [
                'blast_radius_hard_limits' => ['files' => 60, 'lines' => 20000],
                'validation_command_whitelist' => ['php', 'composer', 'phpunit', 'phpcs', 'phpstan'],
                'forbidden_shell_operators' => [';', '&&', '||', '|', '>', '<', '`', '$('],
            ],
        ];
    }

    private function resourceStats(): array
    {
        $stats = $this->store->getStats();
        $fp = EnvFingerprint::capture();
        return [
            'type'            => 'GEP_Stats',
            'schema_version'  => ContentHash::SCHEMA_VERSION,
            'store'           => $stats,
            'env_fingerprint' => $fp,
            'node_id'         => $this->evoMapClient ? $this->evoMapClient->getNodeId() : null,
            'timestamp'       => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resourceSafety(): array
    {
        return [
            'type' => 'GEP_SafetyStatus',
            'safety_controller' => $this->safetyController->getStatusReport(),
            'source_protector' => $this->sourceProtector->getProtectionReport(),
        ];
    }

    private function resourceHealth(): array
    {
        $db = $this->db;
        return [
            'type' => 'GEP_DatabaseHealth',
            'health' => $db->getHealthStatus(),
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // -------------------------------------------------------------------------
    // Tool definitions
    // -------------------------------------------------------------------------

    private function getToolDefinitions(): array
    {
        return [
            [
                'name' => 'evolver_run',
                'description' => '🧬 Run an evolution cycle: extract signals from context, select best Gene/Capsule, and generate a GEP protocol prompt to guide safe evolution. Returns signals, selected gene, and the full GEP prompt.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'context' => [
                            'type' => 'string',
                            'description' => 'Current context, logs, session history, or user instructions to analyze for signals',
                        ],
                        'strategy' => [
                            'type' => 'string',
                            'enum' => ['balanced', 'innovate', 'harden', 'repair-only'],
                            'description' => 'Evolution strategy preset. balanced=default, innovate=maximize features, harden=stability focus, repair-only=emergency fix mode',
                            'default' => 'balanced',
                        ],
                        'driftEnabled' => [
                            'type' => 'boolean',
                            'description' => 'Enable stochastic gene selection (genetic drift) for exploration',
                            'default' => false,
                        ],
                        'cycleId' => [
                            'type' => ['string', 'null'],
                            'description' => 'Optional cycle identifier for tracking',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_solidify',
                'description' => '💾 Solidify an evolution result: validate constraints, record EvolutionEvent, update Gene, and store Capsule on success. Call after successfully applying changes guided by evolver_run.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'intent' => [
                            'type' => 'string',
                            'enum' => ['repair', 'optimize', 'innovate'],
                            'description' => 'Evolution intent',
                        ],
                        'summary' => [
                            'type' => 'string',
                            'description' => 'One-sentence summary of what was changed and why',
                        ],
                        'signals' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Signal strings that triggered this evolution',
                        ],
                        'gene' => [
                            'type' => ['object', 'null'],
                            'description' => 'Gene object used/created (from GEP output)',
                        ],
                        'capsule' => [
                            'type' => ['object', 'null'],
                            'description' => 'Capsule object to store (from GEP output)',
                        ],
                        'blastRadius' => [
                            'type' => 'object',
                            'properties' => [
                                'files' => ['type' => 'integer'],
                                'lines' => ['type' => 'integer'],
                            ],
                            'description' => 'Number of files and lines changed',
                        ],
                        'modifiedFiles' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'List of files that were modified',
                        ],
                        'gepOutput' => [
                            'type' => ['string', 'null'],
                            'description' => 'Raw GEP output text to parse (alternative to providing individual objects)',
                        ],
                        'dryRun' => [
                            'type' => 'boolean',
                            'description' => 'If true, validate but do not write to database',
                            'default' => false,
                        ],
                    ],
                    'required' => ['intent', 'summary'],
                ],
            ],
            [
                'name' => 'evolver_extract_signals',
                'description' => '🔍 Extract evolution signals from log content or context. Returns detected signals and whether they indicate errors or opportunities.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'logContent' => [
                            'type' => 'string',
                            'description' => 'Log content, error messages, or session transcript to analyze',
                        ],
                        'context' => [
                            'type' => 'string',
                            'description' => 'Additional context (merged with logContent if both provided)',
                        ],
                        'includeHistory' => [
                            'type' => 'boolean',
                            'description' => 'Whether to include recent evolution history in signal analysis (for de-duplication)',
                            'default' => true,
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_list_genes',
                'description' => '📋 List available evolution Genes. Genes are reusable strategy templates.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['repair', 'optimize', 'innovate'],
                            'description' => 'Filter by gene category',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_list_capsules',
                'description' => '💊 List available evolution Capsules. Capsules are snapshots of successful evolution results.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of capsules to return',
                            'default' => 20,
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_list_events',
                'description' => '📜 List recent evolution events for audit trail.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of events to return',
                            'default' => 20,
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_upsert_gene',
                'description' => '🧬 Create or update a Gene in the store. Use this to save a new Gene discovered during evolution.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'gene' => [
                            'type' => 'object',
                            'description' => 'Gene object (must have id, category, signals_match, strategy)',
                            'required' => ['id', 'category'],
                        ],
                    ],
                    'required' => ['gene'],
                ],
            ],
            [
                'name' => 'evolver_delete_gene',
                'description' => '🗑️ Delete a Gene from the store by its ID. Use with care — this permanently removes the Gene.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'geneId' => [
                            'type' => 'string',
                            'description' => 'The ID of the Gene to delete (e.g. "gene_gep_repair_from_errors")',
                        ],
                    ],
                    'required' => ['geneId'],
                ],
            ],
            [
                'name' => 'evolver_stats',
                'description' => '📊 Get statistics about the evolution store (gene count, capsule count, event count).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_safety_status',
                'description' => '🛡️ Get current safety status including self-modification mode and source protection.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_cleanup',
                'description' => '🧹 Run cleanup operations: archive old events, compress logs, remove temp files.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
            [
                'name' => 'evolver_sync_to_hub',
                'description' => '🌐 Sync pending assets to EvoMap Hub. Requires A2A_HUB_URL environment variable.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // JSON-RPC helpers
    // -------------------------------------------------------------------------

    private function sendResult(mixed $id, mixed $result): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result ?? new \stdClass(),
        ];
        $this->sendMessage($response);
    }

    private function sendError(mixed $id, int $code, string $message, mixed $data): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
                'data' => $data,
            ],
        ];
        $this->sendMessage($response);
    }

    private function sendNotification(string $method, mixed $params): void
    {
        $notification = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
        ];
        $this->sendMessage($notification);
    }

    private function sendMessage(array $message): void
    {
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        fwrite($this->stdout, $json . "\n");
        fflush($this->stdout);
    }
}
