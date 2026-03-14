<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Reflection Phase System.
 *
 * Triggers strategic reflection every N cycles to analyze evolution patterns
 * and generate strategic adjustment recommendations.
 *
 * Ported from evolver/src/gep/reflection.js
 */
final class Reflection
{
    public const INTERVAL_CYCLES = 5;
    public const COOLDOWN_MS = 1800000; // 30 minutes
    public const MAX_RECENT_EVENTS = 10;
    public const MAX_SIGNALS = 20;
    public const MAX_NARRATIVE_CHARS = 3000;

    /**
     * Determine if a reflection should be triggered.
     *
     * @param int $cycleCount Current cycle count
     * @return bool Whether reflection should run
     */
    public static function shouldReflect(int $cycleCount): bool
    {
        if ($cycleCount < self::INTERVAL_CYCLES) {
            return false;
        }

        if ($cycleCount % self::INTERVAL_CYCLES !== 0) {
            return false;
        }

        $logPath = Paths::getReflectionLogPath();
        if (file_exists($logPath)) {
            $mtime = filemtime($logPath);
            if ($mtime !== false) {
                $elapsedMs = (time() - $mtime) * 1000;
                if ($elapsedMs < self::COOLDOWN_MS) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Build the reflection context prompt.
     *
     * @param array{recentEvents?: array, signals?: array, memoryAdvice?: array|null, narrative?: string|null} $params
     */
    public static function buildContext(array $params): string
    {
        $parts = [
            'You are performing a strategic reflection on recent evolution cycles.',
            'Analyze the patterns below and provide concise strategic guidance.',
            '',
        ];

        $recentEvents = $params['recentEvents'] ?? [];
        if (is_array($recentEvents) && count($recentEvents) > 0) {
            $last10 = array_slice($recentEvents, -self::MAX_RECENT_EVENTS);

            $successCount = 0;
            $failCount = 0;
            $intents = [];
            $genes = [];

            foreach ($last10 as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $status = $event['outcome']['status'] ?? null;
                if ($status === 'success') {
                    $successCount++;
                } elseif ($status === 'failed') {
                    $failCount++;
                }

                $intent = $event['intent'] ?? 'unknown';
                $intents[$intent] = ($intents[$intent] ?? 0) + 1;

                $genesUsed = $event['genes_used'] ?? [];
                $gene = is_array($genesUsed) && isset($genesUsed[0]) ? $genesUsed[0] : 'unknown';
                $genes[$gene] = ($genes[$gene] ?? 0) + 1;
            }

            $parts[] = '## Recent Cycle Statistics (last 10)';
            $parts[] = "- Success: {$successCount}, Failed: {$failCount}";
            $parts[] = '- Intent distribution: ' . json_encode($intents);
            $parts[] = '- Gene usage: ' . json_encode($genes);
            $parts[] = '';
        }

        $signals = $params['signals'] ?? [];
        if (is_array($signals) && count($signals) > 0) {
            $parts[] = '## Current Signals';
            $parts[] = implode(', ', array_slice($signals, 0, self::MAX_SIGNALS));
            $parts[] = '';
        }

        $memoryAdvice = $params['memoryAdvice'] ?? null;
        if (is_array($memoryAdvice)) {
            $parts[] = '## Memory Graph Advice';
            if (isset($memoryAdvice['preferredGeneId'])) {
                $parts[] = "- Preferred gene: {$memoryAdvice['preferredGeneId']}";
            }
            if (isset($memoryAdvice['bannedGeneIds']) && is_array($memoryAdvice['bannedGeneIds'])) {
                $banned = implode(', ', $memoryAdvice['bannedGeneIds']);
                if ($banned !== '') {
                    $parts[] = "- Banned genes: {$banned}";
                }
            }
            if (isset($memoryAdvice['explanation'])) {
                $parts[] = "- Explanation: {$memoryAdvice['explanation']}";
            }
            $parts[] = '';
        }

        $narrative = $params['narrative'] ?? null;
        if ($narrative !== null) {
            $parts[] = '## Recent Evolution Narrative';
            $parts[] = mb_substr((string)$narrative, 0, self::MAX_NARRATIVE_CHARS);
            $parts[] = '';
        }

        $parts[] = '## Questions to Answer';
        $parts[] = '1. Are there persistent signals being ignored?';
        $parts[] = '2. Is the gene selection strategy optimal, or are we stuck in a local maximum?';
        $parts[] = '3. Should the balance between repair/optimize/innovate shift?';
        $parts[] = '4. Are there capability gaps that no current gene addresses?';
        $parts[] = '5. What single strategic adjustment would have the highest impact?';
        $parts[] = '';
        $parts[] = 'Respond with a JSON object: { "insights": [...], "strategy_adjustment": "...", "priority_signals": [...] }';

        return implode("\n", $parts);
    }

    /**
     * Record a reflection result to the log.
     *
     * @param array{insights?: array, strategy_adjustment?: string, priority_signals?: array} $reflection
     */
    public static function record(array $reflection): void
    {
        $logPath = Paths::getReflectionLogPath();
        Paths::ensureDir(dirname($logPath));

        $entry = json_encode([
            'ts' => date('c'),
            'type' => 'reflection',
            ...$reflection,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($entry !== false) {
            file_put_contents($logPath, $entry . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Load recent reflection entries.
     *
     * @param int $count Number of reflections to load (default 3)
     * @return array<array{ts: string, type: string, insights?: array, strategy_adjustment?: string, priority_signals?: array}>
     */
    public static function loadRecent(int $count = 3): array
    {
        $n = $count > 0 ? $count : 3;
        $logPath = Paths::getReflectionLogPath();

        if (!file_exists($logPath)) {
            return [];
        }

        try {
            $content = file_get_contents($logPath);
            if ($content === false) {
                return [];
            }

            $lines = array_filter(explode("\n", trim($content)));
            $recent = array_slice($lines, -$n);

            $result = [];
            foreach ($recent as $line) {
                $parsed = json_decode($line, true);
                if (is_array($parsed)) {
                    $result[] = $parsed;
                }
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get the reflection log path.
     */
    public static function getPath(): string
    {
        return Paths::getReflectionLogPath();
    }

    /**
     * Parse LLM reflection response.
     *
     * @param string $response Raw LLM response
     * @return array{insights: array, strategy_adjustment: string, priority_signals: array}|null
     */
    public static function parseResponse(string $response): ?array
    {
        // Try to extract JSON from the response
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (is_array($parsed)) {
                return [
                    'insights' => $parsed['insights'] ?? [],
                    'strategy_adjustment' => $parsed['strategy_adjustment'] ?? '',
                    'priority_signals' => $parsed['priority_signals'] ?? [],
                ];
            }
        }

        return null;
    }
}
