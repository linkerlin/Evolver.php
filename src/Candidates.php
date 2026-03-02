<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Capability candidate extraction from transcripts and signals.
 * Identifies potential new capabilities based on recurring patterns.
 *
 * Ported from evolver/src/gep/candidates.js
 */
final class Candidates
{
    /**
     * Deterministic lightweight hash (not cryptographic).
     */
    private static function stableHash(string $input): string
    {
        $s = $input;
        $h = 2166136261;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $h ^= ord($s[$i]);
            // FNV-1a multiply
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return str_pad(dechex($h), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Clip text to max chars with truncation marker.
     */
    private static function clip(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, max(0, $maxChars - 20)) . ' ...[TRUNCATED]';
    }

    /**
     * Split text into lines, trimmed and non-empty.
     */
    private static function toLines(string $text): array
    {
        $lines = explode("\n", $text);
        return array_filter(array_map('rtrim', $lines));
    }

    /**
     * Extract tool calls from transcript.
     * Looks for patterns like [TOOL: tool_name]
     */
    private static function extractToolCalls(string $transcript): array
    {
        $lines = self::toLines($transcript);
        $calls = [];
        foreach ($lines as $line) {
            if (preg_match('/\[TOOL:\s*([^\]]+)\]/i', $line, $m)) {
                $calls[] = trim($m[1]);
            }
        }
        return $calls;
    }

    /**
     * Count frequency of items.
     * @param array<string> $items
     * @return array<string, int>
     */
    private static function countFreq(array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item] = ($map[$item] ?? 0) + 1;
        }
        return $map;
    }

    /**
     * Build a five-questions shape for capability candidates.
     */
    private static function buildFiveQuestionsShape(string $title, array $signals, string $evidence): array
    {
        $input = 'Recent session transcript + memory snippets + user instructions';
        $output = 'A safe, auditable evolution patch guided by GEP assets';
        $invariants = 'Protocol order, small reversible patches, validation, append-only events';
        $params = !empty($signals) ? 'Signals: ' . implode(', ', $signals) : 'Signals: (none)';
        $failurePoints = 'Missing signals, over-broad changes, skipped validation, missing knowledge solidification';

        return [
            'title' => substr($title, 0, 120),
            'input' => $input,
            'output' => $output,
            'invariants' => $invariants,
            'params' => $params,
            'failure_points' => $failurePoints,
            'evidence' => self::clip($evidence, 240),
        ];
    }

    /**
     * Extract capability candidates from transcript and signals.
     *
     * @param array{recentSessionTranscript?: string, signals?: array<string>} $params
     * @return array<array{type: string, id: string, title: string, source: string, created_at: string, signals: array, shape: array}>
     */
    public static function extractCapabilityCandidates(array $params): array
    {
        $transcript = $params['recentSessionTranscript'] ?? '';
        $signals = $params['signals'] ?? [];
        $candidates = [];

        // Extract tool calls and count frequency
        $toolCalls = self::extractToolCalls($transcript);
        $freq = self::countFreq($toolCalls);

        // Tool usage candidates
        foreach ($freq as $tool => $count) {
            if ($count < 2) {
                continue;
            }
            $title = "Repeated tool usage: {$tool}";
            $evidence = "Observed {$count} occurrences of tool call marker for {$tool}.";
            $shape = self::buildFiveQuestionsShape($title, $signals, $evidence);

            $candidates[] = [
                'type' => 'CapabilityCandidate',
                'id' => 'cand_' . self::stableHash($title),
                'title' => $title,
                'source' => 'transcript',
                'created_at' => date('c'),
                'signals' => $signals,
                'shape' => $shape,
            ];
        }

        // Signal-based candidates
        $signalCandidates = [
            // Defensive signals
            ['signal' => 'log_error', 'title' => 'Repair recurring runtime errors'],
            ['signal' => 'protocol_drift', 'title' => 'Prevent protocol drift and enforce auditable outputs'],
            ['signal' => 'windows_shell_incompatible', 'title' => 'Avoid platform-specific shell assumptions (Windows compatibility)'],
            ['signal' => 'session_logs_missing', 'title' => 'Harden session log detection and fallback behavior'],
            // Opportunity signals (innovation)
            ['signal' => 'user_feature_request', 'title' => 'Implement user-requested feature'],
            ['signal' => 'user_improvement_suggestion', 'title' => 'Apply user improvement suggestion'],
            ['signal' => 'perf_bottleneck', 'title' => 'Resolve performance bottleneck'],
            ['signal' => 'capability_gap', 'title' => 'Fill capability gap'],
            ['signal' => 'stable_success_plateau', 'title' => 'Explore new strategies during stability plateau'],
            ['signal' => 'external_opportunity', 'title' => 'Evaluate external A2A asset for local adoption'],
        ];

        foreach ($signalCandidates as $sc) {
            $signalKey = $sc['signal'];
            $found = false;
            foreach ($signals as $s) {
                if ($s === $signalKey || str_starts_with($s, $signalKey . ':')) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                continue;
            }

            $evidence = "Signal present: {$signalKey}";
            $shape = self::buildFiveQuestionsShape($sc['title'], $signals, $evidence);

            $candidates[] = [
                'type' => 'CapabilityCandidate',
                'id' => 'cand_' . self::stableHash($signalKey),
                'title' => $sc['title'],
                'source' => 'signals',
                'created_at' => date('c'),
                'signals' => $signals,
                'shape' => $shape,
            ];
        }

        // Deduplicate by id
        $seen = [];
        return array_filter($candidates, function ($c) use (&$seen) {
            if (!isset($c['id'])) {
                return false;
            }
            if (isset($seen[$c['id']])) {
                return false;
            }
            $seen[$c['id']] = true;
            return true;
        });
    }

    /**
     * Render candidates preview for prompt inclusion.
     */
    public static function renderCandidatesPreview(array $candidates, int $maxChars = 1400): string
    {
        $lines = [];
        foreach ($candidates as $c) {
            $s = $c['shape'] ?? [];
            $lines[] = "- {$c['id']}: {$c['title']}";
            $lines[] = "  - input: " . ($s['input'] ?? '');
            $lines[] = "  - output: " . ($s['output'] ?? '');
            $lines[] = "  - invariants: " . ($s['invariants'] ?? '');
            $lines[] = "  - params: " . ($s['params'] ?? '');
            $lines[] = "  - failure_points: " . ($s['failure_points'] ?? '');
            if (!empty($s['evidence'])) {
                $lines[] = "  - evidence: " . $s['evidence'];
            }
        }
        return self::clip(implode("\n", $lines), $maxChars);
    }
}
