<?php

declare(strict_types=1);

namespace Evolver;

/**
 * GDI (Genome Distribution Index) Calculator.
 * Provides independent GDI scoring and sorting capabilities for Genes and Capsules.
 *
 * GDI is a quality score (0.0 - 1.0) that measures how good an asset is.
 * Higher score = better quality asset.
 */
final class GdiCalculator
{
    private const WEIGHT_OUTCOME = 0.4;
    private const WEIGHT_CONFIDENCE = 0.3;
    private const WEIGHT_STREAK_MAX = 0.15;
    private const WEIGHT_PRECISION = 0.1;
    private const WEIGHT_CONTENT = 0.05;

    /**
     * Calculate GDI score for a Capsule.
     */
    public function computeCapsuleGdi(array $capsule): float
    {
        $score = 0.0;

        $outcomeScore = (float)($capsule['outcome']['score'] ?? $capsule['outcome_score'] ?? 0.5);
        $score += $outcomeScore * self::WEIGHT_OUTCOME;

        $confidence = (float)($capsule['confidence'] ?? 0.5);
        $score += $confidence * self::WEIGHT_CONFIDENCE;

        $streak = (int)($capsule['success_streak'] ?? 0);
        $score += min($streak * 0.05, self::WEIGHT_STREAK_MAX);

        $files = (int)($capsule['blast_radius']['files'] ?? $capsule['files'] ?? 1);
        $lines = (int)($capsule['blast_radius']['lines'] ?? $capsule['lines'] ?? 1);
        if ($files <= 5 && $lines <= 100) {
            $score += self::WEIGHT_PRECISION;
        }

        if (!empty($capsule['content'])) {
            $score += self::WEIGHT_CONTENT;
        }

        return min($score, 1.0);
    }

    /**
     * Calculate GDI score for a Gene.
     * Gene GDI is based on usage stats and associated capsules.
     */
    public function computeGeneGdi(array $gene, array $capsules = []): float
    {
        $score = 0.0;

        $usageCount = (int)($gene['usage_count'] ?? count($capsules));
        $score += min($usageCount * 0.02, 0.3);

        $successRate = $this->computeSuccessRate($capsules);
        $score += $successRate * 0.4;

        $avgStreak = $this->computeAverageStreak($capsules);
        $score += min($avgStreak * 0.05, 0.2);

        if (!empty($gene['constraints']['max_files'])) {
            $score += 0.1;
        }

        return min($score, 1.0);
    }

    /**
     * Sort capsules by GDI score (descending).
     */
    public function sortCapsulesByGdi(array $capsules, bool $descending = true): array
    {
        usort($capsules, function($a, $b) use ($descending) {
            $gdiA = $this->computeCapsuleGdi($a);
            $gdiB = $this->computeCapsuleGdi($b);
            return $descending ? ($gdiB <=> $gdiA) : ($gdiA <=> $gdiB);
        });
        return $capsules;
    }

    /**
     * Sort genes by GDI score (descending).
     */
    public function sortGenesByGdi(array $genes, array $capsulesMap = [], bool $descending = true): array
    {
        usort($genes, function($a, $b) use ($capsulesMap, $descending) {
            $gdiA = $this->computeGeneGdi($a, $capsulesMap[$a['id'] ?? ''] ?? []);
            $gdiB = $this->computeGeneGdi($b, $capsulesMap[$b['id'] ?? ''] ?? []);
            return $descending ? ($gdiB <=> $gdiA) : ($gdiA <=> $gdiB);
        });
        return $genes;
    }

    /**
     * Filter capsules by minimum GDI score.
     */
    public function filterCapsulesByMinGdi(array $capsules, float $minGdi): array
    {
        return array_values(array_filter($capsules, function($capsule) use ($minGdi) {
            return $this->computeCapsuleGdi($capsule) >= $minGdi;
        }));
    }

    /**
     * Filter genes by minimum GDI score.
     */
    public function filterGenesByMinGdi(array $genes, array $capsulesMap = [], float $minGdi = 0.0): array
    {
        return array_values(array_filter($genes, function($gene) use ($capsulesMap, $minGdi) {
            return $this->computeGeneGdi($gene, $capsulesMap[$gene['id'] ?? ''] ?? []) >= $minGdi;
        }));
    }

    /**
     * Add GDI scores to capsules (in-place).
     */
    public function annotateCapsulesWithGdi(array &$capsules): void
    {
        foreach ($capsules as &$capsule) {
            $capsule['_gdi'] = $this->computeCapsuleGdi($capsule);
        }
    }

    /**
     * Add GDI scores to genes (in-place).
     */
    public function annotateGenesWithGdi(array &$genes, array $capsulesMap = []): void
    {
        foreach ($genes as &$gene) {
            $gene['_gdi'] = $this->computeGeneGdi($gene, $capsulesMap[$gene['id'] ?? ''] ?? []);
        }
    }

    /**
     * Get top N capsules by GDI.
     */
    public function getTopCapsules(array $capsules, int $limit = 10): array
    {
        $sorted = $this->sortCapsulesByGdi($capsules);
        return array_slice($sorted, 0, $limit);
    }

    /**
     * Get top N genes by GDI.
     */
    public function getTopGenes(array $genes, array $capsulesMap = [], int $limit = 10): array
    {
        $sorted = $this->sortGenesByGdi($genes, $capsulesMap);
        return array_slice($sorted, 0, $limit);
    }

    /**
     * Compute success rate from capsules.
     */
    private function computeSuccessRate(array $capsules): float
    {
        if (empty($capsules)) {
            return 0.5;
        }

        $successCount = 0;
        foreach ($capsules as $capsule) {
            $status = $capsule['outcome']['status'] ?? $capsule['outcome_status'] ?? 'unknown';
            if ($status === 'success') {
                $successCount++;
            }
        }

        return $successCount / count($capsules);
    }

    /**
     * Compute average success streak from capsules.
     */
    private function computeAverageStreak(array $capsules): float
    {
        if (empty($capsules)) {
            return 0.0;
        }

        $totalStreak = 0;
        foreach ($capsules as $capsule) {
            $totalStreak += (int)($capsule['success_streak'] ?? 0);
        }

        return $totalStreak / count($capsules);
    }

    /**
     * Get GDI score category.
     */
    public function getGdiCategory(float $gdi): string
    {
        if ($gdi >= 0.8) {
            return 'excellent';
        }
        if ($gdi >= 0.6) {
            return 'good';
        }
        if ($gdi >= 0.4) {
            return 'average';
        }
        if ($gdi >= 0.2) {
            return 'poor';
        }
        return 'very_poor';
    }

    /**
     * Get GDI statistics for a collection.
     */
    public function getGdiStats(array $capsules): array
    {
        if (empty($capsules)) {
            return [
                'count' => 0,
                'average' => 0.0,
                'min' => 0.0,
                'max' => 0.0,
                'distribution' => [],
            ];
        }

        $scores = array_map(fn($c) => $this->computeCapsuleGdi($c), $capsules);

        return [
            'count' => count($capsules),
            'average' => array_sum($scores) / count($scores),
            'min' => min($scores),
            'max' => max($scores),
            'distribution' => [
                'excellent' => count(array_filter($scores, fn($s) => $s >= 0.8)),
                'good' => count(array_filter($scores, fn($s) => $s >= 0.6 && $s < 0.8)),
                'average' => count(array_filter($scores, fn($s) => $s >= 0.4 && $s < 0.6)),
                'poor' => count(array_filter($scores, fn($s) => $s >= 0.2 && $s < 0.4)),
                'very_poor' => count(array_filter($scores, fn($s) => $s < 0.2)),
            ],
        ];
    }
}
