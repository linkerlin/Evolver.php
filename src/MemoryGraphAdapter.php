<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Memory Graph Adapter -- stable interface boundary for memory graph operations.
 *
 * Default implementation delegates to the local JSONL-based MemoryGraph.php.
 * SaaS providers can supply a remote adapter by setting MEMORY_GRAPH_PROVIDER=remote
 * and configuring MEMORY_GRAPH_REMOTE_URL / MEMORY_GRAPH_REMOTE_KEY.
 *
 * The adapter is designed so that the open-source evolver always works offline
 * with the local implementation. Remote is optional and degrades gracefully.
 *
 * Ported from evolver/src/gep/memoryGraphAdapter.js
 */
final class MemoryGraphAdapter
{
    private string $name;
    private MemoryGraph $localGraph;
    private ?string $remoteUrl = null;
    private ?string $remoteKey = null;
    private int $timeoutMs = 5000;

    private function __construct(string $name, MemoryGraph $localGraph)
    {
        $this->name = $name;
        $this->localGraph = $localGraph;

        if ($name === 'remote') {
            $this->remoteUrl = $_ENV['MEMORY_GRAPH_REMOTE_URL'] ?? null;
            $this->remoteKey = $_ENV['MEMORY_GRAPH_REMOTE_KEY'] ?? null;
            $this->timeoutMs = (int)($_ENV['MEMORY_GRAPH_REMOTE_TIMEOUT_MS'] ?? 5000);
        }
    }

    /**
     * Resolve adapter based on environment configuration.
     */
    public static function resolve(): self
    {
        $provider = strtolower(trim($_ENV['MEMORY_GRAPH_PROVIDER'] ?? 'local'));
        $localGraph = new MemoryGraph();

        if ($provider === 'remote') {
            return new self('remote', $localGraph);
        }

        return new self('local', $localGraph);
    }

    /**
     * Get adapter name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get memory advice from the graph.
     * Remote enhancement candidate for richer graph reasoning.
     */
    public function getAdvice(array $opts): array
    {
        if ($this->name === 'remote' && $this->remoteUrl) {
            try {
                return $this->remoteGetAdvice($opts);
            } catch (\Throwable) {
                // Fallback to local on any remote failure
            }
        }

        return $this->localGraph->getMemoryAdvice($opts);
    }

    /**
     * Record a signal snapshot.
     */
    public function recordSignalSnapshot(array $opts): array
    {
        $ev = $this->localGraph->recordSignalSnapshot($opts);

        if ($this->name === 'remote' && $this->remoteUrl) {
            $this->remoteIngest('signal', $ev);
        }

        return $ev;
    }

    /**
     * Record a hypothesis.
     */
    public function recordHypothesis(array $opts): array
    {
        $result = $this->localGraph->recordHypothesis($opts);

        if ($this->name === 'remote' && $this->remoteUrl) {
            $this->remoteIngest('hypothesis', $result);
        }

        return $result;
    }

    /**
     * Record an attempt.
     */
    public function recordAttempt(array $opts): array
    {
        $result = $this->localGraph->recordAttempt($opts);

        if ($this->name === 'remote' && $this->remoteUrl) {
            $this->remoteIngest('attempt', $result);
        }

        return $result;
    }

    /**
     * Record an outcome.
     */
    public function recordOutcome(array $opts): ?array
    {
        $ev = $this->localGraph->recordOutcomeFromState($opts);

        if ($ev !== null && $this->name === 'remote' && $this->remoteUrl) {
            $this->remoteIngest('outcome', $ev);
        }

        return $ev;
    }

    /**
     * Record an external candidate.
     */
    public function recordExternalCandidate(array $opts): ?array
    {
        $ev = $this->localGraph->recordExternalCandidate($opts);

        if ($ev !== null && $this->name === 'remote' && $this->remoteUrl) {
            $this->remoteIngest('external_candidate', $ev);
        }

        return $ev;
    }

    /**
     * Get memory graph file path.
     */
    public function memoryGraphPath(): string
    {
        return $this->localGraph->memoryGraphPath();
    }

    /**
     * Compute signal key.
     */
    public function computeSignalKey(array $signals): string
    {
        return $this->localGraph->computeSignalKey($signals);
    }

    /**
     * Read memory graph events.
     */
    public function tryReadMemoryGraphEvents(int $limit = 100): array
    {
        return $this->localGraph->tryReadMemoryGraphEvents($limit);
    }

    /**
     * Remote call to get advice from KG service.
     */
    private function remoteGetAdvice(array $opts): array
    {
        if (empty($this->remoteUrl)) {
            throw new \RuntimeException('MEMORY_GRAPH_REMOTE_URL not configured');
        }

        $url = rtrim($this->remoteUrl, '/') . '/kg/advice';
        $body = [
            'signals' => $opts['signals'] ?? [],
            'genes' => array_map(fn($g) => [
                'id' => $g['id'] ?? null,
                'category' => $g['category'] ?? null,
                'type' => $g['type'] ?? null,
            ], $opts['genes'] ?? []),
            'driftEnabled' => $opts['driftEnabled'] ?? false,
        ];

        $result = $this->remoteCall($url, $body);

        // Normalize remote response to match local contract
        return [
            'currentSignalKey' => $result['currentSignalKey'] ?? $this->localGraph->computeSignalKey($opts['signals'] ?? []),
            'preferredGeneId' => $result['preferredGeneId'] ?? null,
            'bannedGeneIds' => $result['bannedGeneIds'] ?? [],
            'explanation' => $result['explanation'] ?? [],
        ];
    }

    /**
     * Remote call to ingest event to KG service.
     */
    private function remoteIngest(string $kind, array $event): void
    {
        if (empty($this->remoteUrl)) {
            return;
        }

        $url = rtrim($this->remoteUrl, '/') . '/kg/ingest';
        $body = [
            'kind' => $kind,
            'event' => $event,
        ];

        // Fire and forget - don't block on remote ingest
        try {
            $this->remoteCall($url, $body);
        } catch (\Throwable) {
            // Silently ignore remote ingest failures
        }
    }

    /**
     * Make a remote HTTP call.
     */
    private function remoteCall(string $url, array $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $headers = ['Content-Type: application/json'];
        if ($this->remoteKey) {
            $headers[] = 'Authorization: Bearer ' . $this->remoteKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('cURL error: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('remote_kg_error: ' . $httpCode);
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response');
        }

        return $result;
    }
}
