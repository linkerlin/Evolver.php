<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Self-Correction Analyzer - Analyzes past failures to suggest better future mutations.
 * Pattern: Meta-learning
 *
 * Ported from evolver/src/gep/analyzer.js
 */
final class Analyzer
{
    /**
     * Analyze failures from MEMORY.md.
     */
    public static function analyzeFailures(?string $memoryPath = null): array
    {
        $memoryPath ??= getcwd() . '/MEMORY.md';
        if (!file_exists($memoryPath)) {
            return ['status' => 'skipped', 'reason' => 'no_memory'];
        }

        $content = file_get_contents($memoryPath);
        if ($content === false) {
            return ['status' => 'skipped', 'reason' => 'read_error'];
        }

        // Match failure pattern: | **F\d+** | Fix | (summary) | **(detail)** ((outcome)) |
        $pattern = '/\|\s*\*\*F\d+\*\*\s*\|\s*Fix\s*\|\s*(.*?)\s*\|\s*\*\*(.*?)\*\*\s*\((.*?)\)\s*\|/s';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $failures = [];
        foreach ($matches as $match) {
            $failures[] = [
                'summary' => trim($match[1]),
                'detail' => trim($match[2]),
                'outcome' => trim($match[3]),
            ];
        }

        return [
            'status' => 'success',
            'count' => count($failures),
            'failures' => array_slice($failures, 0, 3), // Return top 3 for prompt context
        ];
    }

    /**
     * Analyze success patterns from MEMORY.md.
     */
    public static function analyzeSuccesses(?string $memoryPath = null): array
    {
        $memoryPath ??= getcwd() . '/MEMORY.md';
        if (!file_exists($memoryPath)) {
            return ['status' => 'skipped', 'reason' => 'no_memory'];
        }

        $content = file_get_contents($memoryPath);
        if ($content === false) {
            return ['status' => 'skipped', 'reason' => 'read_error'];
        }

        // Match success pattern: | **S\d+** | Success | (summary) | **(detail)** ((outcome)) |
        $pattern = '/\|\s*\*\*S\d+\*\*\s*\|\s*Success\s*\|\s*(.*?)\s*\|\s*\*\*(.*?)\*\*\s*\((.*?)\)\s*\|/s';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $successes = [];
        foreach ($matches as $match) {
            $successes[] = [
                'summary' => trim($match[1]),
                'detail' => trim($match[2]),
                'outcome' => trim($match[3]),
            ];
        }

        return [
            'status' => 'success',
            'count' => count($successes),
            'successes' => array_slice($successes, 0, 3), // Return top 3 for prompt context
        ];
    }

    /**
     * Get combined analysis (failures + successes).
     */
    public static function getFullAnalysis(?string $memoryPath = null): array
    {
        return [
            'failures' => self::analyzeFailures($memoryPath),
            'successes' => self::analyzeSuccesses($memoryPath),
        ];
    }
}
