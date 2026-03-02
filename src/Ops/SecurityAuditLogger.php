<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Security Audit Logger - records all evolution operations for security review.
 *
 * Tracks:
 * - Modification requests and their approval/rejection
 * - Safety violations detected
 * - Gene/Capsule operations
 * - System events
 */
final class SecurityAuditLogger
{
    private string $auditFile;
    private bool $enabled = true;

    public function __construct(?string $dataDir = null, bool $enabled = true)
    {
        $dataDir = $dataDir ?? dirname(__DIR__, 2) . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->auditFile = $dataDir . '/security-audit.log';
        $this->enabled = $enabled;
    }

    /**
     * Log a modification request.
     */
    public function logModificationRequest(array $request): void
    {
        $this->log('modification_request', [
            'intent' => $request['intent'] ?? 'unknown',
            'summary' => $request['summary'] ?? '',
            'files' => $request['files'] ?? [],
            'blast_radius' => $request['blast_radius'] ?? [],
            'gene' => $request['gene']['id'] ?? null,
            'approved' => $request['approved'] ?? null,
            'review_mode' => $request['review_mode'] ?? false,
        ]);
    }

    /**
     * Log a safety violation.
     */
    public function logSafetyViolation(string $type, array $details): void
    {
        $this->log('safety_violation', [
            'type' => $type,
            'details' => $details,
            'violations' => $details['violations'] ?? [],
        ]);
    }

    /**
     * Log a gene operation.
     */
    public function logGeneOperation(string $operation, array $gene): void
    {
        $this->log('gene_operation', [
            'operation' => $operation,
            'gene_id' => $gene['id'] ?? null,
            'gene_category' => $gene['category'] ?? null,
            'asset_id' => $gene['asset_id'] ?? null,
        ]);
    }

    /**
     * Log a capsule operation.
     */
    public function logCapsuleOperation(string $operation, array $capsule): void
    {
        $this->log('capsule_operation', [
            'operation' => $operation,
            'capsule_id' => $capsule['id'] ?? null,
            'gene_id' => $capsule['gene'] ?? null,
            'outcome' => $capsule['outcome']['status'] ?? null,
            'confidence' => $capsule['confidence'] ?? null,
        ]);
    }

    /**
     * Log a system event.
     */
    public function logSystemEvent(string $event, array $data = []): void
    {
        $this->log('system_event', [
            'event' => $event,
            'data' => $data,
        ]);
    }

    /**
     * Log an access attempt.
     */
    public function logAccessAttempt(string $resource, bool $allowed, ?string $reason = null): void
    {
        $this->log('access_attempt', [
            'resource' => $resource,
            'allowed' => $allowed,
            'reason' => $reason,
        ]);
    }

    /**
     * Log command execution.
     */
    public function logCommandExecution(string $command, bool $allowed, ?string $reason = null): void
    {
        $this->log('command_execution', [
            'command' => $command,
            'allowed' => $allowed,
            'reason' => $reason,
        ]);
    }

    /**
     * Log a protection bypass attempt.
     */
    public function logProtectionBypass(string $resource, bool $allowed): void
    {
        $this->log('protection_bypass', [
            'resource' => $resource,
            'allowed' => $allowed,
        ]);
    }

    /**
     * 获取recent audit logs.
     */
    public function getRecentLogs(int $limit = 100): array
    {
        if (!file_exists($this->auditFile)) {
            return [];
        }

        $lines = file($this->auditFile);
        $logs = [];

        foreach (array_slice($lines, -$limit) as $line) {
            $entry = json_decode(trim($line), true);
            if ($entry) {
                $logs[] = $entry;
            }
        }

        return $logs;
    }

    /**
     * Query audit logs by type.
     */
    public function queryByType(string $type, int $limit = 100): array
    {
        $all = $this->getRecentLogs(1000);
        $filtered = array_filter($all, fn($log) => ($log['type'] ?? '') === $type);
        return array_slice(array_values($filtered), -$limit);
    }

    /**
     * Query audit logs by time range.
     */
    public function queryByTimeRange(int $startTimestamp, int $endTimestamp): array
    {
        $all = $this->getRecentLogs(1000);
        $filtered = array_filter($all, function($log) use ($startTimestamp, $endTimestamp) {
            $ts = $log['timestamp'] ?? 0;
            return $ts >= $startTimestamp && $ts <= $endTimestamp;
        });
        return array_values($filtered);
    }

    /**
     * 获取audit statistics.
     */
    public function getStats(): array
    {
        $logs = $this->getRecentLogs(1000);

        $stats = [
            'total_entries' => count($logs),
            'by_type' => [],
            'by_day' => [],
        ];

        foreach ($logs as $log) {
            $type = $log['type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            $day = date('Y-m-d', strtotime($log['timestamp'] ?? 'now'));
            $stats['by_day'][$day] = ($stats['by_day'][$day] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Export audit logs to file.
     */
    public function export(string $outputFile, ?int $startTimestamp = null, ?int $endTimestamp = null): int
    {
        $logs = $this->getRecentLogs(10000);

        if ($startTimestamp !== null || $endTimestamp !== null) {
            $logs = array_filter($logs, function($log) use ($startTimestamp, $endTimestamp) {
                $ts = strtotime($log['timestamp'] ?? 'now');
                if ($startTimestamp !== null && $ts < $startTimestamp) return false;
                if ($endTimestamp !== null && $ts > $endTimestamp) return false;
                return true;
            });
        }

        $content = '';
        foreach ($logs as $log) {
            $content .= json_encode($log, JSON_UNESCAPED_UNICODE) . "\n";
        }

        file_put_contents($outputFile, $content);
        return count($logs);
    }

    /**
     * Clear old audit logs.
     */
    public function clear(int $keepDays = 90): int
    {
        if (!file_exists($this->auditFile)) {
            return 0;
        }

        $cutoff = time() - ($keepDays * 86400);
        $lines = file($this->auditFile);
        $kept = [];

        foreach ($lines as $line) {
            $entry = json_decode(trim($line), true);
            if ($entry) {
                $ts = strtotime($entry['timestamp'] ?? 'now');
                if ($ts >= $cutoff) {
                    $kept[] = $line;
                }
            }
        }

        $removed = count($lines) - count($kept);
        file_put_contents($this->auditFile, implode('', $kept));

        return $removed;
    }

    /**
     * Enable/disable audit logging.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * 检查 audit logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Internal log method.
     */
    private function log(string $type, array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'type' => $type,
            'data' => $data,
            'pid' => getmypid(),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->auditFile, $json . "\n", FILE_APPEND | LOCK_EX);
    }
}
