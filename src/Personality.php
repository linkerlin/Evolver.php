<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Adaptive personality state management with natural selection and triggered mutations.
 * PHP port of personality.js from EvoMap/evolver.
 */
final class Personality
{
    private const PERSONALITY_PARAMS = ['rigor', 'creativity', 'verbosity', 'risk_tolerance', 'obedience'];

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
     * Get the personality state file path.
     */
    public static function personalityFilePath(): string
    {
        $workspace = getenv('WORKSPACE_DIR') ?: getcwd();
        $evoDir = $workspace . '/.evolution';
        if (!is_dir($evoDir)) {
            @mkdir($evoDir, 0755, true);
        }
        return $evoDir . '/personality_state.json';
    }

    /**
     * Return default conservative personality state.
     */
    public static function defaultPersonalityState(): array
    {
        return [
            'type' => 'PersonalityState',
            'rigor' => 0.7,
            'creativity' => 0.35,
            'verbosity' => 0.25,
            'risk_tolerance' => 0.4,
            'obedience' => 0.85,
        ];
    }

    /**
     * Normalize a personality state object.
     */
    public static function normalizePersonalityState(mixed $state): array
    {
        $s = is_array($state) ? $state : [];
        $result = ['type' => 'PersonalityState'];
        foreach (self::PERSONALITY_PARAMS as $k) {
            $result[$k] = self::clamp01($s[$k] ?? 0.5);
        }
        return $result;
    }

    /**
     * Validate a personality state object.
     */
    public static function isValidPersonalityState(mixed $obj): bool
    {
        if (!is_array($obj)) {
            return false;
        }
        if (($obj['type'] ?? null) !== 'PersonalityState') {
            return false;
        }
        foreach (self::PERSONALITY_PARAMS as $k) {
            if (!isset($obj[$k]) || !is_numeric($obj[$k])) {
                return false;
            }
            $n = (float)$obj[$k];
            if ($n < 0 || $n > 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Round to a given step value.
     */
    private static function roundToStep(float $x, float $step): float
    {
        if ($step <= 0) {
            return $x;
        }
        return round($x / $step) * $step;
    }

    /**
     * Generate a unique key for a personality state.
     */
    public static function personalityKey(array $state): string
    {
        $s = self::normalizePersonalityState($state);
        $step = 0.1;
        $parts = [];
        foreach (self::PERSONALITY_PARAMS as $k) {
            $parts[] = "{$k}=" . number_format(self::roundToStep($s[$k], $step), 1);
        }
        return implode('|', $parts);
    }

    /**
     * Compute parameter deltas between two personality states.
     */
    private static function getParamDeltas(array $fromState, array $toState): array
    {
        $a = self::normalizePersonalityState($fromState);
        $b = self::normalizePersonalityState($toState);
        $deltas = [];
        foreach (self::PERSONALITY_PARAMS as $k) {
            $deltas[] = ['param' => $k, 'delta' => (float)$b[$k] - (float)$a[$k]];
        }
        usort($deltas, fn($x, $y) => abs($y['delta']) <=> abs($x['delta']));
        return $deltas;
    }

    /**
     * Compute personality score from stats entry.
     */
    private static function personalityScore(array $entry): float
    {
        $succ = (int)($entry['success'] ?? 0);
        $fail = (int)($entry['fail'] ?? 0);
        $total = $succ + $fail;
        // Laplace-smoothed success probability
        $p = ($succ + 1) / ($total + 2);
        // Penalize tiny-sample overconfidence
        $sampleWeight = min(1, $total / 8);
        // Use avg_score (if present) as mild quality proxy
        $avg = isset($entry['avg_score']) && is_numeric($entry['avg_score'])
            ? self::clamp01($entry['avg_score'])
            : 0.5;
        return $p * 0.75 + $avg * 0.25 * $sampleWeight;
    }

    /**
     * Choose the best-known personality from stats.
     */
    private static function chooseBestKnownPersonality(array $statsByKey): ?array
    {
        $best = null;
        foreach ($statsByKey as $k => $entry) {
            $total = ((int)($entry['success'] ?? 0)) + ((int)($entry['fail'] ?? 0));
            if ($total < 3) {
                continue;
            }
            $sc = self::personalityScore($entry);
            if ($best === null || $sc > $best['score']) {
                $best = ['key' => $k, 'score' => $sc, 'entry' => $entry];
            }
        }
        return $best;
    }

    /**
     * Parse a personality key back to state.
     */
    private static function parseKeyToState(string $key): array
    {
        $out = self::defaultPersonalityState();
        $parts = array_filter(array_map('trim', explode('|', $key)));
        foreach ($parts as $p) {
            $kv = array_map('trim', explode('=', $p, 2));
            if (count($kv) !== 2) {
                continue;
            }
            [$k, $v] = $kv;
            if (!in_array($k, self::PERSONALITY_PARAMS, true)) {
                continue;
            }
            $out[$k] = self::clamp01((float)$v);
        }
        return self::normalizePersonalityState($out);
    }

    /**
     * Apply personality mutations to a state.
     */
    private static function applyPersonalityMutations(array $state, array $mutations): array
    {
        $cur = self::normalizePersonalityState($state);
        $applied = [];
        $count = 0;

        foreach ($mutations as $m) {
            if (!is_array($m)) {
                continue;
            }
            $param = trim((string)($m['param'] ?? ''));
            if (!in_array($param, self::PERSONALITY_PARAMS, true)) {
                continue;
            }
            $delta = (float)($m['delta'] ?? 0);
            if (!is_finite($delta)) {
                continue;
            }
            $clipped = max(-0.2, min(0.2, $delta));
            $cur[$param] = self::clamp01((float)$cur[$param] + $clipped);
            $applied[] = [
                'type' => 'PersonalityMutation',
                'param' => $param,
                'delta' => $clipped,
                'reason' => substr((string)($m['reason'] ?? ''), 0, 140),
            ];
            $count++;
            if ($count >= 2) {
                break;
            }
        }

        return ['state' => $cur, 'applied' => $applied];
    }

    /**
     * Propose personality mutations based on context.
     *
     * @param array<string, mixed>|null $baseState
     * @param array<string> $signals
     */
    private static function proposeMutations(?array $baseState, ?string $reason, bool $driftEnabled, array $signals): array
    {
        $s = self::normalizePersonalityState($baseState);
        $sig = array_map('strval', $signals);
        $muts = [];

        $r = (string)($reason ?? '');
        if ($driftEnabled) {
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'creativity', 'delta' => +0.1, 'reason' => $r ?: 'drift enabled'];
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'risk_tolerance', 'delta' => -0.05, 'reason' => 'drift safety clamp'];
        } elseif (in_array('protocol_drift', $sig, true)) {
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'obedience', 'delta' => +0.1, 'reason' => $r ?: 'protocol drift'];
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'rigor', 'delta' => +0.05, 'reason' => 'tighten protocol compliance'];
        } elseif (in_array('log_error', $sig, true) || self::hasErrorSignature($sig)) {
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'rigor', 'delta' => +0.1, 'reason' => $r ?: 'repair instability'];
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'risk_tolerance', 'delta' => -0.1, 'reason' => 'reduce risky changes under errors'];
        } elseif (Mutation::hasOpportunitySignal($signals)) {
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'creativity', 'delta' => +0.1, 'reason' => $r ?: 'opportunity signal detected'];
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'risk_tolerance', 'delta' => +0.05, 'reason' => 'allow exploration for innovation'];
        } else {
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'rigor', 'delta' => +0.05, 'reason' => $r ?: 'stability bias'];
            $muts[] = ['type' => 'PersonalityMutation', 'param' => 'verbosity', 'delta' => -0.05, 'reason' => 'reduce noise'];
        }

        // If already very high obedience, swap to creativity
        if ($s['obedience'] >= 0.95) {
            foreach ($muts as $i => $m) {
                if ($m['param'] === 'obedience') {
                    $muts[$i] = ['type' => 'PersonalityMutation', 'param' => 'creativity', 'delta' => +0.05, 'reason' => 'obedience saturated'];
                    break;
                }
            }
        }

        return $muts;
    }

    /**
     * Check if signals contain error signatures.
     */
    private static function hasErrorSignature(array $signals): bool
    {
        foreach ($signals as $s) {
            if (str_starts_with($s, 'errsig:') || str_starts_with($s, 'errsig_norm:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determine if personality mutation should be triggered.
     *
     * @param array<string, mixed> $recentEvents
     */
    private static function shouldTriggerPersonalityMutation(bool $driftEnabled, array $recentEvents): array
    {
        if ($driftEnabled) {
            return ['ok' => true, 'reason' => 'drift enabled'];
        }

        $tail = array_slice($recentEvents, -6);
        $outcomes = array_filter(array_map(function ($e) {
            return $e['outcome']['status'] ?? null;
        }, $tail));

        if (count($outcomes) >= 4) {
            $recentFailed = count(array_filter(array_slice($outcomes, -4), fn($x) => $x === 'failed'));
            if ($recentFailed >= 3) {
                return ['ok' => true, 'reason' => 'long failure streak'];
            }
        }

        // Mutation consecutive failure proxy
        $withMut = array_filter($tail, fn($e) => !empty($e['mutation_id']));
        if (count($withMut) >= 3) {
            $last3 = array_slice($withMut, -3);
            $fail3 = count(array_filter($last3, fn($e) => ($e['outcome']['status'] ?? '') === 'failed'));
            if ($fail3 >= 3) {
                return ['ok' => true, 'reason' => 'mutation consecutive failures'];
            }
        }

        return ['ok' => false, 'reason' => ''];
    }

    /**
     * Load the personality model from file.
     */
    public static function loadPersonalityModel(): array
    {
        $p = self::personalityFilePath();
        $fallback = [
            'version' => 1,
            'current' => self::defaultPersonalityState(),
            'stats' => [],
            'history' => [],
            'updated_at' => self::nowIso(),
        ];

        if (!file_exists($p)) {
            return $fallback;
        }

        try {
            $raw = file_get_contents($p);
            if ($raw === false || trim($raw) === '') {
                return $fallback;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $fallback;
            }
            return [
                'version' => 1,
                'current' => self::normalizePersonalityState($data['current'] ?? self::defaultPersonalityState()),
                'stats' => $data['stats'] ?? [],
                'history' => $data['history'] ?? [],
                'updated_at' => $data['updated_at'] ?? self::nowIso(),
            ];
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Save the personality model to file.
     */
    public static function savePersonalityModel(array $model): array
    {
        $out = [
            'version' => 1,
            'current' => self::normalizePersonalityState($model['current'] ?? self::defaultPersonalityState()),
            'stats' => $model['stats'] ?? [],
            'history' => array_slice($model['history'] ?? [], -120),
            'updated_at' => self::nowIso(),
        ];

        $p = self::personalityFilePath();
        $tmp = $p . '.tmp';
        file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, $p);

        return $out;
    }

    /**
     * Select personality for the current evolution run.
     *
     * @param array<string, mixed>|null $opts Options: driftEnabled, signals, recentEvents
     */
    public static function selectPersonalityForRun(?array $opts = null): array
    {
        $driftEnabled = $opts['driftEnabled'] ?? false;
        $signals = $opts['signals'] ?? [];
        $recentEvents = $opts['recentEvents'] ?? [];

        $model = self::loadPersonalityModel();
        $base = self::normalizePersonalityState($model['current']);
        $stats = $model['stats'] ?? [];

        $best = self::chooseBestKnownPersonality($stats);
        $naturalSelectionApplied = [];

        // Natural selection: nudge towards the best-known configuration
        if ($best !== null && isset($best['key'])) {
            $bestState = self::parseKeyToState($best['key']);
            $diffs = array_filter(self::getParamDeltas($base, $bestState), fn($d) => abs($d['delta']) >= 0.05);
            $muts = [];
            foreach (array_slice($diffs, 0, 2) as $d) {
                $clipped = max(-0.1, min(0.1, $d['delta']));
                $muts[] = ['type' => 'PersonalityMutation', 'param' => $d['param'], 'delta' => $clipped, 'reason' => 'natural_selection'];
            }
            $applied = self::applyPersonalityMutations($base, $muts);
            $model['current'] = $applied['state'];
            $naturalSelectionApplied = $applied['applied'];
        }

        // Triggered personality mutation (explicit rule-based)
        $trig = self::shouldTriggerPersonalityMutation((bool)$driftEnabled, $recentEvents);
        $triggeredApplied = [];
        if ($trig['ok']) {
            $props = self::proposeMutations($model['current'], $trig['reason'], (bool)$driftEnabled, $signals);
            $applied = self::applyPersonalityMutations($model['current'], $props);
            $model['current'] = $applied['state'];
            $triggeredApplied = $applied['applied'];
        }

        // Persist updated current state
        $saved = self::savePersonalityModel($model);
        $key = self::personalityKey($saved['current']);
        $known = isset($saved['stats'][$key]);

        return [
            'personality_state' => $saved['current'],
            'personality_key' => $key,
            'personality_known' => $known,
            'personality_mutations' => array_merge($naturalSelectionApplied, $triggeredApplied),
            'model_meta' => [
                'best_known_key' => $best['key'] ?? null,
                'best_known_score' => $best['score'] ?? null,
                'triggered' => $trig['ok'] ? ['reason' => $trig['reason']] : null,
            ],
        ];
    }

    /**
     * Update personality stats after an evolution run.
     *
     * @param array<string, mixed>|null $opts Options: personalityState, outcome, score, notes
     */
    public static function updatePersonalityStats(?array $opts = null): array
    {
        $personalityState = $opts['personalityState'] ?? null;
        $outcome = $opts['outcome'] ?? null;
        $score = $opts['score'] ?? null;
        $notes = $opts['notes'] ?? null;

        $model = self::loadPersonalityModel();
        $st = self::normalizePersonalityState($personalityState ?? $model['current']);
        $key = self::personalityKey($st);

        if (!is_array($model['stats'] ?? null)) {
            $model['stats'] = [];
        }

        $cur = $model['stats'][$key] ?? ['success' => 0, 'fail' => 0, 'avg_score' => 0.5, 'n' => 0];

        $out = strtolower((string)$outcome);
        if ($out === 'success') {
            $cur['success'] = ((int)$cur['success']) + 1;
        } elseif ($out === 'failed') {
            $cur['fail'] = ((int)$cur['fail']) + 1;
        }

        if (is_numeric($score)) {
            $sc = self::clamp01((float)$score);
            $n = ((int)$cur['n']) + 1;
            $prev = isset($cur['avg_score']) && is_numeric($cur['avg_score']) ? (float)$cur['avg_score'] : 0.5;
            $cur['avg_score'] = $prev + ($sc - $prev) / $n;
            $cur['n'] = $n;
        }
        $cur['updated_at'] = self::nowIso();
        $model['stats'][$key] = $cur;

        $model['history'] = $model['history'] ?? [];
        $model['history'][] = [
            'at' => self::nowIso(),
            'key' => $key,
            'outcome' => ($out === 'success' || $out === 'failed') ? $out : 'unknown',
            'score' => is_numeric($score) ? self::clamp01((float)$score) : null,
            'notes' => $notes ? substr((string)$notes, 0, 220) : null,
        ];

        self::savePersonalityModel($model);
        return ['key' => $key, 'stats' => $cur];
    }
}
