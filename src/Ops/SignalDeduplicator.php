<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Signal Deduplicator - prevents notification storms by suppressing duplicate signals.
 * 
 * PHP port inspired by EvoMap/evolver signal handling.
 */
final class SignalDeduplicator
{
    /** Default suppression window in seconds */
    private const DEFAULT_SUPPRESSION_WINDOW = 3600; // 1 hour

    private array $signalHistory = [];
    private int $suppressionWindow;
    private int $maxHistorySize;

    public function __construct(int $suppressionWindow = self::DEFAULT_SUPPRESSION_WINDOW, int $maxHistorySize = 1000)
    {
        $this->suppressionWindow = $suppressionWindow;
        $this->maxHistorySize = $maxHistorySize;
    }

    /**
     * Check if a signal should be suppressed.
     * 
     * @param string $signal The signal to check
     * @param array $context Additional context for fingerprinting
     * @return array{suppress: bool, reason?: string, count: int}
     */
    public function shouldSuppress(string $signal, array $context = []): array
    {
        $fingerprint = $this->computeFingerprint($signal, $context);
        $now = time();

        if (isset($this->signalHistory[$fingerprint])) {
            $entry = $this->signalHistory[$fingerprint];
            $age = $now - $entry['first_seen'];
            
            // Update entry count first
            $newCount = $entry['count'] + 1;
            $this->signalHistory[$fingerprint]['count'] = $newCount;
            $this->signalHistory[$fingerprint]['last_seen'] = $now;

            // Check if still in suppression window
            if ($age < $this->suppressionWindow) {
                return [
                    'suppress' => true,
                    'reason' => 'Signal suppressed (seen ' . $newCount . ' times in last ' . $this->formatDuration($age) . ')',
                    'count' => $newCount,
                    'first_seen' => $entry['first_seen'],
                ];
            }

            // Reset if window has passed
            $this->signalHistory[$fingerprint] = [
                'signal' => $signal,
                'first_seen' => $now,
                'last_seen' => $now,
                'count' => 1,
            ];

            return [
                'suppress' => false,
                'count' => 1,
            ];
        }

        // New signal
        $this->signalHistory[$fingerprint] = [
            'signal' => $signal,
            'first_seen' => $now,
            'last_seen' => $now,
            'count' => 1,
        ];

        $this->cleanupOldEntries();

        return [
            'suppress' => false,
            'count' => 1,
        ];
    }

    /**
     * Process a signal with deduplication.
     * 
     * @return array{action: string, notification?: array, summary?: array}
     */
    public function processSignal(string $signal, array $context = []): array
    {
        $check = $this->shouldSuppress($signal, $context);

        if ($check['suppress']) {
            return [
                'action' => 'suppressed',
                'summary' => $check,
            ];
        }

        // First occurrence - immediate notification
        if ($check['count'] === 1) {
            return [
                'action' => 'notify',
                'notification' => [
                    'signal' => $signal,
                    'timestamp' => time(),
                    'context' => $context,
                ],
            ];
        }

        // Recurring but outside suppression window
        return [
            'action' => 'notify_recurring',
            'notification' => [
                'signal' => $signal,
                'timestamp' => time(),
                'context' => $context,
                'recurrence_count' => $check['count'],
            ],
        ];
    }

    /**
     * Get a summary of suppressed signals for periodic reporting.
     */
    public function getSuppressionSummary(int $sinceSeconds = 3600): array
    {
        $now = time();
        $cutoff = $now - $sinceSeconds;
        $summary = [];

        foreach ($this->signalHistory as $fingerprint => $entry) {
            if ($entry['last_seen'] >= $cutoff) {
                $summary[] = [
                    'signal' => $entry['signal'],
                    'count' => $entry['count'],
                    'first_seen' => $entry['first_seen'],
                    'last_seen' => $entry['last_seen'],
                    'duration' => $entry['last_seen'] - $entry['first_seen'],
                ];
            }
        }

        // Sort by count descending
        usort($summary, fn($a, $b) => $b['count'] <=> $a['count']);

        return [
            'period_seconds' => $sinceSeconds,
            'unique_signals' => count($summary),
            'total_occurrences' => array_sum(array_column($summary, 'count')),
            'signals' => array_slice($summary, 0, 20), // Top 20
        ];
    }

    /**
     * Clear suppression history.
     */
    public function clearHistory(): void
    {
        $this->signalHistory = [];
    }

    /**
     * Get current history size.
     */
    public function getHistorySize(): int
    {
        return count($this->signalHistory);
    }

    /**
     * Compute a fingerprint for a signal.
     */
    private function computeFingerprint(string $signal, array $context): string
    {
        // Normalize signal by removing dynamic parts
        $normalized = $this->normalizeSignal($signal);
        
        // Include relevant context in fingerprint
        $contextParts = [];
        if (isset($context['gene_id'])) {
            $contextParts[] = $context['gene_id'];
        }
        if (isset($context['file'])) {
            $contextParts[] = $context['file'];
        }

        $fingerprintData = $normalized . '|' . implode('|', $contextParts);
        return hash('sha256', $fingerprintData);
    }

    /**
     * Normalize a signal for fingerprinting.
     */
    private function normalizeSignal(string $signal): string
    {
        // Remove timestamps
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', '', $signal);
        
        // Remove hex addresses
        $normalized = preg_replace('/0x[a-f0-9]+/i', '0xADDR', $normalized);
        
        // Remove line numbers
        $normalized = preg_replace('/:\d+/', ':LINE', $normalized);
        
        // Normalize whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return strtolower(trim($normalized));
    }

    /**
     * Clean up old entries to prevent memory growth.
     */
    private function cleanupOldEntries(): void
    {
        // If history is too large, remove oldest entries
        if (count($this->signalHistory) > $this->maxHistorySize) {
            // Sort by last_seen ascending
            uasort($this->signalHistory, fn($a, $b) => $a['last_seen'] <=> $b['last_seen']);
            
            // Keep only the most recent entries
            $this->signalHistory = array_slice($this->signalHistory, -$this->maxHistorySize, null, true);
        }

        // Remove entries older than 2x suppression window
        $now = time();
        $maxAge = $this->suppressionWindow * 2;
        
        $this->signalHistory = array_filter(
            $this->signalHistory,
            fn($entry) => ($now - $entry['last_seen']) < $maxAge
        );
    }

    /**
     * Format duration in human-readable form.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . 'h';
        }
        return floor($seconds / 86400) . 'd';
    }
}
