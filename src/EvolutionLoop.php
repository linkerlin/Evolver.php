<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Evolution Loop - runs continuous evolution cycles in the background.
 * Executes evolution at regular intervals and manages the evolution lifecycle.
 */
final class EvolutionLoop
{
    private Database $db;
    private GepAssetStore $store;
    private SignalExtractor $signalExtractor;
    private GeneSelector $geneSelector;
    private PromptBuilder $promptBuilder;
    private SafetyController $safetyController;

    private bool $running = false;
    private bool $shouldStop = false;
    private int $intervalSeconds = 60;
    private int $cyclesCompleted = 0;
    private int $cyclesFailed = 0;
    private ?int $startTime = null;

    public function __construct(Database $db, int $intervalSeconds = 60)
    {
        $this->db = $db;
        $this->store = new GepAssetStore($db);
        $this->signalExtractor = new SignalExtractor();
        $this->geneSelector = new GeneSelector();
        $this->promptBuilder = new PromptBuilder();
        $this->safetyController = SafetyController::fromEnvironment();
        $this->intervalSeconds = $intervalSeconds;
    }

    /**
     * Run the evolution loop.
     */
    public function run(): void
    {
        $this->running = true;
        $this->startTime = time();
        $this->registerSignalHandlers();

        error_log('[EvolutionLoop] Starting evolution loop with ' . $this->intervalSeconds . 's interval');

        while (!$this->shouldStop) {
            $this->executeCycle();

            // Sleep in small increments to allow signal handling
            for ($i = 0; $i < $this->intervalSeconds && !$this->shouldStop; $i++) {
                sleep(1);
            }
        }

        $this->running = false;
        error_log('[EvolutionLoop] Evolution loop stopped');
    }

    /**
     * Execute a single evolution cycle.
     */
    private function executeCycle(): void
    {
        error_log('[EvolutionLoop] Executing evolution cycle #' . ($this->cyclesCompleted + $this->cyclesFailed + 1));

        try {
            // Check if modifications are allowed
            if (!$this->safetyController->isSelfModifyAllowed()) {
                error_log('[EvolutionLoop] Self-modification disabled, skipping cycle');
                return;
            }

            // Get recent events for context
            $recentEvents = $this->store->loadRecentEvents(5);

            // Build context from recent events
            $context = $this->buildContextFromEvents($recentEvents);

            // Extract signals from context
            $signals = $this->signalExtractor->extract($context);

            if (empty($signals)) {
                error_log('[EvolutionLoop] No signals detected, skipping cycle');
                return;
            }

            // Load available genes
            $genes = $this->store->loadGenes();
            if (empty($genes)) {
                error_log('[EvolutionLoop] No genes available, skipping cycle');
                return;
            }

            // Select best gene
            $selectedGene = $this->geneSelector->selectGene($genes, $signals);

            if (empty($selectedGene)) {
                error_log('[EvolutionLoop] No suitable gene found, skipping cycle');
                return;
            }

            // Build GEP prompt (this would be used by an LLM in real usage)
            $promptInput = [
                'context' => $context,
                'signals' => $signals,
                'selectedGene' => $selectedGene,
                'parentEventId' => $this->store->getLastEventId(),
            ];
            $prompt = $this->promptBuilder->buildGepPrompt($promptInput);

            // In loop mode, we just log the prompt that would be used
            // In real usage, this would be sent to an LLM
            error_log('[EvolutionLoop] Generated GEP prompt for gene: ' . ($selectedGene['id'] ?? 'unknown'));

            $this->cyclesCompleted++;
            error_log('[EvolutionLoop] Cycle completed successfully. Total: ' . $this->cyclesCompleted);

        } catch (\Throwable $e) {
            $this->cyclesFailed++;
            error_log('[EvolutionLoop] Cycle failed: ' . $e->getMessage());
        }
    }

    /**
     * Build context from recent evolution events.
     */
    private function buildContextFromEvents(array $events): string
    {
        if (empty($events)) {
            return 'No recent evolution events';
        }

        $parts = [];
        foreach ($events as $event) {
            $parts[] = sprintf(
                '[%s] %s: %s (outcome: %s)',
                $event['created_at'] ?? 'unknown',
                $event['intent'] ?? 'unknown',
                $event['summary'] ?? 'no summary',
                $event['outcome']['status'] ?? 'unknown'
            );
        }

        return implode("\n", $parts);
    }

    /**
     * Stop the loop gracefully.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Check if loop is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get loop statistics.
     */
    public function getStats(): array
    {
        return [
            'running' => $this->running,
            'cycles_completed' => $this->cyclesCompleted,
            'cycles_failed' => $this->cyclesFailed,
            'interval_seconds' => $this->intervalSeconds,
            'uptime_seconds' => $this->startTime ? time() - $this->startTime : 0,
        ];
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
    }

    /**
     * Handle shutdown signals.
     */
    public function handleSignal(int $signal): void
    {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
        ];
        $name = $signalNames[$signal] ?? 'UNKNOWN';
        error_log('[EvolutionLoop] Received ' . $name . ', stopping gracefully');
        $this->stop();
    }
}
