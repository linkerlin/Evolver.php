<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Gene and capsule selection engine.
 * PHP port of selector.js from EvoMap/evolver.
 */
final class GeneSelector
{
    /**
     * Match a pattern (substring or /regex/) against a signal list.
     */
    public function matchPatternToSignals(string $pattern, array $signals): bool
    {
        if (empty($pattern) || empty($signals)) {
            return false;
        }

        // Check if pattern looks like a regex: /body/flags
        $isRegexLike = strlen($pattern) >= 2
            && str_starts_with($pattern, '/')
            && strrpos($pattern, '/') > 0;

        if ($isRegexLike) {
            $lastSlash = strrpos($pattern, '/');
            $body = substr($pattern, 1, $lastSlash - 1);
            $flags = substr($pattern, $lastSlash + 1);
            $phpFlags = '';
            if (str_contains($flags, 'i')) $phpFlags .= 'i';
            if (str_contains($flags, 'm')) $phpFlags .= 'm';
            $phpPattern = '/' . $body . '/' . $phpFlags;
            try {
                foreach ($signals as $sig) {
                    if (@preg_match($phpPattern, (string)$sig)) {
                        return true;
                    }
                }
                return false;
            } catch (\Throwable) {
                // Fallback to substring
            }
        }

        $needle = strtolower($pattern);
        foreach ($signals as $sig) {
            if (str_contains(strtolower((string)$sig), $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Score a gene against signals.
     */
    public function scoreGene(array $gene, array $signals): int
    {
        if (($gene['type'] ?? '') !== 'Gene') {
            return 0;
        }
        $patterns = $gene['signals_match'] ?? [];
        if (empty($patterns)) {
            return 0;
        }
        $score = 0;
        foreach ($patterns as $pat) {
            if ($this->matchPatternToSignals((string)$pat, $signals)) {
                $score++;
            }
        }
        return $score;
    }

    /**
     * Compute drift intensity based on effective population size.
     */
    private function computeDriftIntensity(array $opts): float
    {
        $driftEnabled = (bool)($opts['driftEnabled'] ?? false);
        $ne = $opts['effectivePopulationSize'] ?? $opts['genePoolSize'] ?? null;

        if ($driftEnabled) {
            return ($ne !== null && $ne > 1)
                ? min(1.0, 1.0 / sqrt((float)$ne) + 0.3)
                : 0.7;
        }

        if ($ne !== null && (float)$ne > 0) {
            return min(1.0, 1.0 / sqrt((float)$ne));
        }

        return 0.0;
    }

    /**
     * Select the best-matching gene from the pool.
     */
    public function selectGene(array $genes, array $signals, array $opts = []): array
    {
        $bannedGeneIds = $opts['bannedGeneIds'] ?? [];
        if (!($bannedGeneIds instanceof \SplFixedArray)) {
            $bannedGeneIds = array_flip((array)$bannedGeneIds);
        }
        $driftEnabled = (bool)($opts['driftEnabled'] ?? false);
        $preferredGeneId = $opts['preferredGeneId'] ?? null;

        $driftIntensity = $this->computeDriftIntensity([
            'driftEnabled' => $driftEnabled,
            'effectivePopulationSize' => $opts['effectivePopulationSize'] ?? null,
            'genePoolSize' => count($genes),
        ]);
        $useDrift = $driftEnabled || $driftIntensity > 0.15;

        $distilledPrefix = 'gene_distilled_';
        $distilledFactor = 0.8;

        // Score all genes
        $scored = [];
        foreach ($genes as $gene) {
            $score = $this->scoreGene($gene, $signals);
            if ($score > 0) {
                if (isset($gene['id']) && str_starts_with((string)$gene['id'], $distilledPrefix)) {
                    $score *= $distilledFactor;
                }
                $scored[] = ['gene' => $gene, 'score' => $score];
            }
        }

        if (empty($scored)) {
            return ['selected' => null, 'alternatives' => [], 'driftIntensity' => $driftIntensity];
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Memory graph preference
        if ($preferredGeneId !== null) {
            $preferredIdx = null;
            foreach ($scored as $idx => $item) {
                if (($item['gene']['id'] ?? '') === $preferredGeneId) {
                    $preferredIdx = $idx;
                    break;
                }
            }
            if ($preferredIdx !== null) {
                $preferred = $scored[$preferredIdx];
                $geneId = $preferred['gene']['id'] ?? '';
                $isBanned = is_array($bannedGeneIds)
                    ? isset($bannedGeneIds[$geneId])
                    : false;
                if ($useDrift || !$isBanned) {
                    $rest = array_filter($scored, fn($x, $i) => $i !== $preferredIdx, ARRAY_FILTER_USE_BOTH);
                    $filteredRest = $useDrift ? array_values($rest) :
                        array_values(array_filter($rest, fn($x) => !isset($bannedGeneIds[$x['gene']['id'] ?? ''])));
                    return [
                        'selected' => $preferred['gene'],
                        'alternatives' => array_slice(array_column($filteredRest, 'gene'), 0, 4),
                        'driftIntensity' => $driftIntensity,
                    ];
                }
            }
        }

        // Filter banned genes
        $filtered = $useDrift ? $scored :
            array_values(array_filter($scored, fn($x) => !isset($bannedGeneIds[$x['gene']['id'] ?? ''])));

        if (empty($filtered)) {
            return [
                'selected' => null,
                'alternatives' => array_slice(array_column($scored, 'gene'), 0, 4),
                'driftIntensity' => $driftIntensity,
            ];
        }

        // Stochastic selection under drift
        $selectedIdx = 0;
        if ($driftIntensity > 0 && count($filtered) > 1 && (mt_rand() / mt_getrandmax()) < $driftIntensity) {
            $topN = max(2, (int)ceil(count($filtered) * $driftIntensity));
            $topN = min($topN, count($filtered));
            $selectedIdx = mt_rand(0, $topN - 1);
        }

        $alternatives = [];
        foreach ($filtered as $i => $item) {
            if ($i !== $selectedIdx) {
                $alternatives[] = $item['gene'];
            }
        }

        return [
            'selected' => $filtered[$selectedIdx]['gene'],
            'alternatives' => array_slice($alternatives, 0, 4),
            'driftIntensity' => $driftIntensity,
        ];
    }

    /**
     * Select the best-matching capsule from the pool.
     */
    public function selectCapsule(array $capsules, array $signals): ?array
    {
        $scored = [];
        foreach ($capsules as $capsule) {
            $triggers = $capsule['trigger'] ?? [];
            if (!is_array($triggers)) {
                $triggers = [$triggers];
            }
            $score = 0;
            foreach ($triggers as $t) {
                if ($this->matchPatternToSignals((string)$t, $signals)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['capsule' => $capsule, 'score' => $score];
            }
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored[0]['capsule'];
    }

    /**
     * Compute signal overlap ratio between two signal arrays.
     */
    public function computeSignalOverlap(array $signalsA, array $signalsB): float
    {
        if (empty($signalsA) || empty($signalsB)) {
            return 0.0;
        }
        $setB = array_flip(array_map('strtolower', array_map('strval', $signalsB)));
        $hits = 0;
        foreach ($signalsA as $s) {
            if (isset($setB[strtolower((string)$s)])) {
                $hits++;
            }
        }
        return $hits / max(count($signalsA), 1);
    }

    /**
     * Compute banned gene IDs from failed capsules.
     */
    public function banGenesFromFailedCapsules(array $failedCapsules, array $signals, array $existingBans = []): array
    {
        $bans = array_flip($existingBans);
        $banThreshold = 2;
        $overlapMin = 0.6;

        $geneFailCounts = [];
        foreach ($failedCapsules as $fc) {
            if (empty($fc['gene'])) continue;
            $overlap = $this->computeSignalOverlap($signals, $fc['trigger'] ?? []);
            if ($overlap < $overlapMin) continue;
            $gid = (string)$fc['gene'];
            $geneFailCounts[$gid] = ($geneFailCounts[$gid] ?? 0) + 1;
        }

        foreach ($geneFailCounts as $gid => $count) {
            if ($count >= $banThreshold) {
                $bans[$gid] = true;
            }
        }

        return array_keys($bans);
    }

    /**
     * Select gene and capsule together, building a selector decision.
     */
    public function selectGeneAndCapsule(array $input): array
    {
        $genes = $input['genes'] ?? [];
        $capsules = $input['capsules'] ?? [];
        $signals = $input['signals'] ?? [];
        $memoryAdvice = $input['memoryAdvice'] ?? null;
        $driftEnabled = (bool)($input['driftEnabled'] ?? false);
        $failedCapsules = $input['failedCapsules'] ?? [];

        $bannedGeneIds = $memoryAdvice['bannedGeneIds'] ?? [];
        $preferredGeneId = $memoryAdvice['preferredGeneId'] ?? null;

        $effectiveBans = $this->banGenesFromFailedCapsules($failedCapsules, $signals, $bannedGeneIds);

        $geneResult = $this->selectGene($genes, $signals, [
            'bannedGeneIds' => $effectiveBans,
            'preferredGeneId' => $preferredGeneId,
            'driftEnabled' => $driftEnabled,
        ]);

        $selectedCapsule = $this->selectCapsule($capsules, $signals);

        $selector = $this->buildSelectorDecision([
            'gene' => $geneResult['selected'],
            'capsule' => $selectedCapsule,
            'signals' => $signals,
            'alternatives' => $geneResult['alternatives'],
            'memoryAdvice' => $memoryAdvice,
            'driftEnabled' => $driftEnabled,
            'driftIntensity' => $geneResult['driftIntensity'],
        ]);

        return [
            'selectedGene' => $geneResult['selected'],
            'capsuleCandidates' => $selectedCapsule ? [$selectedCapsule] : [],
            'selector' => $selector,
            'driftIntensity' => $geneResult['driftIntensity'],
        ];
    }

    /**
     * Build a selector decision object.
     */
    public function buildSelectorDecision(array $input): array
    {
        $gene = $input['gene'] ?? null;
        $capsule = $input['capsule'] ?? null;
        $signals = $input['signals'] ?? [];
        $alternatives = $input['alternatives'] ?? [];
        $memoryAdvice = $input['memoryAdvice'] ?? null;
        $driftEnabled = (bool)($input['driftEnabled'] ?? false);
        $driftIntensity = (float)($input['driftIntensity'] ?? 0.0);

        $reason = [];
        if ($gene) $reason[] = 'signals match gene.signals_match';
        if ($capsule) $reason[] = 'capsule trigger matches signals';
        if (!$gene) $reason[] = 'no matching gene found; new gene may be required';
        if (!empty($signals)) $reason[] = 'signals: ' . implode(', ', $signals);

        if (!empty($memoryAdvice['explanation'])) {
            $reason[] = 'memory_graph: ' . implode(' | ', (array)$memoryAdvice['explanation']);
        }
        if ($driftEnabled) {
            $reason[] = 'random_drift_override: true';
        }
        if ($driftIntensity > 0) {
            $reason[] = sprintf('drift_intensity: %.3f', $driftIntensity);
        }

        return [
            'selected' => $gene ? ($gene['id'] ?? null) : null,
            'reason' => $reason,
            'alternatives' => array_map(fn($g) => $g['id'] ?? null, $alternatives),
        ];
    }
}
