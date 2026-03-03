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
        'empty_cycle_loop_detected',
        'force_steady_state',
        'evolution_saturation',
        'consecutive_failure_streak',
        'failure_loop_detected',
        'high_failure_ratio',
        'high_tool_usage',
        'repeated_tool_usage',
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

            // --- Saturation detection (graceful degradation) ---
            // When consecutive empty cycles pile up, the evolver has exhausted its innovation space
            if ($history['consecutiveEmptyCycles'] >= 5) {
                if (!in_array('force_steady_state', $signals)) $signals[] = 'force_steady_state';
                if (!in_array('evolution_saturation', $signals)) $signals[] = 'evolution_saturation';
            } elseif ($history['consecutiveEmptyCycles'] >= 3) {
                if (!in_array('evolution_saturation', $signals)) $signals[] = 'evolution_saturation';
            }

            // --- Failure streak awareness ---
            if ($history['consecutiveFailureCount'] >= 3) {
                $signals[] = 'consecutive_failure_streak_' . $history['consecutiveFailureCount'];
                if ($history['consecutiveFailureCount'] >= 5) {
                    $signals[] = 'failure_loop_detected';
                    // Ban the dominant gene
                    if (!empty($history['topGene'])) {
                        $signals[] = 'ban_gene:' . $history['topGene'];
                    }
                }
            }

            // High failure ratio (>= 75% failed in last 8 cycles)
            if ($history['recentFailureRatio'] >= 0.75) {
                $signals[] = 'high_failure_ratio';
                if (!in_array('force_innovation_after_repair_loop', $signals)) {
                    $signals[] = 'force_innovation_after_repair_loop';
                }
            }

            // 移除suppressed signals (appeared too frequently without resolution)
            $signals = array_filter($signals, function ($s) use ($history) {
                $key = str_starts_with($s, 'errsig:') ? 'errsig' :
                    (str_starts_with($s, 'recurring_errsig') ? 'recurring_errsig' : $s);
                return !$history['suppressedSignals']->contains($key);
            });
        }

        // --- Tool Usage Analytics ---
        $toolUsage = [];
        preg_match_all('/\[TOOL:\s*([\w-]+)\]/', $corpus, $toolMatches);
        $toolMatches = $toolMatches[1] ?? [];
        
        // Extract exec commands to identify benign loops
        preg_match_all('/exec:\s*(php\s+[\w\/\.-]+\.php\s+ensure)/i', $corpus, $execMatches);
        $benignExecCount = count($execMatches[0] ?? []);
        
        foreach ($toolMatches as $toolName) {
            $toolUsage[$toolName] = ($toolUsage[$toolName] ?? 0) + 1;
        }
        
        // Adjust exec count by subtracting benign commands
        if (isset($toolUsage['exec'])) {
            $toolUsage['exec'] = max(0, $toolUsage['exec'] - $benignExecCount);
        }
        
        foreach ($toolUsage as $tool => $count) {
            if ($count >= 10) {
                $signals[] = 'high_tool_usage:' . $tool;
            }
            if ($tool === 'exec' && $count >= 5) {
                $signals[] = 'repeated_tool_usage:exec';
            }
        }

        // --- Multi-language feature request detection ---
        $featureRequestSnippet = $this->extractFeatureRequestSnippet($corpus);
        if ($featureRequestSnippet !== null) {
            $signals[] = 'user_feature_request:' . $featureRequestSnippet;
        }

        // --- Multi-language improvement suggestion detection ---
        if (!$errorHit) {
            $improvementSnippet = $this->extractImprovementSnippet($corpus);
            if ($improvementSnippet !== null) {
                $signals[] = 'user_improvement_suggestion:' . $improvementSnippet;
            }
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

        // 构建suppressed signals set (appeared 3+ times in last 8 events)
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

        // Find top gene (most frequently used)
        $topGene = null;
        $topGeneCount = 0;
        foreach ($geneFreq as $gene => $count) {
            if ($count > $topGeneCount) {
                $topGeneCount = $count;
                $topGene = $gene;
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
            'topGene' => $topGene,
            'oscillationCount' => $oscillationCount,
            'uniqueFilesTouched' => array_unique($filesTouched),
            'repeatedFileModifications' => $repeatedFileModifications,
        ];
    }

    /**
     * 检查 any opportunity signal is present.
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
     * 获取repair loop detection thresholds for external use.
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

    // -------------------------------------------------------------------------
    // Multi-source signal fusion (Task 1.2)
    // -------------------------------------------------------------------------

    /**
     * Extract signals from multiple sources and merge them.
     * Sources can include: session_logs, today_logs, memory_snippets, user_snippets, etc.
     *
     * @param array<string, string> $sources Map of source name to content
     * @return array<string, mixed> Merged signals with source attribution
     */
    public function extractFromMultipleSources(array $sources): array
    {
        $allSignals = [];
        $sourceSignals = [];

        foreach ($sources as $sourceName => $content) {
            if (empty($content)) {
                continue;
            }

            $signals = $this->extract(['context' => $content]);
            $sourceSignals[$sourceName] = $signals;

            foreach ($signals as $signal) {
                if (!isset($allSignals[$signal])) {
                    $allSignals[$signal] = [
                        'signal' => $signal,
                        'sources' => [],
                        'count' => 0,
                    ];
                }
                $allSignals[$signal]['sources'][] = $sourceName;
                $allSignals[$signal]['count']++;
            }
        }

        // Sort by count (most frequent first)
        uasort($allSignals, fn($a, $b) => $b['count'] <=> $a['count']);

        return [
            'signals' => array_keys($allSignals),
            'detailed' => array_values($allSignals),
            'by_source' => $sourceSignals,
        ];
    }

    /**
     * Parse session transcript and extract structured data.
     * Extracts messages, tool calls, tool results, and errors.
     *
     * @return array{
     *   messages: array,
     *   tool_calls: array,
     *   tool_results: array,
     *   errors: array,
     *   metadata: array
     * }
     */
    public function parseSessionTranscript(string $log): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $log)));
        
        $messages = [];
        $toolCalls = [];
        $toolResults = [];
        $errors = [];
        $metadata = [
            'total_lines' => count($lines),
            'has_errors' => false,
            'timestamp_range' => null,
        ];

        foreach ($lines as $line) {
            // Try to parse as JSON first
            $data = json_decode($line, true);
            if (is_array($data)) {
                // Extract message
                if (isset($data['message']) || isset($data['content'])) {
                    $messages[] = [
                        'type' => 'message',
                        'role' => $data['role'] ?? 'unknown',
                        'content' => $data['message']['content'] ?? $data['content'] ?? '',
                        'timestamp' => $data['timestamp'] ?? null,
                    ];
                }

                // Extract tool calls
                if (isset($data['tool_calls']) || isset($data['tool_call'])) {
                    $calls = $data['tool_calls'] ?? [$data['tool_call']];
                    foreach ((array)$calls as $call) {
                        $toolCalls[] = [
                            'name' => is_array($call) ? ($call['name'] ?? 'unknown') : (string)$call,
                            'args' => is_array($call) ? ($call['args'] ?? []) : [],
                            'timestamp' => $data['timestamp'] ?? null,
                        ];
                    }
                }

                // Extract tool results
                if (isset($data['tool_results']) || isset($data['tool_result'])) {
                    $results = $data['tool_results'] ?? [$data['tool_result']];
                    foreach ((array)$results as $result) {
                        $toolResults[] = [
                            'status' => is_array($result) ? ($result['status'] ?? 'unknown') : 'unknown',
                            'content' => is_array($result) ? json_encode($result) : (string)$result,
                            'timestamp' => $data['timestamp'] ?? null,
                        ];
                    }
                }

                // Extract errors
                if (isset($data['error']) || isset($data['errorMessage'])) {
                    $errorMsg = $data['errorMessage'] ?? (is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : (string)$data['error']);
                    $errors[] = [
                        'message' => $errorMsg,
                        'timestamp' => $data['timestamp'] ?? null,
                    ];
                    $metadata['has_errors'] = true;
                }
            } else {
                // Try regex patterns for non-JSON lines
                if (preg_match('/\[TOOL:\s*([^\]]+)\]/i', $line, $m)) {
                    $toolCalls[] = [
                        'name' => trim($m[1]),
                        'args' => [],
                        'timestamp' => null,
                    ];
                }

                if (preg_match('/\[error\]|error:|exception:/i', $line)) {
                    $errors[] = [
                        'message' => substr($line, 0, 500),
                        'timestamp' => null,
                    ];
                    $metadata['has_errors'] = true;
                }
            }
        }

        return [
            'messages' => $messages,
            'tool_calls' => $toolCalls,
            'tool_results' => $toolResults,
            'errors' => $errors,
            'metadata' => $metadata,
        ];
    }

    /**
     * Detect repair loop from recent events.
     * Returns the type of loop detected or null if no loop.
     */
    public function detectRepairLoop(array $recentEvents): ?string
    {
        if (empty($recentEvents)) {
            return null;
        }

        $history = $this->analyzeRecentHistory($recentEvents);

        // Check for repair loop
        if ($history['consecutiveRepairCount'] >= self::FORCE_INNOVATION_THRESHOLD) {
            return 'force_innovation_required';
        }

        if ($history['consecutiveRepairCount'] >= self::REPAIR_LOOP_THRESHOLD) {
            return 'repair_loop_detected';
        }

        // Check for stagnation
        if ($history['consecutiveEmptyCycles'] >= 5) {
            return 'evolution_saturation';
        }

        if ($history['consecutiveEmptyCycles'] >= self::STAGNATION_THRESHOLD) {
            return 'evolution_stagnation_detected';
        }

        // Check for failure streak
        if ($history['consecutiveFailureCount'] >= 5) {
            return 'failure_loop_detected';
        }

        if ($history['recentFailureRatio'] >= self::FAILURE_RATIO_THRESHOLD) {
            return 'high_failure_ratio';
        }

        // Check for oscillation
        if ($history['oscillationCount'] >= self::OSCILLATION_THRESHOLD) {
            return 'signal_oscillation_detected';
        }

        return null;
    }

    /**
     * Deduplicate and fold similar signals.
     * Groups related signals and provides frequency counts.
     *
     * @return array<int, array{signal: string, folded: bool, count: int, related: array}>
     */
    public function deduplicateSignals(array $signals): array
    {
        if (empty($signals)) {
            return [];
        }

        $groups = [];
        $result = [];

        foreach ($signals as $signal) {
            // Normalize signal for grouping
            $normalized = $this->normalizeSignalForGrouping($signal);
            
            if (!isset($groups[$normalized])) {
                $groups[$normalized] = [
                    'canonical' => $signal,
                    'count' => 0,
                    'variants' => [],
                ];
            }
            
            $groups[$normalized]['count']++;
            if ($signal !== $groups[$normalized]['canonical']) {
                $groups[$normalized]['variants'][] = $signal;
            }
        }

        foreach ($groups as $normalized => $group) {
            $result[] = [
                'signal' => $group['canonical'],
                'folded' => $group['count'] > 1,
                'count' => $group['count'],
                'related' => array_unique($group['variants']),
            ];
        }

        // Sort by count descending
        usort($result, fn($a, $b) => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Normalize signal for grouping purposes.
     */
    private function normalizeSignalForGrouping(string $signal): string
    {
        // Remove dynamic parts like timestamps, line numbers, etc.
        $normalized = $signal;
        
        // Normalize errsig:* signals
        if (str_starts_with($signal, 'errsig:')) {
            return 'errsig:generic';
        }
        
        // Normalize recurring_errsig:* signals
        if (str_starts_with($signal, 'recurring_errsig')) {
            return 'recurring_errsig:generic';
        }

        // Normalize consecutive_failure_streak_* signals
        if (preg_match('/^consecutive_failure_streak_\d+$/', $signal)) {
            return 'consecutive_failure_streak';
        }

        // Normalize user_feature_request:* signals
        if (str_starts_with($signal, 'user_feature_request:')) {
            return 'user_feature_request';
        }

        return $signal;
    }

    // -------------------------------------------------------------------------
    // Multi-language signal extraction helpers
    // -------------------------------------------------------------------------

    /**
     * Extract feature request snippet from corpus (EN, ZH-CN, ZH-TW, JA).
     */
    private function extractFeatureRequestSnippet(string $corpus): ?string
    {
        $snippet = null;

        // English
        if (preg_match('/\b(add|implement|create|build|make|develop|write|design)\b[^.?!
]{3,120}\b(feature|function|module|capability|tool|support|endpoint|command|option|mode)\b/i', $corpus, $m)) {
            $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
        }
        if (!$snippet && preg_match('/\b(i want|i need|we need|please add|can you add|could you add|let\'?s add)\b/i', $corpus)) {
            if (preg_match('/.{0,80}\b(i want|i need|we need|please add|can you add|could you add|let\'?s add)\b.{0,80}/i', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
        }

        // ZH-CN (Simplified Chinese)
        if (!$snippet && preg_match('/加个|实现一下|做个|想要\s*一个|需要\s*一个|帮我加|帮我开发|加一下|新增一个|加个功能|做个功能|我想/', $corpus)) {
            if (preg_match('/.{0,100}(加个|实现一下|做个|想要\s*一个|需要\s*一个|帮我加|帮我开发|加一下|新增一个|加个功能|做个功能).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            if (!$snippet && preg_match('/我想/u', $corpus)) {
                if (preg_match('/我想\s*[，,.。、\s]*([\s\S]{0,400})/u', $corpus, $m)) {
                    $snippet = preg_replace('/\s+/', ' ', trim($m[1])) ?: '功能需求';
                }
            }
            $snippet = $snippet ?: '功能需求';
        }

        // ZH-TW (Traditional Chinese)
        if (!$snippet && preg_match('/加個|實現一下|做個|想要一個|請加|新增一個|加個功能|做個功能|幫我加/u', $corpus)) {
            if (preg_match('/.{0,100}(加個|實現一下|做個|想要一個|請加|新增一個|加個功能|做個功能|幫我加).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            $snippet = $snippet ?: '功能需求';
        }

        // JA (Japanese)
        if (!$snippet && preg_match('/追加|実装|作って|機能を|追加して|が欲しい|を追加|してほしい/u', $corpus)) {
            if (preg_match('/.{0,100}(追加|実装|作って|機能を|追加して|が欲しい|を追加|してほしい).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            $snippet = $snippet ?: '機能要望';
        }

        return $snippet ? substr($snippet, 0, 200) : null;
    }

    /**
     * Extract improvement suggestion snippet from corpus (EN, ZH-CN, ZH-TW, JA).
     */
    private function extractImprovementSnippet(string $corpus): ?string
    {
        $snippet = null;

        // English
        if (preg_match('/.{0,80}\b(should be|could be better|improve|enhance|upgrade|refactor|clean up|simplify|streamline)\b.{0,80}/i', $corpus, $m)) {
            $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
        }

        // ZH-CN
        if (!$snippet && preg_match('/改进一下|优化一下|简化|重构|整理一下|弄得更好/u', $corpus)) {
            if (preg_match('/.{0,100}(改进一下|优化一下|简化|重构|整理一下|弄得更好).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            $snippet = $snippet ?: '改进建议';
        }

        // ZH-TW
        if (!$snippet && preg_match('/改進一下|優化一下|簡化|重構|整理一下|弄得更好/u', $corpus)) {
            if (preg_match('/.{0,100}(改進一下|優化一下|簡化|重構|整理一下|弄得更好).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            $snippet = $snippet ?: '改進建議';
        }

        // JA
        if (!$snippet && preg_match('/改善|最適化|簡素化|リファクタ|良くして|改良/u', $corpus)) {
            if (preg_match('/.{0,100}(改善|最適化|簡素化|リファクタ|良くして|改良).{0,100}/u', $corpus, $m)) {
                $snippet = preg_replace('/\s+/', ' ', trim($m[0]));
            }
            $snippet = $snippet ?: '改善要望';
        }

        return $snippet ? substr($snippet, 0, 200) : null;
    }
}
