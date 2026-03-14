<?php

declare(strict_types=1);

namespace Evolver;

/**
 * 后馈演化核心指标（方案 E1.1）
 * 从近期 EvolutionEvent 计算 8 项指标，用于周报与决策。
 */
final class FeedbackMetrics
{
    /**
     * 从事件列表计算 8 项核心指标 + F2 反事实（按 selector_mode 分组成功率与相对基线提升）。
     *
     * @param array<int, array<string, mixed>> $events 近期事件（ chronological 或 reverse chronological 均可）
     * @param array{total: int, solidified: int, closure_rate: float}|null $runClosureStats D1 run→solidify 闭环统计，可选
     */
    public static function compute(array $events, ?array $runClosureStats = null): array
    {
        $n = count($events);
        if ($n === 0) {
            return self::emptyReport();
        }

        $withOutcomeSource = 0;
        $successCount = 0;
        $successWithValidation = 0;
        $totalCosts = [];
        $filesList = [];
        $linesList = [];
        $withAttribution = 0;
        $successCosts = [];

        foreach ($events as $ev) {
            $outcome = $ev['outcome'] ?? [];
            $status = $outcome['status'] ?? 'unknown';
            $source = $outcome['source'] ?? null;
            if ($source === 'solidify') {
                $withOutcomeSource++;
            }
            if ($status === 'success') {
                $successCount++;
                $successWithValidation++; // 有 solidify 的 success 即视为可复现
                $c = $ev['cost']['total_cost'] ?? null;
                if (is_numeric($c)) {
                    $successCosts[] = (float) $c;
                }
            }
            $cost = $ev['cost']['total_cost'] ?? null;
            if (is_numeric($cost)) {
                $totalCosts[] = (float) $cost;
            }
            $blast = $ev['blast_radius'] ?? [];
            $filesList[] = (int)($blast['files'] ?? 0);
            $linesList[] = (int)($blast['lines'] ?? 0);
            $att = $ev['attribution'] ?? [];
            if (!empty($att['decision_source']) || !empty($att['primary_cause'])) {
                $withAttribution++;
            }
        }

        $closureCoverage = $n > 0 ? $withOutcomeSource / $n : 0.0;
        $reproducibleSuccessRate = $n > 0 ? $successCount / $n : 0.0;
        $regressionRate = self::estimateRegressionRate($events);
        $avgFixSteps = self::estimateAvgFixSteps($events);
        sort($filesList);
        sort($linesList);
        $p50 = (int) floor((count($filesList) - 1) * 0.5);
        $p90 = (int) floor((count($filesList) - 1) * 0.9);
        if ($p50 < 0) {
            $p50 = 0;
        }
        if ($p90 < 0) {
            $p90 = 0;
        }
        $blastP50Files = $filesList[$p50] ?? 0;
        $blastP90Files = $filesList[$p90] ?? 0;
        $blastP50Lines = $linesList[$p50] ?? 0;
        $blastP90Lines = $linesList[$p90] ?? 0;

        $avgTotalCost = count($totalCosts) > 0 ? array_sum($totalCosts) / count($totalCosts) : 0.0;
        $totalCostsSorted = $totalCosts;
        sort($totalCostsSorted);
        $p90Idx = (int) floor((count($totalCostsSorted) - 1) * 0.9);
        if ($p90Idx < 0) {
            $p90Idx = 0;
        }
        $p90TotalCost = $totalCostsSorted[$p90Idx] ?? 0.0;

        $successPerUnitCost = 0.0;
        if (count($successCosts) > 0 && array_sum($successCosts) > 0) {
            $successPerUnitCost = count($successCosts) / array_sum($successCosts);
        }
        $attributionClarityRate = $n > 0 ? $withAttribution / $n : 0.0;

        $counterfactual = self::computeCounterfactual($events);
        $report = self::buildReport(
            $n,
            $closureCoverage,
            $reproducibleSuccessRate,
            $regressionRate,
            $avgFixSteps,
            $blastP50Files,
            $blastP90Files,
            $blastP50Lines,
            $blastP90Lines,
            $avgTotalCost,
            $p90TotalCost,
            $successPerUnitCost,
            $attributionClarityRate,
            $counterfactual,
            $runClosureStats
        );
        $result = [
            'closure_coverage' => round($closureCoverage, 4),
            'reproducible_success_rate' => round($reproducibleSuccessRate, 4),
            'regression_rate' => round($regressionRate, 4),
            'avg_fix_steps' => round($avgFixSteps, 2),
            'blast_radius_p50_files' => $blastP50Files,
            'blast_radius_p90_files' => $blastP90Files,
            'blast_radius_p50_lines' => $blastP50Lines,
            'blast_radius_p90_lines' => $blastP90Lines,
            'avg_total_cost' => round($avgTotalCost, 4),
            'p90_total_cost' => round($p90TotalCost, 4),
            'success_per_unit_cost' => round($successPerUnitCost, 4),
            'attribution_clarity_rate' => round($attributionClarityRate, 4),
            'event_count' => $n,
            'counterfactual' => $counterfactual,
            'report' => $report,
        ];
        if ($runClosureStats !== null) {
            $result['run_closure_rate'] = round($runClosureStats['closure_rate'], 4);
            $result['run_closure_total'] = $runClosureStats['total'];
            $result['run_closure_solidified'] = $runClosureStats['solidified'];
        }
        return $result;
    }

    /**
     * F2: 按 selector_mode 分组的成功率及相对随机/规则基线的提升。
     *
     * @param array<int, array<string, mixed>> $events
     * @return array{by_mode: array<string, array{count: int, success_rate: float}>, lift_vs_random: float|null, lift_vs_rule: float|null}
     */
    private static function computeCounterfactual(array $events): array
    {
        $byMode = [];
        foreach ($events as $ev) {
            $mode = $ev['selector_mode'] ?? 'learning';
            if (!isset($byMode[$mode])) {
                $byMode[$mode] = ['count' => 0, 'success' => 0];
            }
            $byMode[$mode]['count']++;
            if (($ev['outcome']['status'] ?? '') === 'success') {
                $byMode[$mode]['success']++;
            }
        }
        $out = ['by_mode' => [], 'lift_vs_random' => null, 'lift_vs_rule' => null];
        foreach ($byMode as $mode => $v) {
            $out['by_mode'][$mode] = [
                'count' => $v['count'],
                'success_rate' => $v['count'] > 0 ? $v['success'] / $v['count'] : 0.0,
            ];
        }
        $learningRate = $out['by_mode']['learning']['success_rate'] ?? 0.0;
        if (isset($out['by_mode']['random']['success_rate'])) {
            $out['lift_vs_random'] = $learningRate - $out['by_mode']['random']['success_rate'];
        }
        if (isset($out['by_mode']['rule']['success_rate'])) {
            $out['lift_vs_rule'] = $learningRate - $out['by_mode']['rule']['success_rate'];
        }
        return $out;
    }

    private static function estimateRegressionRate(array $events): float
    {
        $successSignals = [];
        $regressions = 0;
        $successCount = 0;
        foreach ($events as $ev) {
            $status = $ev['outcome']['status'] ?? '';
            $sigKey = self::signalKey($ev['signals'] ?? []);
            if ($status === 'success') {
                $successCount++;
                $successSignals[$sigKey] = ($successSignals[$sigKey] ?? 0) + 1;
            }
        }
        foreach ($successSignals as $count) {
            if ($count > 1) {
                $regressions += $count - 1;
            }
        }
        if ($successCount === 0) {
            return 0.0;
        }
        return min(1.0, $regressions / $successCount);
    }

    private static function estimateAvgFixSteps(array $events): float
    {
        $bySignalKey = [];
        foreach ($events as $ev) {
            $sigKey = self::signalKey($ev['signals'] ?? []);
            if (!isset($bySignalKey[$sigKey])) {
                $bySignalKey[$sigKey] = ['steps' => 0, 'resolved' => false];
            }
            $bySignalKey[$sigKey]['steps']++;
            if (($ev['outcome']['status'] ?? '') === 'success') {
                $bySignalKey[$sigKey]['resolved'] = true;
            }
        }
        $steps = [];
        foreach ($bySignalKey as $v) {
            if ($v['resolved'] && $v['steps'] > 0) {
                $steps[] = $v['steps'];
            }
        }
        if (count($steps) === 0) {
            return 0.0;
        }
        return array_sum($steps) / count($steps);
    }

    private static function signalKey(array $signals): string
    {
        $s = array_map('strval', $signals);
        sort($s);
        return implode('|', array_slice($s, 0, 5));
    }

    /**
     * @param array{by_mode: array<string, array{count: int, success_rate: float}>, lift_vs_random: float|null, lift_vs_rule: float|null} $counterfactual
     * @param array{total: int, solidified: int, closure_rate: float}|null $runClosureStats
     */
    private static function buildReport(
        int $n,
        float $closureCoverage,
        float $reproducibleSuccessRate,
        float $regressionRate,
        float $avgFixSteps,
        int $blastP50Files,
        int $blastP90Files,
        int $blastP50Lines,
        int $blastP90Lines,
        float $avgTotalCost,
        float $p90TotalCost,
        float $successPerUnitCost,
        float $attributionClarityRate,
        array $counterfactual = [],
        ?array $runClosureStats = null
    ): string {
        $lines = [
            "# 后馈演化周报（核心指标）",
            "事件数: {$n}",
            "",
            "| 指标 | 值 |",
            "|------|-----|",
            "| 闭环覆盖率 (outcome.source=solidify) | " . round($closureCoverage * 100, 1) . "% |",
            "| 可复现成功率 | " . round($reproducibleSuccessRate * 100, 1) . "% |",
            "| 回归率（同信号再次成功占比） | " . round($regressionRate * 100, 1) . "% |",
            "| 平均修复步数 | " . round($avgFixSteps, 1) . " |",
            "| 爆炸半径 P50 (files/lines) | {$blastP50Files} / {$blastP50Lines} |",
            "| 爆炸半径 P90 (files/lines) | {$blastP90Files} / {$blastP90Lines} |",
            "| 平均总代价 | " . round($avgTotalCost, 2) . " |",
            "| 总代价 P90 | " . round($p90TotalCost, 2) . " |",
            "| 单位代价成功率 | " . round($successPerUnitCost, 4) . " |",
            "| 归因清晰率 | " . round($attributionClarityRate * 100, 1) . "% |",
        ];
        if ($runClosureStats !== null) {
            $lines[] = "| run→solidify 闭环覆盖率 (D1) | " . round($runClosureStats['closure_rate'] * 100, 1) . "% ({$runClosureStats['solidified']}/{$runClosureStats['total']}) |";
        }
        if (!empty($counterfactual['by_mode'])) {
            $lines[] = "";
            $lines[] = "## 反事实（F2）按 selector_mode";
            foreach ($counterfactual['by_mode'] as $mode => $v) {
                $lines[] = "- **{$mode}**: 成功率 " . round($v['success_rate'] * 100, 1) . "% (n={$v['count']})";
            }
            if ($counterfactual['lift_vs_random'] !== null) {
                $lines[] = "- 相对随机基线提升: " . round($counterfactual['lift_vs_random'] * 100, 1) . "%";
            }
            if ($counterfactual['lift_vs_rule'] !== null) {
                $lines[] = "- 相对规则基线提升: " . round($counterfactual['lift_vs_rule'] * 100, 1) . "%";
            }
        }
        return implode("\n", $lines);
    }

    private static function emptyReport(): array
    {
        return [
            'closure_coverage' => 0.0,
            'reproducible_success_rate' => 0.0,
            'regression_rate' => 0.0,
            'avg_fix_steps' => 0.0,
            'blast_radius_p50_files' => 0,
            'blast_radius_p90_files' => 0,
            'blast_radius_p50_lines' => 0,
            'blast_radius_p90_lines' => 0,
            'avg_total_cost' => 0.0,
            'p90_total_cost' => 0.0,
            'success_per_unit_cost' => 0.0,
            'attribution_clarity_rate' => 0.0,
            'event_count' => 0,
            'counterfactual' => ['by_mode' => [], 'lift_vs_random' => null, 'lift_vs_rule' => null],
            'report' => "# 后馈演化周报\n无近期事件，无法计算指标。",
        ];
    }
}
