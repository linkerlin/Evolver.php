<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Narrative Memory System.
 *
 * Records evolution decisions and outcomes in a human-readable markdown format.
 * Provides context for the reflection phase and maintains a rolling history.
 *
 * Ported from evolver/src/gep/narrativeMemory.js
 */
final class NarrativeMemory
{
    private const MAX_ENTRIES = 30;
    private const MAX_SIZE = 12000;
    private const SUMMARY_ENTRIES = 8;
    private const DEFAULT_SUMMARY_CHARS = 4000;

    /**
     * Record a narrative entry for an evolution event.
     *
     * @param array{gene?: array|null, signals?: array, mutation?: array|null, outcome?: array|null, blast?: array|null, capsule?: array|null} $params
     */
    public static function record(array $params): void
    {
        $narrativePath = Paths::getNarrativePath();
        Paths::ensureDir(dirname($narrativePath));

        $entry = self::buildEntry($params);

        $existing = self::loadExistingContent($narrativePath);
        if ($existing === '') {
            $existing = "# Evolution Narrative\n\nA chronological record of evolution decisions and outcomes.\n\n";
        }

        $combined = $existing . $entry;
        $trimmed = self::trim($combined);

        self::atomicWrite($narrativePath, $trimmed);
    }

    /**
     * Load recent narrative summary for reflection context.
     *
     * @param int $maxChars Maximum characters to return (default 4000)
     */
    public static function loadSummary(int $maxChars = self::DEFAULT_SUMMARY_CHARS): string
    {
        $limit = $maxChars > 0 ? $maxChars : self::DEFAULT_SUMMARY_CHARS;
        $narrativePath = Paths::getNarrativePath();

        if (!file_exists($narrativePath)) {
            return '';
        }

        try {
            $content = file_get_contents($narrativePath);
            if ($content === false || trim($content) === '') {
                return '';
            }
        } catch (\Throwable) {
            return '';
        }

        $headerEnd = strpos($content, '###');
        if ($headerEnd === false) {
            return '';
        }

        $entriesContent = substr($content, $headerEnd);
        $entries = preg_split('/(?=^### \[)/m', $entriesContent);

        if ($entries === false) {
            return '';
        }

        $recent = array_slice($entries, -self::SUMMARY_ENTRIES);
        $summary = implode('', $recent);

        if (strlen($summary) > $limit) {
            $summary = substr($summary, -$limit);
            $firstEntry = strpos($summary, '### [');
            if ($firstEntry !== false && $firstEntry > 0) {
                $summary = substr($summary, $firstEntry);
            }
        }

        return trim($summary);
    }

    /**
     * Trim narrative to stay within size/entry limits.
     */
    public static function trim(string $content): string
    {
        if (strlen($content) <= self::MAX_SIZE) {
            return $content;
        }

        $headerEnd = strpos($content, '###');
        if ($headerEnd === false) {
            return substr($content, -self::MAX_SIZE);
        }

        $header = substr($content, 0, $headerEnd);
        $entriesContent = substr($content, $headerEnd);
        $entries = preg_split('/(?=^### \[)/m', $entriesContent);

        if ($entries === false) {
            return substr($content, -self::MAX_SIZE);
        }

        while (count($entries) > self::MAX_ENTRIES) {
            array_shift($entries);
        }

        $result = $header . implode('', $entries);

        if (strlen($result) > self::MAX_SIZE) {
            $keep = max(1, count($entries) - 5);
            $result = $header . implode('', array_slice($entries, -$keep));
        }

        return $result;
    }

    /**
     * Get the narrative file path.
     */
    public static function getPath(): string
    {
        return Paths::getNarrativePath();
    }

    /**
     * Build a narrative entry from evolution parameters.
     *
     * @param array{gene?: array|null, signals?: array, mutation?: array|null, outcome?: array|null, blast?: array|null, capsule?: array|null} $params
     */
    private static function buildEntry(array $params): string
    {
        $gene = $params['gene'] ?? null;
        $signals = $params['signals'] ?? [];
        $mutation = $params['mutation'] ?? null;
        $outcome = $params['outcome'] ?? null;
        $blast = $params['blast'] ?? null;
        $capsule = $params['capsule'] ?? null;

        $ts = date('Y-m-d H:i:s');
        $geneId = ($gene && isset($gene['id'])) ? $gene['id'] : '(auto)';
        $category = ($mutation['category'] ?? null) ?: ($gene['category'] ?? null) ?: 'unknown';
        $status = $outcome['status'] ?? 'unknown';
        $score = isset($outcome['score']) && is_numeric($outcome['score'])
            ? number_format((float)$outcome['score'], 2)
            : '?';

        $signalsSummary = is_array($signals) && count($signals) > 0
            ? implode(', ', array_slice($signals, 0, 4))
            : '(none)';

        $filesChanged = $blast['files'] ?? 0;
        $linesChanged = $blast['lines'] ?? 0;

        $rationale = '';
        if (isset($mutation['rationale']) && is_string($mutation['rationale'])) {
            $rationale = mb_substr($mutation['rationale'], 0, 200);
        }

        $strategy = '';
        if (isset($gene['strategy']) && is_array($gene['strategy'])) {
            $strategySteps = array_slice($gene['strategy'], 0, 3);
            $lines = [];
            foreach ($strategySteps as $i => $step) {
                $lines[] = '  ' . ($i + 1) . '. ' . $step;
            }
            $strategy = implode("\n", $lines);
        }

        $capsuleSummary = '';
        if (isset($capsule['summary']) && is_string($capsule['summary'])) {
            $capsuleSummary = mb_substr($capsule['summary'], 0, 200);
        }

        $lines = [
            "### [{$ts}] " . strtoupper($category) . " - {$status}",
            "- Gene: {$geneId} | Score: {$score} | Scope: {$filesChanged} files, {$linesChanged} lines",
            "- Signals: [{$signalsSummary}]",
        ];

        if ($rationale !== '') {
            $lines[] = "- Why: {$rationale}";
        }

        if ($strategy !== '') {
            $lines[] = "- Strategy:\n{$strategy}";
        }

        if ($capsuleSummary !== '') {
            $lines[] = "- Result: {$capsuleSummary}";
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Load existing narrative content.
     */
    private static function loadExistingContent(string $path): string
    {
        if (!file_exists($path)) {
            return '';
        }

        try {
            $content = file_get_contents($path);
            return $content !== false ? $content : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Write content atomically to prevent corruption.
     */
    private static function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path . '.tmp';
        try {
            $result = file_put_contents($tmp, $content);
            if ($result === false) {
                return false;
            }
            return rename($tmp, $path);
        } catch (\Throwable) {
            @unlink($tmp);
            return false;
        }
    }
}
