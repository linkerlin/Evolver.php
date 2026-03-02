<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Knowledge graph tracking signal→gene→outcome edges with Laplace-smoothed success probabilities and decay.
 * PHP port of memoryGraph.js from EvoMap/evolver.
 */
final class MemoryGraph
{
    /**
     * Get current ISO timestamp.
     */
    public static function nowIso(): string
    {
        return date('c');
    }

    /**
     * Clamp a value to the [0, 1] range.
     */
    public static function clamp01(mixed $x): float
    {
        $n = is_numeric($x) ? (float)$x : NAN;
        if (!is_finite($n)) {
            return 0.0;
        }
        return max(0.0, min(1.0, $n));
    }

    /**
     * Generate a stable hash from input string.
     */
    public static function stableHash(string $input): string
    {
        $h = 2166136261;
        for ($i = 0; $i < strlen($input); $i++) {
            $h ^= ord($input[$i]);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }
        return str_pad(dechex($h), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize error signature for matching.
     */
    public static function normalizeErrorSignature(string $text): ?string
    {
        $s = trim($text);
        if ($s === '') {
            return null;
        }
        $s = strtolower($s);
        // Normalize Windows paths
        $s = preg_replace('/[a-z]:\\\\[^\s\n\r\t]+/i', '<path>', $s);
        // Normalize Unix paths
        $s = preg_replace('~/[^\s\n\r\t]+~', '<path>', $s);
        // Normalize hex and numbers
        $s = preg_replace('/\b0x[0-9a-f]+\b/i', '<hex>', $s);
        $s = preg_replace('/\b\d+\b/', '<n>', $s);
        // Normalize whitespace
        $s = preg_replace('/\s+/', ' ', $s);
        return substr($s, 0, 220);
    }

    /**
     * Normalize signals for matching.
     *
     * @param array<mixed> $signals
     * @return string[]
     */
    public static function normalizeSignalsForMatching(array $signals): array
    {
        $out = [];
        foreach ($signals as $s) {
            $str = trim((string)$s);
            if ($str === '') {
                continue;
            }
            if (str_starts_with($str, 'errsig:')) {
                $norm = self::normalizeErrorSignature(substr($str, 7));
                if ($norm !== null) {
                    $out[] = 'errsig_norm:' . self::stableHash($norm);
                }
                continue;
            }
            $out[] = $str;
        }
        return $out;
    }

    /**
     * Compute a stable signal key from signals array.
     *
     * @param array<mixed> $signals
     */
    public static function computeSignalKey(array $signals): string
    {
        $list = self::normalizeSignalsForMatching($signals);
        $uniq = array_unique(array_filter($list));
        sort($uniq);
        return implode('|', $uniq) ?: '(none)';
    }

    /**
     * Extract error signature from signals.
     *
     * @param array<mixed> $signals
     */
    public static function extractErrorSignatureFromSignals(array $signals): ?string
    {
        foreach ($signals as $s) {
            $str = (string)$s;
            if (str_starts_with($str, 'errsig:')) {
                return self::normalizeErrorSignature(substr($str, 7));
            }
        }
        return null;
    }

    /**
     * Get the memory graph file path.
     */
    public static function memoryGraphPath(): string
    {
        $custom = getenv('MEMORY_GRAPH_PATH');
        if ($custom) {
            return $custom;
        }
        $workspace = getenv('WORKSPACE_DIR') ?: getcwd();
        $evoDir = $workspace . '/.evolution';
        if (!is_dir($evoDir)) {
            @mkdir($evoDir, 0755, true);
        }
        return $evoDir . '/memory_graph.jsonl';
    }

    /**
     * Get the memory graph state file path.
     */
    public static function memoryGraphStatePath(): string
    {
        $workspace = getenv('WORKSPACE_DIR') ?: getcwd();
        $evoDir = $workspace . '/.evolution';
        if (!is_dir($evoDir)) {
            @mkdir($evoDir, 0755, true);
        }
        return $evoDir . '/memory_graph_state.json';
    }

    /**
     * Append a JSON line to a JSONL file.
     */
    private static function appendJsonl(string $filePath, array $obj): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($filePath, json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    /**
     * Read JSON file if it exists.
     */
    private static function readJsonIfExists(string $filePath, array $fallback): array
    {
        if (!file_exists($filePath)) {
            return $fallback;
        }
        try {
            $raw = file_get_contents($filePath);
            if ($raw === false || trim($raw) === '') {
                return $fallback;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Write JSON file atomically.
     */
    private static function writeJsonAtomic(string $filePath, array $obj): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $filePath . '.tmp';
        file_put_contents($tmp, json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $filePath);
    }

    /**
     * Try to read memory graph events from JSONL file.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tryReadMemoryGraphEvents(int $limitLines = 2000): array
    {
        $p = self::memoryGraphPath();
        if (!file_exists($p)) {
            return [];
        }
        try {
            $raw = file_get_contents($p);
            if ($raw === false) {
                return [];
            }
            $lines = array_filter(array_map('trim', explode("\n", $raw)));
            $recent = array_slice($lines, max(0, count($lines) - $limitLines));
            $events = [];
            foreach ($recent as $line) {
                $obj = json_decode($line, true);
                if (is_array($obj)) {
                    $events[] = $obj;
                }
            }
            return $events;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compute Jaccard similarity between two signal lists.
     *
     * @param array<mixed> $aList
     * @param array<mixed> $bList
     */
    public static function jaccard(array $aList, array $bList): float
    {
        $aNorm = self::normalizeSignalsForMatching($aList);
        $bNorm = self::normalizeSignalsForMatching($bList);
        $a = array_unique(array_map('strval', $aNorm));
        $b = array_unique(array_map('strval', $bNorm));

        if (empty($a) && empty($b)) {
            return 1.0;
        }
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));
        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * Compute decay weight based on age.
     */
    public static function decayWeight(?string $updatedAtIso, float $halfLifeDays): float
    {
        if ($halfLifeDays <= 0 || $updatedAtIso === null) {
            return 1.0;
        }
        $t = strtotime($updatedAtIso);
        if ($t === false) {
            return 1.0;
        }
        $ageDays = (time() - $t) / (60 * 60 * 24);
        if ($ageDays <= 0) {
            return 1.0;
        }
        // Exponential half-life decay: weight = 0.5^(age/hl)
        return pow(0.5, $ageDays / $halfLifeDays);
    }

    /**
     * Aggregate edges from events.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array<string, mixed>>
     */
    public static function aggregateEdges(array $events): array
    {
        $map = [];
        foreach ($events as $ev) {
            if (($ev['type'] ?? null) !== 'MemoryGraphEvent') {
                continue;
            }
            if (($ev['kind'] ?? null) !== 'outcome') {
                continue;
            }
            $signalKey = $ev['signal']['key'] ?? '(none)';
            $geneId = $ev['gene']['id'] ?? null;
            if ($geneId === null) {
                continue;
            }

            $k = "{$signalKey}::{$geneId}";
            $cur = $map[$k] ?? ['signalKey' => $signalKey, 'geneId' => $geneId, 'success' => 0, 'fail' => 0, 'last_ts' => null, 'last_score' => null];

            $status = $ev['outcome']['status'] ?? 'unknown';
            if ($status === 'success') {
                $cur['success']++;
            } elseif ($status === 'failed') {
                $cur['fail']++;
            }

            $ts = $ev['ts'] ?? $ev['created_at'] ?? $ev['at'] ?? null;
            if ($ts !== null && ($cur['last_ts'] === null || strtotime($ts) > strtotime($cur['last_ts']))) {
                $cur['last_ts'] = $ts;
                $cur['last_score'] = isset($ev['outcome']['score']) && is_numeric($ev['outcome']['score'])
                    ? (float)$ev['outcome']['score']
                    : $cur['last_score'];
            }
            $map[$k] = $cur;
        }
        return $map;
    }

    /**
     * Aggregate gene outcomes from events.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<string, array<string, mixed>>
     */
    public static function aggregateGeneOutcomes(array $events): array
    {
        $map = [];
        foreach ($events as $ev) {
            if (($ev['type'] ?? null) !== 'MemoryGraphEvent') {
                continue;
            }
            if (($ev['kind'] ?? null) !== 'outcome') {
                continue;
            }
            $geneId = $ev['gene']['id'] ?? null;
            if ($geneId === null) {
                continue;
            }

            $cur = $map[$geneId] ?? ['geneId' => $geneId, 'success' => 0, 'fail' => 0, 'last_ts' => null, 'last_score' => null];
            $status = $ev['outcome']['status'] ?? 'unknown';
            if ($status === 'success') {
                $cur['success']++;
            } elseif ($status === 'failed') {
                $cur['fail']++;
            }

            $ts = $ev['ts'] ?? $ev['created_at'] ?? $ev['at'] ?? null;
            if ($ts !== null && ($cur['last_ts'] === null || strtotime($ts) > strtotime($cur['last_ts']))) {
                $cur['last_ts'] = $ts;
                $cur['last_score'] = isset($ev['outcome']['score']) && is_numeric($ev['outcome']['score'])
                    ? (float)$ev['outcome']['score']
                    : $cur['last_score'];
            }
            $map[$geneId] = $cur;
        }
        return $map;
    }

    /**
     * Compute expected success for an edge.
     *
     * @param array<string, mixed> $edge
     * @return array<string, mixed>
     */
    public static function edgeExpectedSuccess(array $edge, float $halfLifeDays = 30): array
    {
        $succ = (int)($edge['success'] ?? 0);
        $fail = (int)($edge['fail'] ?? 0);
        $total = $succ + $fail;
        $p = ($succ + 1) / ($total + 2); // Laplace smoothing
        $w = self::decayWeight($edge['last_ts'] ?? null, $halfLifeDays);
        return [
            'p' => $p,
            'w' => $w,
            'total' => $total,
            'value' => $p * $w,
        ];
    }

    /**
     * Get memory advice for gene selection.
     *
     * @param array<mixed> $signals
     * @param array<int, array<string, mixed>> $genes
     * @return array<string, mixed>
     */
    public static function getMemoryAdvice(array $signals, array $genes, bool $driftEnabled = false): array
    {
        $events = self::tryReadMemoryGraphEvents(2000);
        $edges = self::aggregateEdges($events);
        $geneOutcomes = self::aggregateGeneOutcomes($events);
        $curKey = self::computeSignalKey($signals);

        $bannedGeneIds = [];
        $scoredGeneIds = [];

        // Build candidate keys with similarity
        $seenKeys = [$curKey => true];
        $candidateKeys = [['key' => $curKey, 'sim' => 1.0]];

        foreach ($events as $ev) {
            if (($ev['type'] ?? null) !== 'MemoryGraphEvent') {
                continue;
            }
            $k = $ev['signal']['key'] ?? '(none)';
            if (isset($seenKeys[$k])) {
                continue;
            }
            $sigs = $ev['signal']['signals'] ?? [];
            $sim = self::jaccard($signals, $sigs);
            if ($sim >= 0.34) {
                $candidateKeys[] = ['key' => $k, 'sim' => $sim];
                $seenKeys[$k] = true;
            }
        }

        $byGene = [];
        foreach ($candidateKeys as $ck) {
            foreach ($genes as $g) {
                if (($g['type'] ?? null) !== 'Gene' || !isset($g['id'])) {
                    continue;
                }
                $k = "{$ck['key']}::{$g['id']}";
                $edge = $edges[$k] ?? null;
                $cur = $byGene[$g['id']] ?? ['geneId' => $g['id'], 'best' => 0, 'attempts' => 0, 'prior' => 0, 'prior_attempts' => 0];

                // Signal->Gene edge score
                if ($edge !== null) {
                    $ex = self::edgeExpectedSuccess($edge, 30);
                    $weighted = $ex['value'] * $ck['sim'];
                    if ($weighted > $cur['best']) {
                        $cur['best'] = $weighted;
                    }
                    $cur['attempts'] = max($cur['attempts'], $ex['total']);
                }

                // Gene->Outcome prior
                $gEdge = $geneOutcomes[$g['id']] ?? null;
                if ($gEdge !== null) {
                    $gx = self::edgeExpectedSuccess($gEdge, 45);
                    $cur['prior'] = max($cur['prior'], $gx['value']);
                    $cur['prior_attempts'] = max($cur['prior_attempts'], $gx['total']);
                }

                $byGene[$g['id']] = $cur;
            }
        }

        foreach ($byGene as $geneId => $info) {
            $combined = $info['best'] > 0
                ? $info['best'] + $info['prior'] * 0.12
                : $info['prior'] * 0.4;
            $scoredGeneIds[] = [
                'geneId' => $geneId,
                'score' => $combined,
                'attempts' => $info['attempts'],
                'prior' => $info['prior'],
            ];

            // Low-efficiency path suppression
            if (!$driftEnabled && $info['attempts'] >= 2 && $info['best'] < 0.18) {
                $bannedGeneIds[$geneId] = true;
            }
            // Suppress genes with consistently poor global outcomes
            if (!$driftEnabled && $info['attempts'] < 2 && $info['prior_attempts'] >= 3 && $info['prior'] < 0.12) {
                $bannedGeneIds[$geneId] = true;
            }
        }

        usort($scoredGeneIds, fn($a, $b) => $b['score'] <=> $a['score']);
        $preferredGeneId = !empty($scoredGeneIds) ? $scoredGeneIds[0]['geneId'] : null;

        $explanation = [];
        if ($preferredGeneId !== null) {
            $explanation[] = "memory_prefer:{$preferredGeneId}";
        }
        if (!empty($bannedGeneIds)) {
            $banList = implode(',', array_slice(array_keys($bannedGeneIds), 0, 6));
            $explanation[] = "memory_ban:{$banList}";
        }
        if ($preferredGeneId !== null) {
            $top = array_filter($scoredGeneIds, fn($x) => $x['geneId'] === $preferredGeneId);
            $top = reset($top);
            if ($top && $top['prior'] > 0) {
                $explanation[] = 'gene_prior:' . number_format($top['prior'], 3);
            }
        }
        if ($driftEnabled) {
            $explanation[] = 'random_drift:enabled';
        }

        return [
            'currentSignalKey' => $curKey,
            'preferredGeneId' => $preferredGeneId,
            'bannedGeneIds' => array_keys($bannedGeneIds),
            'explanation' => $explanation,
        ];
    }

    /**
     * Record a signal snapshot event.
     *
     * @param array<mixed> $signals
     * @param array<string, mixed>|null $observations
     */
    public static function recordSignalSnapshot(array $signals, ?array $observations = null): array
    {
        $signalKey = self::computeSignalKey($signals);
        $ts = self::nowIso();
        $errsig = self::extractErrorSignatureFromSignals($signals);

        $ev = [
            'type' => 'MemoryGraphEvent',
            'kind' => 'signal',
            'id' => 'mge_' . time() . '_' . self::stableHash("{$signalKey}|signal|{$ts}"),
            'ts' => $ts,
            'signal' => [
                'key' => $signalKey,
                'signals' => $signals,
                'error_signature' => $errsig,
            ],
            'observed' => $observations,
        ];

        self::appendJsonl(self::memoryGraphPath(), $ev);
        return $ev;
    }

    /**
     * Record a hypothesis event.
     *
     * @param array<string, mixed> $opts
     */
    public static function recordHypothesis(array $opts): array
    {
        $signals = $opts['signals'] ?? [];
        $mutation = $opts['mutation'] ?? null;
        $personalityState = $opts['personality_state'] ?? null;
        $selectedGene = $opts['selectedGene'] ?? null;
        $driftEnabled = $opts['driftEnabled'] ?? false;

        $signalKey = self::computeSignalKey($signals);
        $geneId = $selectedGene['id'] ?? null;
        $geneCategory = $selectedGene['category'] ?? null;
        $ts = self::nowIso();
        $errsig = self::extractErrorSignatureFromSignals($signals);
        $hypothesisId = 'hyp_' . time() . '_' . self::stableHash("{$signalKey}|" . ($geneId ?? 'none') . "|{$ts}");

        $mutNorm = Mutation::isValidMutation($mutation) ? Mutation::normalizeMutation($mutation) : null;
        $psNorm = Personality::isValidPersonalityState($personalityState) ? Personality::normalizePersonalityState($personalityState) : null;

        $ev = [
            'type' => 'MemoryGraphEvent',
            'kind' => 'hypothesis',
            'id' => 'mge_' . time() . '_' . self::stableHash("{$hypothesisId}|{$ts}"),
            'ts' => $ts,
            'signal' => ['key' => $signalKey, 'signals' => $signals, 'error_signature' => $errsig],
            'hypothesis' => [
                'id' => $hypothesisId,
                'text' => "Given signal_key={$signalKey}, selecting gene={$geneId} under mode=" . ($driftEnabled ? 'drift' : 'directed'),
                'predicted_outcome' => ['status' => null, 'score' => null],
            ],
            'mutation' => $mutNorm ? [
                'id' => $mutNorm['id'],
                'category' => $mutNorm['category'],
                'trigger_signals' => $mutNorm['trigger_signals'],
                'target' => $mutNorm['target'],
                'expected_effect' => $mutNorm['expected_effect'],
                'risk_level' => $mutNorm['risk_level'],
            ] : null,
            'personality' => $psNorm ? [
                'key' => Personality::personalityKey($psNorm),
                'state' => $psNorm,
            ] : null,
            'gene' => ['id' => $geneId, 'category' => $geneCategory],
            'action' => [
                'drift' => $driftEnabled,
                'selected_by' => $opts['selectedBy'] ?? 'selector',
            ],
        ];

        self::appendJsonl(self::memoryGraphPath(), $ev);
        return ['hypothesisId' => $hypothesisId, 'signalKey' => $signalKey];
    }

    /**
     * Record an attempt event.
     *
     * @param array<string, mixed> $opts
     */
    public static function recordAttempt(array $opts): array
    {
        $signals = $opts['signals'] ?? [];
        $mutation = $opts['mutation'] ?? null;
        $personalityState = $opts['personality_state'] ?? null;
        $selectedGene = $opts['selectedGene'] ?? null;
        $driftEnabled = $opts['driftEnabled'] ?? false;
        $hypothesisId = $opts['hypothesisId'] ?? null;

        $signalKey = self::computeSignalKey($signals);
        $geneId = $selectedGene['id'] ?? null;
        $geneCategory = $selectedGene['category'] ?? null;
        $ts = self::nowIso();
        $errsig = self::extractErrorSignatureFromSignals($signals);
        $actionId = 'act_' . time() . '_' . self::stableHash("{$signalKey}|" . ($geneId ?? 'none') . "|{$ts}");

        $mutNorm = Mutation::isValidMutation($mutation) ? Mutation::normalizeMutation($mutation) : null;
        $psNorm = Personality::isValidPersonalityState($personalityState) ? Personality::normalizePersonalityState($personalityState) : null;

        $ev = [
            'type' => 'MemoryGraphEvent',
            'kind' => 'attempt',
            'id' => 'mge_' . time() . '_' . self::stableHash($actionId),
            'ts' => $ts,
            'signal' => ['key' => $signalKey, 'signals' => $signals, 'error_signature' => $errsig],
            'mutation' => $mutNorm ? [
                'id' => $mutNorm['id'],
                'category' => $mutNorm['category'],
                'trigger_signals' => $mutNorm['trigger_signals'],
                'target' => $mutNorm['target'],
                'expected_effect' => $mutNorm['expected_effect'],
                'risk_level' => $mutNorm['risk_level'],
            ] : null,
            'personality' => $psNorm ? [
                'key' => Personality::personalityKey($psNorm),
                'state' => $psNorm,
            ] : null,
            'gene' => ['id' => $geneId, 'category' => $geneCategory],
            'hypothesis' => $hypothesisId ? ['id' => $hypothesisId] : null,
            'action' => [
                'id' => $actionId,
                'drift' => $driftEnabled,
                'selected_by' => $opts['selectedBy'] ?? 'selector',
            ],
        ];

        self::appendJsonl(self::memoryGraphPath(), $ev);

        // Update state
        $statePath = self::memoryGraphStatePath();
        $state = self::readJsonIfExists($statePath, ['last_action' => null]);
        $state['last_action'] = [
            'action_id' => $actionId,
            'signal_key' => $signalKey,
            'signals' => $signals,
            'mutation_id' => $mutNorm['id'] ?? null,
            'mutation_category' => $mutNorm['category'] ?? null,
            'mutation_risk_level' => $mutNorm['risk_level'] ?? null,
            'personality_key' => $psNorm ? Personality::personalityKey($psNorm) : null,
            'personality_state' => $psNorm,
            'gene_id' => $geneId,
            'gene_category' => $geneCategory,
            'hypothesis_id' => $hypothesisId,
            'had_error' => in_array('log_error', $signals, true),
            'created_at' => $ts,
            'outcome_recorded' => false,
        ];
        self::writeJsonAtomic($statePath, $state);

        return ['actionId' => $actionId, 'signalKey' => $signalKey];
    }

    /**
     * Record outcome from state.
     *
     * @param array<mixed> $signals
     * @param array<string, mixed>|null $observations
     */
    public static function recordOutcomeFromState(array $signals, ?array $observations = null): ?array
    {
        $statePath = self::memoryGraphStatePath();
        $state = self::readJsonIfExists($statePath, ['last_action' => null]);
        $last = $state['last_action'] ?? null;

        if ($last === null || empty($last['action_id'])) {
            return null;
        }
        if (!empty($last['outcome_recorded'])) {
            return null;
        }

        $currentHasError = in_array('log_error', $signals, true);
        $inferred = self::inferOutcome($last['had_error'] ?? false, $currentHasError);

        $ts = self::nowIso();
        $errsig = self::extractErrorSignatureFromSignals($signals);

        $ev = [
            'type' => 'MemoryGraphEvent',
            'kind' => 'outcome',
            'id' => 'mge_' . time() . '_' . self::stableHash("{$last['action_id']}|outcome|{$ts}"),
            'ts' => $ts,
            'signal' => [
                'key' => $last['signal_key'] ?? '(none)',
                'signals' => $last['signals'] ?? [],
                'error_signature' => $errsig,
            ],
            'mutation' => $last['mutation_id'] ? [
                'id' => $last['mutation_id'],
                'category' => $last['mutation_category'],
                'risk_level' => $last['mutation_risk_level'],
            ] : null,
            'personality' => $last['personality_key'] ? [
                'key' => $last['personality_key'],
                'state' => $last['personality_state'],
            ] : null,
            'gene' => ['id' => $last['gene_id'], 'category' => $last['gene_category']],
            'action' => ['id' => $last['action_id']],
            'hypothesis' => $last['hypothesis_id'] ? ['id' => $last['hypothesis_id']] : null,
            'outcome' => [
                'status' => $inferred['status'],
                'score' => $inferred['score'],
                'note' => $inferred['note'],
                'observed' => ['current_signals' => $signals],
            ],
        ];

        self::appendJsonl(self::memoryGraphPath(), $ev);

        // Mark outcome recorded
        $state['last_action']['outcome_recorded'] = true;
        $state['last_action']['outcome_recorded_at'] = $ts;
        self::writeJsonAtomic($statePath, $state);

        return $ev;
    }

    /**
     * Infer outcome from error state changes.
     */
    private static function inferOutcome(bool $prevHadError, bool $currentHasError): array
    {
        if ($prevHadError && !$currentHasError) {
            return ['status' => 'success', 'score' => 0.85, 'note' => 'error_cleared'];
        }
        if ($prevHadError && $currentHasError) {
            return ['status' => 'failed', 'score' => 0.2, 'note' => 'error_persisted'];
        }
        if (!$prevHadError && $currentHasError) {
            return ['status' => 'failed', 'score' => 0.15, 'note' => 'new_error_appeared'];
        }
        return ['status' => 'success', 'score' => 0.6, 'note' => 'stable_no_error'];
    }

    /**
     * Record an external candidate event.
     *
     * @param array<string, mixed> $asset
     * @param array<mixed> $signals
     */
    public static function recordExternalCandidate(array $asset, string $source, array $signals): ?array
    {
        $type = $asset['type'] ?? null;
        $id = $asset['id'] ?? null;
        if ($type === null || $id === null) {
            return null;
        }

        $ts = self::nowIso();
        $signalKey = self::computeSignalKey($signals);

        $ev = [
            'type' => 'MemoryGraphEvent',
            'kind' => 'external_candidate',
            'id' => 'mge_' . time() . '_' . self::stableHash("{$type}|{$id}|external|{$ts}"),
            'ts' => $ts,
            'signal' => ['key' => $signalKey, 'signals' => $signals],
            'external' => [
                'source' => $source ?: 'external',
                'received_at' => $ts,
            ],
            'asset' => ['type' => $type, 'id' => $id],
            'candidate' => [
                'trigger' => $type === 'Capsule' ? ($asset['trigger'] ?? []) : [],
                'gene' => $type === 'Capsule' ? ($asset['gene'] ?? null) : null,
                'confidence' => $type === 'Capsule' && is_numeric($asset['confidence'] ?? null) ? (float)$asset['confidence'] : null,
            ],
        ];

        self::appendJsonl(self::memoryGraphPath(), $ev);
        return $ev;
    }
}
