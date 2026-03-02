<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Operations Manager - unified interface for all运维 tools.
 * Provides a consistent CLI interface for system maintenance tasks.
 */
final class OpsManager
{
    private string $dataDir;
    private ?\Evolver\Database $db;

    public function __construct(?string $dataDir = null, ?\Evolver\Database $db = null)
    {
        $this->dataDir = $dataDir ?: dirname(__DIR__, 2) . '/data';
        $this->db = $db;
    }

    /**
     * Run an ops command.
     *
     * @param string $command The command to run
     * @param array $opts Command options
     * @return array{ok: bool, result?: array, error?: string}
     */
    public function run(string $command, array $opts = []): array
    {
        return match ($command) {
            'cleanup' => $this->cleanup($opts),
            'health' => $this->health($opts),
            'stats' => $this->stats($opts),
            'gc' => $this->garbageCollect($opts),
            'dedupe', 'deduplicate' => $this->deduplicate($opts),
            'help' => $this->help(),
            default => $this->unknownCommand($command),
        };
    }

    /**
     * List available commands.
     */
    public function listCommands(): array
    {
        return [
            'cleanup' => 'Clean old logs, temp files, and archive data',
            'health' => 'Check system health status',
            'stats' => 'Display storage and system statistics',
            'gc' => 'Garbage collect old capsules and events',
            'dedupe' => 'Show signal deduplication stats (alias: deduplicate)',
            'help' => 'Show this help message',
        ];
    }

    /**
     * Run cleanup operation.
     */
    private function cleanup(array $opts): array
    {
        $cleaner = new DiskCleaner($this->dataDir);

        $dryRun = $opts['dry-run'] ?? $opts['dry_run'] ?? false;
        if ($dryRun) {
            $stats = $cleaner->getStats();
            return [
                'ok' => true,
                'result' => [
                    'message' => 'Dry run - no files will be deleted',
                    'stats' => $stats,
                ],
            ];
        }

        $result = $cleaner->cleanup();
        return [
            'ok' => true,
            'result' => $result,
        ];
    }

    /**
     * Check system health.
     */
    private function health(array $opts): array
    {
        $results = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'disk_space' => null,
            'database' => null,
        ];

        // Check disk space
        $cleaner = new DiskCleaner($this->dataDir);
        $results['disk_space'] = $cleaner->checkDiskSpace();

        // Check database (optional, don't fail if unavailable)
        if ($this->db !== null) {
            try {
                $health = $this->db->getHealthStatus();
                $results['database'] = [
                    'ok' => $health['integrity_check'] === 'ok',
                    'size_bytes' => $health['size_bytes'],
                    'schema_version' => $health['schema_version'],
                ];
            } catch (\Throwable $e) {
                $results['database'] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        } else {
            $results['database'] = [
                'ok' => null,
                'message' => 'Database not available',
            ];
        }

        // Overall status
        $healthy = true;
        if ($results['disk_space'] && !($results['disk_space']['ok'] ?? true)) {
            $healthy = false;
        }
        if ($results['database'] && $results['database']['ok'] === false) {
            $healthy = false;
        }

        return [
            'ok' => $healthy,
            'result' => $results,
        ];
    }

    /**
     * Display statistics.
     */
    private function stats(array $opts): array
    {
        $results = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Disk stats
        $cleaner = new DiskCleaner($this->dataDir);
        $results['disk'] = $cleaner->getStats();

        // Database stats (optional)
        if ($this->db !== null) {
            try {
                $store = new \Evolver\GepAssetStore($this->db);
                $results['assets'] = $store->getStats();
            } catch (\Throwable $e) {
                $results['assets'] = ['error' => $e->getMessage()];
            }
        } else {
            $results['assets'] = ['message' => 'Database not available'];
        }

        return [
            'ok' => true,
            'result' => $results,
        ];
    }

    /**
     * Garbage collect old data.
     */
    private function garbageCollect(array $opts): array
    {
        if ($this->db === null) {
            return ['ok' => false, 'error' => 'Database not available'];
        }

        $dryRun = $opts['dry-run'] ?? $opts['dry_run'] ?? false;
        $maxAgeDays = (int)($opts['max_age_days'] ?? 90);
        $maxEvents = (int)($opts['max_events'] ?? 1000);

        $results = [
            'dry_run' => $dryRun,
            'capsules_removed' => 0,
            'events_removed' => 0,
        ];

        if (!$dryRun) {
            // This would require implementing actual GC in GepAssetStore
            $results['message'] = 'GC functionality requires database support';
        }

        return [
            'ok' => true,
            'result' => $results,
        ];
    }

    /**
     * Show deduplication stats.
     */
    private function deduplicate(array $opts): array
    {
        $sinceSeconds = (int)($opts['since'] ?? 3600);
        $deduplicator = new SignalDeduplicator();

        $summary = $deduplicator->getSuppressionSummary($sinceSeconds);

        return [
            'ok' => true,
            'result' => $summary,
        ];
    }

    /**
     * Show help.
     */
    private function help(): array
    {
        return [
            'ok' => true,
            'result' => [
                'commands' => $this->listCommands(),
                'usage' => 'php evolver.php --ops <command> [options]',
                'examples' => [
                    'php evolver.php --ops cleanup',
                    'php evolver.php --ops cleanup --dry-run',
                    'php evolver.php --ops health',
                    'php evolver.php --ops stats',
                    'php evolver.php --ops dedupe --since 3600',
                ],
            ],
        ];
    }

    /**
     * Handle unknown command.
     */
    private function unknownCommand(string $command): array
    {
        return [
            'ok' => false,
            'error' => "Unknown command: {$command}",
            'available_commands' => array_keys($this->listCommands()),
        ];
    }

    /**
     * Format output for CLI display.
     */
    public static function formatOutput(array $result): string
    {
        if (isset($result['error'])) {
            return "❌ Error: {$result['error']}";
        }

        if (isset($result['available_commands'])) {
            $lines = ['❌ Unknown command'];
            $lines[] = 'Available commands:';
            foreach ($result['available_commands'] as $cmd) {
                $lines[] = "  - {$cmd}";
            }
            return implode("\n", $lines);
        }

        $json = json_encode($result['result'] ?? $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $json ?: '';
    }
}
