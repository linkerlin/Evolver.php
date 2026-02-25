<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Extracts evolution signals from log/session/context content.
 * Enhanced with advanced repair loop detection.
 * PHP port of signals.js from EvoMap/evolver.
 */
final class SignalExtractor
{
    /** Opportunity signal names */
    public const OPPORTUNITY_SIGNALS = [
        'user_feature_request',
        'user_improvement_suggestion',
        'perf_bottleneck',
        'capability_gap',
        'stable_success_plateau',
        'external_opportunity',
        'recurring_error',
        'unsupported_input_type',
        'evolution_stagnation_detected',
        'repair_loop_detected',
        'force_innovation_after_repair_loop',
    ];

    /** Repair loop detection thresholds */
    private const REPAIR_LOOP_THRESHOLD = 3;
    private const FORCE_INNOVATION_THRESHOLD = 5;
    private const STAGNATION_THRESHOLD = 3;
    private const FAILURE_RATIO_THRESHOLD = 0.6;
    private const OSCILLATION_THRESHOLD = 2;

    /**
     * Extract signals from provided context/log content.
     *
     * @param array{
     *   context?: string,
     *   recentSessionTranscript?: string,
     *   todayLog?: string,
     *   memorySnippet?: string,
     *   userSnippet?: string,
     *   recentEvents?: array
     * } $input
     * @return string[]
     */
    public function extract(array $input): array
    {
        $signals = [];

        $corpus = implode("\n", array_filter([
            $input['context'] ?? '',
            $input['recentSessionTranscript'] ?? '',
            $input['todayLog'] ?? '',
            $input['memorySnippet'] ?? '',
            $input['userSnippet'] ?? '',
        ]));

        $lower = strtolower($corpus);

        // --- Defensive signals (errors, missing resources) ---
        $errorHit = (bool)preg_match(
            '/\[error\]|error:|exception:|"iserror"\s*:\s*true|"status"\s*:\s*"error"|"status"\s*:\s*"failed"/',
            $lower
        );

        if ($errorHit) {
            $signals[] = 'log_error';
        }

        // Error signature extraction
        $lines = array_filter(array_map('trim', explode("\n", $corpus)));
        $errLine = null;
        foreach ($lines as $line) {
            if (preg_match('/\b(typeerror|referenceerror|syntaxerror|fatal\s+error)\b\s*:|error\s*:|exception\s*:|\[error/i', $line)) {
                $errLine = $line;
                break;
            }
        }
        if ($errLine !== null) {
            $clipped = substr(preg_replace('/\s+/', ' ', $errLine), 0, 260);
            $signals[] = 'errsig:' . $clipped;
        }

        if (str_contains($lower, 'memory.md missing')) $signals[] = 'memory_missing';
        if (str_contains($lower, 'user.md missing')) $signals[] = 'user_missing';
        if (str_contains($lower, 'key missing')) $signals[] = 'integration_key_missing';
        if (str_contains($lower, 'no session logs found') || str_contains($lower, 'no jsonl files')) {
            $signals[] = 'session_logs_missing';
        }

        // Protocol drift
        if (str_contains($lower, 'prompt') && !str_contains($lower, 'evolutionevent')) {
            $signals[] = 'protocol_drift';
        }

        // --- Recurring error detection ---
        $errorCounts = [];
        preg_match_all('/(?:LLM error|"error"|"status":\s*"error")[^}]{0,200}/i', $corpus, $errMatches);
        foreach ($errMatches[0] as $match) {
            $key = substr(preg_replace('/\s+/', ' ', $match), 0, 100);
            $errorCounts[$key] = ($errorCounts[$key] ?? 0) + 1;
        }
        $recurringErrors = array_filter($errorCounts, fn($count) => $count >= 3);
        if (!empty($recurringErrors)) {
            $signals[] = 'recurring_error';
            arsort($recurringErrors);
            $topKey = array_key_first($recurringErrors);
            $topCount = $recurringErrors[$topKey];
            $signals[] = 'recurring_errsig(' . $topCount . 'x):' . substr($topKey, 0, 150);
        }

        // --- Unsupported input type ---
        if (preg_match('/unsupported mime|unsupported.*type|invalid.*mime/i', $lower)) {
            $signals[] = 'unsupported_input_type';
        }

        // --- Opportunity signals ---

        // user_feature_request
        if (preg_match('/\b(add|implement|create|build|make|develop|write|design)\b[^.?!\n]{3,60}\b(feature|function|module|capability|tool|support|endpoint|command|option|mode)\b/i', $corpus)) {
            $signals[] = 'user_feature_request';
        }
        if (preg_match('/\b(i want|i need|we need|please add|can you add|could you add|let\'?s add)\b/i', $lower)) {
            $signals[] = 'user_feature_request';
        }

        // user_improvement_suggestion
        if (!$errorHit && preg_match('/\b(should be|could be better|improve|enhance|upgrade|refactor|clean up|simplify|streamline)\b/i', $lower)) {
            $signals[] = 'user_improvement_suggestion';
        }

        // perf_bottleneck
        if (preg_match('/\b(slow|timeout|timed?\s*out|latency|bottleneck|took too long|performance issue|high cpu|high memory|oom|out of memory)\b/i', $lower)) {
            $signals[] = 'perf_bottleneck';
        }

        // capability_gap
        if (!in_array('memory_missing', $signals) && !in_array('user_missing', $signals) && !in_array('session_logs_missing', $signals)) {
            if (preg_match('/\b(not supported|cannot|doesn\'?t support|no way to|missing feature|unsupported|not available|not implemented|no support for)\b/i', $lower)) {
                $signals[] = 'capability_gap';
            }
        }

        // stable_success_plateau
        if (preg_match('/\b(all tests pass|stable|no errors|clean run|everything works|100%|perfect)\b/i', $lower)) {
            $signals[] = 'stable_success_plateau';
        }

        // --- Advanced repair loop / stagnation detection from recent events ---
        $recentEvents = $input['recentEvents'] ?? [];
        if (!empty($recentEvents)) {
            $history = $this->analyzeRecentHistory($recentEvents);

            // Basic repair loop detection
            if ($history['consecutiveRepairCount'] >= self::REPAIR_LOOP_THRESHOLD) {
                $signals[] = 'repair_loop_detected';
            }
            
            // Force innovation after extended repair loop
            if ($history['consecutiveRepairCount'] >= self::FORCE_INNOVATION_THRESHOLD) {
                $signals[] = 'force_innovation_after_repair_loop';
            }
            
            // Stagnation detection
            if ($history['consecutiveEmptyCycles'] >= self::STAGNATION_THRESHOLD) {
                $signals[] = 'evolution_stagnation_detected';
            }

            // High failure ratio detection
            if ($history['recentFailureRatio'] >= self::FAILURE_RATIO_THRESHOLD) {
                $signals[] = 'high_failure_rate';
            }

            // Oscillation detection (same signal appearing and disappearing)
            if ($history['oscillationCount'] >= self::OSCILLATION_THRESHOLD) {
                $signals[] = 'signal_oscillation_detected';
            }

            // Remove suppressed signals (appeared too frequently without resolution)
            $signals = array_filter($signals, function ($s) use ($history) {
                $key = str_starts_with($s, 'errsig:') ? 'errsig' :
                    (str_starts_with($s, 'recurring_errsig') ? 'recurring_errsig' : $s);
                return !$history['suppressedSignals']->contains($key);
            });
        }

        return array_values(array_unique($signals));
    }

    /**
     * Analyze recent evolution history for de-duplication and loop detection.
     * Enhanced with oscillation detection and trend analysis.
     */
    public function analyzeRecentHistory(array $recentEvents): array
    {
        if (empty($recentEvents)) {
            return [
                'suppressedSignals' => new \SplFixedArray(0),
                'recentIntents' => [],
                'consecutiveRepairCount' => 0,
                'emptyCycleCount' => 0,
                'consecutiveEmptyCycles' => 0,
                'consecutiveFailureCount' => 0,
                'recentFailureCount' => 0,
                'recentFailureRatio' => 0.0,
                'signalFreq' => [],
                'geneFreq' => [],
                'oscillationCount' => 0,
                'uniqueFilesTouched' => [],
                'repeatedFileModifications' => [],
            ];
        }

        $recent = array_slice($recentEvents, -10);

        // Count consecutive repair intent at tail
        $consecutiveRepairCount = 0;
        for ($i = count($recent) - 1; $i >= 0; $i--) {
            if (($recent[$i]['intent'] ?? '') === 'repair') {
                $consecutiveRepairCount++;
            } else {
                break;
            }
        }

        // Count signal/gene frequency in last 8 events
        $tail = array_slice($recent, -8);
        $signalFreq = [];
        $geneFreq = [];
        $filesTouched = [];
        $fileModificationCounts = [];
        
        foreach ($tail as $idx => $evt) {
            foreach ($evt['signals'] ?? [] as $s) {
                $s = (string)$s;
                $key = str_starts_with($s, 'errsig:') ? 'errsig' :
                    (str_starts_with($s, 'recurring_errsig') ? 'recurring_errsig' : $s);
                $signalFreq[$key] = ($signalFreq[$key] ?? 0) + 1;
            }
            foreach ($evt['genes_used'] ?? [] as $g) {
                $geneFreq[(string)$g] = ($geneFreq[(string)$g] ?? 0) + 1;
            }
            
            // Track files touched
            $blastRadius = $evt['blast_radius'] ?? null;
            if ($blastRadius && !empty($blastRadius['files'])) {
                $eventFiles = $evt['modified_files'] ?? [];
                foreach ($eventFiles as $file) {
                    $filesTouched[] = $file;
                    $fileModificationCounts[$file] = ($fileModificationCounts[$file] ?? 0) + 1;
                }
            }
        }

        // Build suppressed signals set (appeared 3+ times in last 8 events)
        $suppressedSignalsArray = [];
        foreach ($signalFreq as $sig => $count) {
            if ($count >= 3) {
                $suppressedSignalsArray[] = $sig;
            }
        }
        
        // Use a simple object with a contains method
        $suppressedSignals = new class($suppressedSignalsArray) {
            private array $set;
            public function __construct(array $items) {
                $this->set = array_flip($items);
            }
            public function contains(string $item): bool {
                return isset($this->set[$item]);
            }
        };

        // Count empty/failed cycles
        $emptyCycleCount = 0;
        $consecutiveEmptyCycles = 0;
        $consecutiveFailureCount = 0;
        $recentFailureCount = 0;

        foreach ($tail as $evt) {
            $br = $evt['blast_radius'] ?? null;
            $isEmpty = ($evt['meta']['empty_cycle'] ?? false) ||
                ($br !== null && ($br['files'] ?? 0) === 0 && ($br['lines'] ?? 0) === 0);
            if ($isEmpty) {
                $emptyCycleCount++;
            }
            if (($evt['outcome']['status'] ?? '') === 'failed') {
                $recentFailureCount++;
            }
        }

        for ($i = count($recent) - 1; $i >= 0; $i--) {
            $br = $recent[$i]['blast_radius'] ?? null;
            $isEmpty = ($recent[$i]['meta']['empty_cycle'] ?? false) ||
                ($br !== null && ($br['files'] ?? 0) === 0 && ($br['lines'] ?? 0) === 0);
            if ($isEmpty) {
                $consecutiveEmptyCycles++;
            } else {
                break;
            }
        }

        for ($i = count($recent) - 1; $i >= 0; $i--) {
            if (($recent[$i]['outcome']['status'] ?? '') === 'failed') {
                $consecutiveFailureCount++;
            } else {
                break;
            }
        }

        // Detect oscillation (signals appearing and disappearing)
        $oscillationCount = 0;
        $signalAppearances = [];
        foreach ($recent as $idx => $evt) {
            foreach ($evt['signals'] ?? [] as $s) {
                $key = str_starts_with($s, 'errsig:') ? 'errsig' :
                    (str_starts_with($s, 'recurring_errsig') ? 'recurring_errsig' : $s);
                if (!isset($signalAppearances[$key])) {
                    $signalAppearances[$key] = ['first' => $idx, 'last' => $idx, 'count' => 0];
                }
                $signalAppearances[$key]['last'] = $idx;
                $signalAppearances[$key]['count']++;
            }
        }
        
        foreach ($signalAppearances as $key => $appearances) {
            // If a signal appears, disappears, then reappears, that's oscillation
            if ($appearances['count'] >= 2 && ($appearances['last'] - $appearances['first']) >= 2) {
                $oscillationCount++;
            }
        }

        // Find repeatedly modified files (potential thrashing)
        $repeatedFileModifications = [];
        foreach ($fileModificationCounts as $file => $count) {
            if ($count >= 3) {
                $repeatedFileModifications[] = $file;
            }
        }

        return [
            'suppressedSignals' => $suppressedSignals,
            'recentIntents' => array_map(fn($e) => $e['intent'] ?? 'unknown', $recent),
            'consecutiveRepairCount' => $consecutiveRepairCount,
            'emptyCycleCount' => $emptyCycleCount,
            'consecutiveEmptyCycles' => $consecutiveEmptyCycles,
            'consecutiveFailureCount' => $consecutiveFailureCount,
            'recentFailureCount' => $recentFailureCount,
            'recentFailureRatio' => count($tail) > 0 ? $recentFailureCount / count($tail) : 0.0,
            'signalFreq' => $signalFreq,
            'geneFreq' => $geneFreq,
            'oscillationCount' => $oscillationCount,
            'uniqueFilesTouched' => array_unique($filesTouched),
            'repeatedFileModifications' => $repeatedFileModifications,
        ];
    }

    /**
     * Check if any opportunity signal is present.
     */
    public function hasOpportunitySignal(array $signals): bool
    {
        foreach (self::OPPORTUNITY_SIGNALS as $opp) {
            if (in_array($opp, $signals, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get repair loop detection thresholds for external use.
     */
    public static function getThresholds(): array
    {
        return [
            'repair_loop' => self::REPAIR_LOOP_THRESHOLD,
            'force_innovation' => self::FORCE_INNOVATION_THRESHOLD,
            'stagnation' => self::STAGNATION_THRESHOLD,
            'failure_ratio' => self::FAILURE_RATIO_THRESHOLD,
            'oscillation' => self::OSCILLATION_THRESHOLD,
        ];
    }
}
