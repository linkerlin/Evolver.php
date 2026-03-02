<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Mutation object construction with safety constraints.
 * PHP port of mutation.js from EvoMap/evolver.
 */
final class Mutation
{
    /** @var string[] Opportunity signals that indicate a chance to innovate */
    public const OPPORTUNITY_SIGNALS = [
        'user_feature_request',
        'user_improvement_suggestion',
        'perf_bottleneck',
        'capability_gap',
        'stable_success_plateau',
        'external_opportunity',
        'issue_already_resolved',
        'openclaw_self_healed',
        'empty_cycle_loop_detected',
    ];

    /**
     * Clamp a value to the [0, 1] range.
     */
    public static function clamp01(mixed $x): float
    {
        $n = self::toNumber($x);
        if (!is_finite($n)) {
            return 0.0;
        }
        return max(0.0, min(1.0, $n));
    }

    /**
     * Convert value to float safely.
     */
    private static function toNumber(mixed $x): float
    {
        return is_numeric($x) ? (float)$x : NAN;
    }

    /**
     * Get current timestamp in milliseconds.
     */
    public static function nowTsMs(): int
    {
        return (int)(microtime(true) * 1000);
    }

    /**
     * Return unique strings from a list.
     *
     * @param array<mixed> $list
     * @return string[]
     */
    public static function uniqStrings(array $list): array
    {
        $out = [];
        $seen = [];
        foreach ($list as $x) {
            $s = trim((string)($x ?? ''));
            if ($s === '') {
                continue;
            }
            $key = strtolower($s);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Check if signals contain error-like patterns.
     *
     * @param array<mixed> $signals
     */
    public static function hasErrorishSignal(array $signals): bool
    {
        $list = array_map(fn($s) => (string)($s ?? ''), $signals);
        if (in_array('issue_already_resolved', $list, true) || in_array('openclaw_self_healed', $list, true)) {
            return false;
        }
        if (in_array('log_error', $list, true)) {
            return true;
        }
        foreach ($list as $s) {
            if (str_starts_with($s, 'errsig:') || str_starts_with($s, 'errsig_norm:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if signals contain opportunity patterns.
     *
     * @param array<mixed> $signals
     */
    public static function hasOpportunitySignal(array $signals): bool
    {
        $list = array_map(fn($s) => (string)($s ?? ''), $signals);
        foreach (self::OPPORTUNITY_SIGNALS as $name) {
            if (in_array($name, $list, true)) {
                return true;
            }
            foreach ($list as $s) {
                if (str_starts_with($s, $name . ':')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Determine mutation category from context.
     *
     * @param array<mixed> $signals
     */
    public static function mutationCategoryFromContext(array $signals, bool $driftEnabled = false): string
    {
        if (self::hasErrorishSignal($signals)) {
            return 'repair';
        }
        if ($driftEnabled) {
            return 'innovate';
        }
        // Auto-innovate: opportunity signals present and no errors
        if (self::hasOpportunitySignal($signals)) {
            return 'innovate';
        }
        // Consult strategy preset
        try {
            $strategy = StrategyConfig::resolve();
            if (isset($strategy['innovate']) && is_numeric($strategy['innovate']) && $strategy['innovate'] >= 0.5) {
                return 'innovate';
            }
        } catch (\Throwable $e) {
            // Ignore strategy errors
        }
        return 'optimize';
    }

    /**
     * Get expected effect description from category.
     */
    public static function expectedEffectFromCategory(string $category): string
    {
        return match ($category) {
            'repair' => 'reduce runtime errors, increase stability, and lower failure rate',
            'optimize' => 'improve success rate and reduce repeated operational cost',
            'innovate' => 'explore new strategy combinations to escape local optimum',
            default => 'improve robustness and success probability',
        };
    }

    /**
     * Get target from selected gene.
     */
    public static function targetFromGene(?array $selectedGene): string
    {
        if ($selectedGene && isset($selectedGene['id'])) {
            return 'gene:' . (string)$selectedGene['id'];
        }
        return 'behavior:protocol';
    }

    /**
     * Check if personality state is high-risk.
     */
    public static function isHighRiskPersonality(?array $p): bool
    {
        if ($p === null) {
            return false;
        }
        $rigor = isset($p['rigor']) && is_numeric($p['rigor']) ? (float)$p['rigor'] : null;
        $riskTol = isset($p['risk_tolerance']) && is_numeric($p['risk_tolerance']) ? (float)$p['risk_tolerance'] : null;
        if ($rigor !== null && $rigor < 0.5) {
            return true;
        }
        if ($riskTol !== null && $riskTol > 0.6) {
            return true;
        }
        return false;
    }

    /**
     * Check if high-risk mutation is allowed given personality.
     */
    public static function isHighRiskMutationAllowed(?array $personalityState): bool
    {
        $rigor = $personalityState && isset($personalityState['rigor']) && is_numeric($personalityState['rigor'])
            ? (float)$personalityState['rigor']
            : 0;
        $riskTol = $personalityState && isset($personalityState['risk_tolerance']) && is_numeric($personalityState['risk_tolerance'])
            ? (float)$personalityState['risk_tolerance']
            : 1;
        return $rigor >= 0.6 && $riskTol <= 0.5;
    }

    /**
     * Build a mutation object with safety constraints.
     *
     * @param array<mixed> $signals
     * @param array<string, mixed>|null $selectedGene
     * @param array<string, mixed>|null $personalityState
     */
    public static function buildMutation(
        array $signals = [],
        ?array $selectedGene = null,
        bool $driftEnabled = false,
        ?array $personalityState = null,
        bool $allowHighRisk = false,
        ?string $target = null,
        ?string $expectedEffect = null
    ): array {
        $ts = self::nowTsMs();
        $category = self::mutationCategoryFromContext($signals, $driftEnabled);
        $triggerSignals = self::uniqStrings($signals);

        $base = [
            'type' => 'Mutation',
            'id' => "mut_{$ts}",
            'category' => $category,
            'trigger_signals' => $triggerSignals,
            'target' => $target ?? self::targetFromGene($selectedGene),
            'expected_effect' => $expectedEffect ?? self::expectedEffectFromCategory($category),
            'risk_level' => 'low',
        ];

        // Default risk assignment: innovate is medium; others low.
        if ($category === 'innovate') {
            $base['risk_level'] = 'medium';
        }

        // Optional high-risk escalation
        if ($allowHighRisk && $category === 'innovate') {
            $base['risk_level'] = 'high';
        }

        // Safety constraints (hard)
        $highRiskPersonality = self::isHighRiskPersonality($personalityState);
        if ($base['category'] === 'innovate' && $highRiskPersonality) {
            $base['category'] = 'optimize';
            $base['expected_effect'] = 'safety downgrade: optimize under high-risk personality (avoid innovate+high-risk combo)';
            $base['risk_level'] = 'low';
            $base['trigger_signals'] = self::uniqStrings(array_merge($base['trigger_signals'], ['safety:avoid_innovate_with_high_risk_personality']));
        }

        if ($base['risk_level'] === 'high' && !self::isHighRiskMutationAllowed($personalityState)) {
            $base['risk_level'] = 'medium';
            $base['trigger_signals'] = self::uniqStrings(array_merge($base['trigger_signals'], ['safety:downgrade_high_risk']));
        }

        return $base;
    }

    /**
     * Validate a mutation object.
     */
    public static function isValidMutation(mixed $obj): bool
    {
        if (!is_array($obj)) {
            return false;
        }
        if (($obj['type'] ?? null) !== 'Mutation') {
            return false;
        }
        if (!is_string($obj['id'] ?? null) || $obj['id'] === '') {
            return false;
        }
        if (!in_array($obj['category'] ?? null, ['repair', 'optimize', 'innovate'], true)) {
            return false;
        }
        if (!is_array($obj['trigger_signals'] ?? null)) {
            return false;
        }
        if (!is_string($obj['target'] ?? null) || $obj['target'] === '') {
            return false;
        }
        if (!is_string($obj['expected_effect'] ?? null) || $obj['expected_effect'] === '') {
            return false;
        }
        if (!in_array($obj['risk_level'] ?? null, ['low', 'medium', 'high'], true)) {
            return false;
        }
        return true;
    }

    /**
     * Normalize a mutation object.
     */
    public static function normalizeMutation(mixed $obj): array
    {
        $m = is_array($obj) ? $obj : [];
        $category = in_array($m['category'] ?? null, ['repair', 'optimize', 'innovate'], true)
            ? (string)$m['category']
            : 'optimize';

        return [
            'type' => 'Mutation',
            'id' => is_string($m['id'] ?? null) && $m['id'] !== ''
                ? $m['id']
                : 'mut_' . self::nowTsMs(),
            'category' => $category,
            'trigger_signals' => self::uniqStrings($m['trigger_signals'] ?? []),
            'target' => is_string($m['target'] ?? null) && $m['target'] !== ''
                ? $m['target']
                : 'behavior:protocol',
            'expected_effect' => is_string($m['expected_effect'] ?? null) && $m['expected_effect'] !== ''
                ? $m['expected_effect']
                : self::expectedEffectFromCategory($category),
            'risk_level' => in_array($m['risk_level'] ?? null, ['low', 'medium', 'high'], true)
                ? (string)$m['risk_level']
                : 'low',
        ];
    }
}
