<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Automatic GitHub issue reporter for recurring evolver failures.
 * When the evolver hits persistent errors (failure streaks, recurring errors),
 * this module files a GitHub issue with sanitized logs and environment info.
 *
 * Ported from evolver/src/gep/issueReporter.js
 */
final class IssueReporter
{
    private const STATE_FILE_NAME = 'issue_reporter_state.json';
    private const DEFAULT_REPO = 'autogame-17/capability-evolver';
    private const DEFAULT_COOLDOWN_MS = 86400000; // 24 hours
    private const DEFAULT_MIN_STREAK = 5;
    private const MAX_LOG_CHARS = 2000;
    private const MAX_EVENTS = 5;
    private const REQUEST_TIMEOUT = 15;

    private GepA2AProtocol $protocol;

    public function __construct(?GepA2AProtocol $protocol = null)
    {
        $this->protocol = $protocol ?? new GepA2AProtocol();
    }

    /**
     * Get configuration from environment.
     *
     * @return array{repo: string, cooldownMs: int, minStreak: int}|null
     */
    public function getConfig(): ?array
    {
        $raw = getenv('EVOLVER_AUTO_ISSUE');
        $enabled = $raw !== false ? strtolower($raw) : 'true';
        if ($enabled === 'false' || $enabled === '0') {
            return null;
        }

        $cooldownRaw = getenv('EVOLVER_ISSUE_COOLDOWN_MS');
        $minStreakRaw = getenv('EVOLVER_ISSUE_MIN_STREAK');

        return [
            'repo' => getenv('EVOLVER_ISSUE_REPO') ?: self::DEFAULT_REPO,
            'cooldownMs' => ($cooldownRaw !== false && $cooldownRaw !== '') ? (int)$cooldownRaw : self::DEFAULT_COOLDOWN_MS,
            'minStreak' => ($minStreakRaw !== false && $minStreakRaw !== '') ? (int)$minStreakRaw : self::DEFAULT_MIN_STREAK,
        ];
    }

    /**
     * Check if an issue should be reported based on signals and cooldown.
     *
     * @param string[] $signals
     * @param array|null $config
     */
    public function shouldReport(array $signals, ?array $config = null): bool
    {
        $config = $config ?? $this->getConfig();
        if ($config === null) {
            return false;
        }

        $hasFailureLoop = in_array('failure_loop_detected', $signals, true);
        $hasRecurringAndHigh = in_array('recurring_error', $signals, true)
            && in_array('high_failure_ratio', $signals, true);

        if (!$hasFailureLoop && !$hasRecurringAndHigh) {
            return false;
        }

        $streakCount = $this->extractStreakCount($signals);
        if ($streakCount > 0 && $streakCount < $config['minStreak']) {
            return false;
        }

        $state = $this->readState();
        $errorKey = $this->computeErrorKey($signals);

        if ($state['lastReportedAt'] !== null) {
            $elapsed = (time() * 1000) - strtotime($state['lastReportedAt']) * 1000;
            if ($elapsed < $config['cooldownMs']) {
                $recentKeys = $state['recentIssueKeys'] ?? [];
                if (in_array($errorKey, $recentKeys, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build the issue body.
     *
     * @param array $opts{
     *   signals?: string[],
     *   recentEvents?: array,
     *   sessionLog?: string,
     *   envFingerprint?: array
     * }
     */
    public function buildIssueBody(array $opts): string
    {
        $fp = $opts['envFingerprint'] ?? $this->captureEnvFingerprint();
        $signals = $opts['signals'] ?? [];
        $recentEvents = $opts['recentEvents'] ?? [];
        $sessionLog = $opts['sessionLog'] ?? '';
        $streakCount = $this->extractStreakCount($signals);
        $errorSig = $this->extractErrorSignature($signals);
        $nodeId = $this->truncateNodeId($this->protocol->getNodeId());

        $failureSignals = array_filter($signals, function ($s) {
            return str_starts_with($s, 'recurring_')
                || str_starts_with($s, 'consecutive_failure')
                || str_starts_with($s, 'failure_loop')
                || str_starts_with($s, 'high_failure')
                || str_starts_with($s, 'ban_gene:')
                || $s === 'force_innovation_after_repair_loop';
        });
        $failureSignalsStr = implode(', ', $failureSignals);

        $sanitizedLog = Sanitize::redactString(
            is_string($sessionLog) ? substr($sessionLog, -self::MAX_LOG_CHARS) : ''
        );

        $eventsTable = $this->formatRecentEvents($recentEvents);

        $reportId = substr(hash('sha256', $nodeId . '|' . time() . '|' . $errorSig), 0, 12);

        $lines = [
            '## Environment',
            '- **Evolver Version:** ' . ($fp['evolver_version'] ?? 'unknown'),
            '- **PHP:** ' . PHP_VERSION,
            '- **Platform:** ' . PHP_OS_FAMILY . ' ' . php_uname('m'),
            '- **Container:** ' . ($fp['container'] ?? false ? 'yes' : 'no'),
            '',
            '## Failure Summary',
            '- **Consecutive failures:** ' . ($streakCount ?: 'N/A'),
            '- **Failure signals:** ' . ($failureSignalsStr ?: 'none'),
            '',
            '## Error Signature',
            '```',
            Sanitize::redactString($errorSig),
            '```',
            '',
            '## Recent Evolution Events (sanitized)',
            $eventsTable,
            '',
            '## Session Log Excerpt (sanitized)',
            '```',
            $sanitizedLog ?: '_No session log available._',
            '```',
            '',
            '---',
            '_This issue was automatically created by evolver.php v' . ($fp['evolver_version'] ?? 'unknown') . '._',
            '_Device: ' . $nodeId . ' | Report ID: ' . $reportId . '_',
        ];

        return implode("\n", $lines);
    }

    /**
     * Maybe report an issue if conditions are met.
     *
     * @param array $opts{
     *   signals?: string[],
     *   recentEvents?: array,
     *   sessionLog?: string,
     *   envFingerprint?: array
     * }
     * @return array{reported: bool, issueNumber?: int, issueUrl?: string, error?: string}
     */
    public function maybeReportIssue(array $opts): array
    {
        $config = $this->getConfig();
        if ($config === null) {
            return ['reported' => false, 'error' => 'disabled'];
        }

        $signals = $opts['signals'] ?? [];

        if (!$this->shouldReport($signals, $config)) {
            return ['reported' => false, 'error' => 'conditions_not_met'];
        }

        $token = $this->getGithubToken();
        if ($token === '') {
            return ['reported' => false, 'error' => 'no_github_token'];
        }

        $errorSig = $this->extractErrorSignature($signals);
        $titleSig = substr($errorSig, 0, 80);
        $title = '[Auto] Recurring failure: ' . $titleSig;
        $body = $this->buildIssueBody($opts);

        try {
            $result = $this->createGithubIssue($config['repo'], $title, $body, $token);

            $state = $this->readState();
            $errorKey = $this->computeErrorKey($signals);
            $recentKeys = $state['recentIssueKeys'] ?? [];
            $recentKeys[] = $errorKey;
            if (count($recentKeys) > 20) {
                $recentKeys = array_slice($recentKeys, -20);
            }

            $this->writeState([
                'lastReportedAt' => date('c'),
                'recentIssueKeys' => $recentKeys,
                'lastIssueUrl' => $result['url'],
                'lastIssueNumber' => $result['number'],
            ]);

            return [
                'reported' => true,
                'issueNumber' => $result['number'],
                'issueUrl' => $result['url'],
            ];
        } catch (\Throwable $e) {
            return ['reported' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a GitHub issue.
     *
     * @return array{number: int, url: string}
     */
    private function createGithubIssue(string $repo, string $title, string $body, string $token): array
    {
        $url = 'https://api.github.com/repos/' . $repo . '/issues';

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize curl");
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
        ], JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'Content-Type: application/json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new \RuntimeException("HTTP error: " . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("GitHub API {$httpCode}: " . substr((string)$response, 0, 200));
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || !isset($data['number'])) {
            throw new \RuntimeException("Invalid GitHub API response");
        }

        return [
            'number' => (int)$data['number'],
            'url' => $data['html_url'] ?? '',
        ];
    }

    /**
     * Get GitHub token from environment.
     */
    public function getGithubToken(): string
    {
        return getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN') ?: getenv('GITHUB_PAT') ?: '';
    }

    /**
     * Get state file path.
     */
    public static function getStatePath(): string
    {
        return Paths::getEvolutionDir() . '/' . self::STATE_FILE_NAME;
    }

    /**
     * Read state from file.
     */
    private function readState(): array
    {
        $path = self::getStatePath();
        if (!file_exists($path)) {
            return ['lastReportedAt' => null, 'recentIssueKeys' => []];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['lastReportedAt' => null, 'recentIssueKeys' => []];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['lastReportedAt' => null, 'recentIssueKeys' => []];
    }

    /**
     * Write state to file.
     */
    private function writeState(array $state): void
    {
        $path = self::getStatePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * Truncate node ID for display.
     */
    private function truncateNodeId(string $nodeId): string
    {
        if ($nodeId === '' || strlen($nodeId) <= 10) {
            return $nodeId ?: 'unknown';
        }
        return substr($nodeId, 0, 10) . '...';
    }

    /**
     * Compute error key from signals.
     */
    public function computeErrorKey(array $signals): string
    {
        $relevant = array_filter($signals, function ($s) {
            return str_starts_with($s, 'recurring_errsig')
                || str_starts_with($s, 'ban_gene:')
                || $s === 'recurring_error'
                || $s === 'failure_loop_detected'
                || $s === 'high_failure_ratio';
        });
        sort($relevant);
        $joined = implode('|', $relevant) ?: 'unknown';
        return substr(hash('sha256', $joined), 0, 16);
    }

    /**
     * Extract error signature from signals.
     */
    public function extractErrorSignature(array $signals): string
    {
        foreach ($signals as $s) {
            if (str_starts_with($s, 'recurring_errsig')) {
                $result = preg_replace('/^recurring_errsig\(\d+x\):/', '', $s);
                return trim(substr($result, 0, 200));
            }
        }

        foreach ($signals as $s) {
            if (str_starts_with($s, 'ban_gene:')) {
                return 'Repeated failures with gene: ' . str_replace('ban_gene:', '', $s);
            }
        }

        return 'Persistent evolution failure';
    }

    /**
     * Extract streak count from signals.
     */
    public function extractStreakCount(array $signals): int
    {
        foreach ($signals as $s) {
            if (str_starts_with($s, 'consecutive_failure_streak_')) {
                $n = (int)str_replace('consecutive_failure_streak_', '', $s);
                if ($n > 0) {
                    return $n;
                }
            }
        }
        return 0;
    }

    /**
     * Format recent events as markdown table.
     */
    private function formatRecentEvents(array $events): string
    {
        if (empty($events)) {
            return '_No recent events available._';
        }

        $failed = array_filter($events, function ($e) {
            return isset($e['outcome']['status']) && $e['outcome']['status'] === 'failed';
        });

        if (empty($failed)) {
            return '_No failed events in recent history._';
        }

        $rows = array_slice(array_values($failed), -self::MAX_EVENTS);
        $lines = [
            '| # | Intent | Gene | Outcome | Reason |',
            '|---|--------|------|---------|--------|',
        ];

        foreach ($rows as $idx => $e) {
            $intent = $e['intent'] ?? '-';
            $gene = (isset($e['genes_used'][0])) ? $e['genes_used'][0] : '-';
            $outcome = $e['outcome']['status'] ?? '-';
            $reason = $e['outcome']['reason'] ?? '';
            if (strlen($reason) > 80) {
                $reason = substr($reason, 0, 80) . '...';
            }
            $reason = Sanitize::redactString($reason);

            $lines[] = '| ' . ($idx + 1) . ' | ' . $intent . ' | ' . $gene . ' | ' . $outcome . ' | ' . $reason . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * Capture environment fingerprint.
     */
    private function captureEnvFingerprint(): array
    {
        return [
            'evolver_version' => '1.0.0',
            'php_version' => PHP_VERSION,
            'platform' => PHP_OS_FAMILY,
            'arch' => php_uname('m'),
            'container' => getenv('KUBERNETES_SERVICE_HOST') !== false
                || getenv('DOCKER_CONTAINER') !== false,
        ];
    }
}
