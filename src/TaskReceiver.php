<?php

declare(strict_types=1);

namespace Evolver;

/**
 * TaskReceiver - Pulls external tasks from Hub, auto-claims, and injects
 * them as high-priority signals into the evolution loop.
 *
 * v2: Smart task selection with difficulty-aware ROI scoring and capability
 *     matching via memory graph history.
 *
 * PHP port of taskReceiver.js from EvoMap/evolver.
 */
final class TaskReceiver
{
    private const DEFAULT_HUB_URL = 'https://evomap.ai';
    private const DEFAULT_STRATEGY = 'balanced';
    private const DEFAULT_MIN_CAPABILITY_MATCH = 0.1;
    private const REQUEST_TIMEOUT = 8;
    private const CLAIM_TIMEOUT = 5;

    /** Scoring weights by strategy */
    private const STRATEGY_WEIGHTS = [
        'greedy'       => ['roi' => 0.10, 'capability' => 0.05, 'completion' => 0.05, 'bounty' => 0.80],
        'balanced'     => ['roi' => 0.35, 'capability' => 0.30, 'completion' => 0.20, 'bounty' => 0.15],
        'conservative' => ['roi' => 0.25, 'capability' => 0.45, 'completion' => 0.25, 'bounty' => 0.05],
    ];

    private GepA2AProtocol $protocol;
    private string $hubUrl;
    private string $strategy;
    private float $minCapabilityMatch;

    public function __construct(?GepA2AProtocol $protocol = null, ?string $hubUrl = null)
    {
        $this->protocol = $protocol ?? new GepA2AProtocol();
        $this->hubUrl = $this->normalizeUrl($hubUrl ?? getenv('A2A_HUB_URL') ?: getenv('EVOMAP_HUB_URL') ?: self::DEFAULT_HUB_URL);
        $this->strategy = strtolower(getenv('TASK_STRATEGY') ?: self::DEFAULT_STRATEGY);
        $this->minCapabilityMatch = (float)(getenv('TASK_MIN_CAPABILITY_MATCH') ?: self::DEFAULT_MIN_CAPABILITY_MATCH);
    }

    // -------------------------------------------------------------------------
    // fetchTasks
    // -------------------------------------------------------------------------

    /**
     * Fetch available tasks from Hub via the A2A fetch endpoint.
     *
     * @param array{
     *   questions?: array<array{question: string, amount?: int, signals?: string[]}>
     * } $opts
     * @return array{tasks: array, questions_created?: array, relevant_lessons?: array}
     */
    public function fetchTasks(array $opts = []): array
    {
        $nodeId = $this->protocol->getNodeId();
        if (empty($nodeId)) {
            return ['tasks' => []];
        }

        $payload = [
            'asset_type' => null,
            'include_tasks' => true,
        ];

        if (!empty($opts['questions']) && is_array($opts['questions'])) {
            $payload['questions'] = $opts['questions'];
        }

        $msg = [
            'protocol' => GepA2AProtocol::PROTOCOL_NAME,
            'protocol_version' => GepA2AProtocol::PROTOCOL_VERSION,
            'message_type' => 'fetch',
            'message_id' => GepA2AProtocol::generateMessageId(),
            'sender_id' => $nodeId,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'payload' => $payload,
        ];

        $url = $this->hubUrl . '/a2a/fetch';

        try {
            $response = $this->httpPost($url, $msg, self::REQUEST_TIMEOUT);

            if ($response['status'] !== 200) {
                return ['tasks' => []];
            }

            $data = $response['body'];
            $respPayload = $data['payload'] ?? $data;
            $tasks = is_array($respPayload['tasks'] ?? null) ? $respPayload['tasks'] : [];
            $result = ['tasks' => $tasks];

            if (!empty($respPayload['questions_created'])) {
                $result['questions_created'] = $respPayload['questions_created'];
            }

            // Extract relevant lessons from Hub response
            if (!empty($respPayload['relevant_lessons']) && is_array($respPayload['relevant_lessons'])) {
                $result['relevant_lessons'] = $respPayload['relevant_lessons'];
            }

            return $result;
        } catch (\Throwable $e) {
            echo "[TaskReceiver] fetchTasks failed: " . $e->getMessage() . "\n";
            return ['tasks' => []];
        }
    }

    // -------------------------------------------------------------------------
    // Capability matching
    // -------------------------------------------------------------------------

    /**
     * Parse signals from raw string or array.
     */
    private function parseSignals($raw): array
    {
        if (empty($raw)) return [];
        if (is_array($raw)) return array_map('strtolower', array_map('strval', $raw));
        return array_map('trim', array_filter(explode(',', strtolower((string)$raw))));
    }

    /**
     * Calculate Jaccard similarity between two arrays.
     */
    private function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;
        $setA = array_flip($a);
        $setB = array_flip($b);
        $inter = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $inter;
        return $union > 0 ? $inter / $union : 0.0;
    }

    /**
     * Estimate how well this agent can handle a task based on memory graph history.
     * Returns 0.0 - 1.0 where 1.0 = strong match with high success rate.
     *
     * @param array $task Task from Hub (has .signals field)
     * @param array $memoryEvents From tryReadMemoryGraphEvents()
     * @return float
     */
    public function estimateCapabilityMatch(array $task, array $memoryEvents): float
    {
        if (empty($memoryEvents)) return 0.5;

        $taskSignals = $this->parseSignals($task['signals'] ?? $task['title'] ?? '');
        if (empty($taskSignals)) return 0.5;

        $successBySignalKey = [];
        $totalBySignalKey = [];
        $allSignals = [];

        foreach ($memoryEvents as $ev) {
            if (($ev['type'] ?? '') !== 'MemoryGraphEvent' || ($ev['kind'] ?? '') !== 'outcome') {
                continue;
            }

            $sigs = $ev['signal']['signals'] ?? [];
            $key = $ev['signal']['key'] ?? '';
            $status = $ev['outcome']['status'] ?? '';

            foreach ($sigs as $s) {
                $allSignals[strtolower((string)$s)] = true;
            }

            if (empty($key)) continue;
            if (!isset($totalBySignalKey[$key])) {
                $totalBySignalKey[$key] = 0;
                $successBySignalKey[$key] = 0;
            }
            $totalBySignalKey[$key]++;
            if ($status === 'success') {
                $successBySignalKey[$key]++;
            }
        }

        // Jaccard overlap between task signals and all signals this agent has worked with
        $allSigArr = array_keys($allSignals);
        $overlapScore = $this->jaccard($taskSignals, $allSigArr);

        // Weighted success rate across matching signal keys
        $weightedSuccess = 0.0;
        $weightSum = 0.0;
        foreach ($totalBySignalKey as $sk => $total) {
            // Reconstruct signals from the key for comparison
            $skParts = array_map('strtolower', array_map('trim', array_filter(explode('|', $sk))));
            $sim = $this->jaccard($taskSignals, $skParts);
            if ($sim < 0.15) continue;

            $succ = $successBySignalKey[$sk] ?? 0;
            $rate = ($succ + 1) / ($total + 2); // Laplace smoothing
            $weightedSuccess += $rate * $sim;
            $weightSum += $sim;
        }

        $successScore = $weightSum > 0 ? ($weightedSuccess / $weightSum) : 0.5;

        // Combine: 60% success rate history + 40% signal overlap
        return min(1.0, $overlapScore * 0.4 + $successScore * 0.6);
    }

    // -------------------------------------------------------------------------
    // Local difficulty estimation
    // -------------------------------------------------------------------------

    /**
     * Local fallback difficulty estimation when Hub doesn't provide complexity_score.
     */
    private function localDifficultyEstimate(array $task): float
    {
        $signals = $this->parseSignals($task['signals'] ?? null);
        $signalFactor = min(count($signals) / 8, 1.0);

        $titleWords = count(preg_split('/\s+/', $task['title'] ?? '', -1, PREG_SPLIT_NO_EMPTY));
        $titleFactor = min($titleWords / 15, 1.0);

        return min(1.0, $signalFactor * 0.6 + $titleFactor * 0.4);
    }

    // -------------------------------------------------------------------------
    // Task scoring
    // -------------------------------------------------------------------------

    /**
     * Score a single task for this agent.
     *
     * @param array $task Task from Hub
     * @param float $capabilityMatch From estimateCapabilityMatch()
     * @return array{composite: float, factors: array}
     */
    public function scoreTask(array $task, float $capabilityMatch): array
    {
        $w = self::STRATEGY_WEIGHTS[$this->strategy] ?? self::STRATEGY_WEIGHTS['balanced'];

        $difficulty = $task['complexity_score'] ?? $this->localDifficultyEstimate($task);
        $bountyAmount = (float)($task['bounty_amount'] ?? 0);
        $completionRate = $task['historical_completion_rate'] ?? 0.5;

        // ROI: bounty per unit difficulty (higher = better value)
        $roiRaw = $bountyAmount / ($difficulty + 0.1);
        $roiNorm = min($roiRaw / 200, 1.0); // normalize: 200-credit ROI = max

        // Bounty absolute: normalize against a reference max
        $bountyNorm = min($bountyAmount / 100, 1.0);

        $composite =
            $w['roi'] * $roiNorm +
            $w['capability'] * $capabilityMatch +
            $w['completion'] * $completionRate +
            $w['bounty'] * $bountyNorm;

        return [
            'composite' => round($composite, 3),
            'factors' => [
                'roi' => round($roiNorm, 2),
                'capability' => round($capabilityMatch, 2),
                'completion' => round($completionRate, 2),
                'bounty' => round($bountyNorm, 2),
                'difficulty' => round($difficulty, 2),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Task selection
    // -------------------------------------------------------------------------

    /**
     * Pick the best task from a list using composite scoring.
     *
     * @param array $tasks
     * @param array $memoryEvents From tryReadMemoryGraphEvents()
     * @return array|null
     */
    public function selectBestTask(array $tasks, array $memoryEvents = []): ?array
    {
        if (empty($tasks)) return null;

        $nodeId = $this->protocol->getNodeId();

        // Already-claimed tasks for this node always take top priority (resume work)
        foreach ($tasks as $t) {
            if (($t['status'] ?? '') === 'claimed' && ($t['claimed_by'] ?? '') === $nodeId) {
                return $t;
            }
        }

        // Filter to open tasks only
        $open = array_filter($tasks, fn($t) => ($t['status'] ?? '') === 'open');
        if (empty($open)) return null;

        // Legacy greedy mode: preserve old behavior exactly
        if ($this->strategy === 'greedy' && empty($memoryEvents)) {
            $bountyTasks = array_filter($open, fn($t) => !empty($t['bounty_id']));
            if (!empty($bountyTasks)) {
                usort($bountyTasks, fn($a, $b) => ($b['bounty_amount'] ?? 0) <=> ($a['bounty_amount'] ?? 0));
                return $bountyTasks[0];
            }
            return array_values($open)[0] ?? null;
        }

        // Score all open tasks
        $scored = [];
        foreach ($open as $t) {
            $cap = $this->estimateCapabilityMatch($t, $memoryEvents);
            $result = $this->scoreTask($t, $cap);
            $scored[] = [
                'task' => $t,
                'composite' => $result['composite'],
                'factors' => $result['factors'],
                'capability' => $cap,
            ];
        }

        // Filter by minimum capability match
        if ($this->minCapabilityMatch > 0) {
            $filtered = array_filter($scored, fn($s) => $s['capability'] >= $this->minCapabilityMatch);
            if (!empty($filtered)) {
                $scored = array_values($filtered);
            }
        }

        // Sort by composite score descending
        usort($scored, fn($a, $b) => $b['composite'] <=> $a['composite']);

        // Log top 3 candidates for debugging
        $top3 = array_slice($scored, 0, 3);
        foreach ($top3 as $i => $s) {
            $title = substr($s['task']['title'] ?? $s['task']['task_id'] ?? '', 0, 50);
            echo "[TaskStrategy] #" . ($i + 1) . " \"{$title}\" score={$s['composite']} " .
                json_encode($s['factors']) . "\n";
        }

        return $scored[0]['task'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Task actions
    // -------------------------------------------------------------------------

    /**
     * Claim a task on the Hub.
     *
     * @param string $taskId
     * @return bool True if claim succeeded
     */
    public function claimTask(string $taskId): bool
    {
        $nodeId = $this->protocol->getNodeId();
        if (empty($nodeId) || empty($taskId)) return false;

        $url = $this->hubUrl . '/a2a/task/claim';

        try {
            $response = $this->httpPost($url, [
                'task_id' => $taskId,
                'node_id' => $nodeId,
            ], self::CLAIM_TIMEOUT);

            return $response['status'] >= 200 && $response['status'] < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Complete a task on the Hub with the result asset ID.
     *
     * @param string $taskId
     * @param string $assetId
     * @return bool
     */
    public function completeTask(string $taskId, string $assetId): bool
    {
        $nodeId = $this->protocol->getNodeId();
        if (empty($nodeId) || empty($taskId) || empty($assetId)) return false;

        $url = $this->hubUrl . '/a2a/task/complete';

        try {
            $response = $this->httpPost($url, [
                'task_id' => $taskId,
                'asset_id' => $assetId,
                'node_id' => $nodeId,
            ], self::CLAIM_TIMEOUT);

            return $response['status'] >= 200 && $response['status'] < 300;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Signal extraction
    // -------------------------------------------------------------------------

    /**
     * Extract signals from a task to inject into evolution cycle.
     *
     * @param array $task
     * @return string[]
     */
    public function taskToSignals(array $task): array
    {
        $signals = [];

        if (!empty($task['signals'])) {
            $parts = array_map('trim', explode(',', (string)$task['signals']));
            $signals = array_merge($signals, array_filter($parts));
        }

        if (!empty($task['title'])) {
            $words = preg_split('/\s+/', strtolower((string)$task['title']), -1, PREG_SPLIT_NO_EMPTY);
            $words = array_filter($words, fn($w) => strlen($w) >= 3);
            foreach (array_slice($words, 0, 5) as $w) {
                if (!in_array($w, $signals)) {
                    $signals[] = $w;
                }
            }
        }

        $signals[] = 'external_task';
        if (!empty($task['bounty_id'])) {
            $signals[] = 'bounty_task';
        }

        return array_values(array_unique($signals));
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Perform HTTP POST request.
     *
     * @param string $url
     * @param array $data
     * @param int $timeout
     * @return array{status: int, body: array}
     */
    private function httpPost(string $url, array $data, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize curl");
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("HTTP error: " . $error);
        }

        $body = [];
        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return ['status' => (int)$httpCode, 'body' => $body];
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getHubUrl(): string
    {
        return $this->hubUrl;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getStrategyWeights(): array
    {
        return self::STRATEGY_WEIGHTS;
    }
}
