<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Hub Search-First Evolution: query evomap-hub for reusable solutions.
 * PHP port of hubSearch.js from EvoMap/evolver.
 *
 * Flow: extractSignals() -> hubSearch(signals) -> if hit: reuse; if miss: normal evolve
 * Two modes: direct (skip local reasoning) | reference (inject into prompt as strong hint)
 */
final class HubSearch
{
    private const DEFAULT_MIN_REUSE_SCORE = 0.72;
    private const DEFAULT_REUSE_MODE = 'reference'; // 'direct' | 'reference'
    private const DEFAULT_TIMEOUT_MS = 8000;
    private const DEFAULT_LIMIT = 5;

    /**
     * Get the hub URL from environment.
     */
    public static function getHubUrl(): string
    {
        $url = getenv('A2A_HUB_URL') ?: '';
        return rtrim($url, '/');
    }

    /**
     * Get the reuse mode from environment.
     */
    public static function getReuseMode(): string
    {
        $m = strtolower((string)(getenv('EVOLVER_REUSE_MODE') ?: self::DEFAULT_REUSE_MODE));
        return $m === 'direct' ? 'direct' : 'reference';
    }

    /**
     * Get the minimum reuse score from environment.
     */
    public static function getMinReuseScore(): float
    {
        $n = getenv('EVOLVER_MIN_REUSE_SCORE');
        if (is_numeric($n) && (float)$n > 0) {
            return (float)$n;
        }
        return self::DEFAULT_MIN_REUSE_SCORE;
    }

    /**
     * Score a hub asset for local reuse quality.
     * rank = confidence * max(success_streak, 1) * (reputation / 100)
     */
    public static function scoreHubResult(array $asset): float
    {
        $confidence = isset($asset['confidence']) && is_numeric($asset['confidence'])
            ? (float)$asset['confidence']
            : 0;
        $streak = isset($asset['success_streak']) && is_numeric($asset['success_streak'])
            ? max((float)$asset['success_streak'], 1)
            : 1;
        // Reputation is included in asset from hub ranked endpoint; default 50 if missing
        $reputation = isset($asset['reputation_score']) && is_numeric($asset['reputation_score'])
            ? (float)$asset['reputation_score']
            : 50;
        return $confidence * $streak * ($reputation / 100);
    }

    /**
     * Pick the best matching asset above the threshold.
     * Returns ['match' => array, 'score' => float, 'mode' => string] or null if nothing qualifies.
     *
     * @param array<mixed> $results
     */
    public static function pickBestMatch(array $results, float $threshold): ?array
    {
        if (empty($results)) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($results as $asset) {
            // Only consider promoted assets
            if (isset($asset['status']) && $asset['status'] !== 'promoted') {
                continue;
            }
            $s = self::scoreHubResult($asset);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $asset;
            }
        }

        if ($best === null || $bestScore < $threshold) {
            return null;
        }

        return [
            'match' => $best,
            'score' => round($bestScore, 3),
            'mode' => self::getReuseMode(),
        ];
    }

    /**
     * Search the hub for reusable capsules matching the given signals.
     * Returns ['hit' => true, 'match' => array, 'score' => float, 'mode' => string, ...]
     * or ['hit' => false, 'reason' => string, ...].
     *
     * @param array<mixed> $signals
     * @param array<string, mixed> $opts Optional: threshold, limit, timeoutMs
     * @return array<string, mixed>
     */
    public static function hubSearch(array $signals, array $opts = []): array
    {
        $hubUrl = self::getHubUrl();
        if ($hubUrl === '') {
            return ['hit' => false, 'reason' => 'no_hub_url'];
        }

        $signalList = array_filter($signals, fn($s) => !empty($s));
        if (empty($signalList)) {
            return ['hit' => false, 'reason' => 'no_signals'];
        }

        $threshold = isset($opts['threshold']) && is_numeric($opts['threshold'])
            ? (float)$opts['threshold']
            : self::getMinReuseScore();
        $limit = isset($opts['limit']) && is_numeric($opts['limit'])
            ? (int)$opts['limit']
            : self::DEFAULT_LIMIT;
        $timeout = isset($opts['timeoutMs']) && is_numeric($opts['timeoutMs'])
            ? (int)$opts['timeoutMs']
            : self::DEFAULT_TIMEOUT_MS;

        try {
            $params = http_build_query([
                'signals' => implode(',', $signalList),
                'status' => 'promoted',
                'limit' => $limit,
            ]);

            $url = "{$hubUrl}/a2a/assets/search?{$params}";

            $ch = curl_init($url);
            if ($ch === false) {
                return ['hit' => false, 'reason' => 'curl_init_failed'];
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => $timeout,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['hit' => false, 'reason' => 'curl_error', 'error' => $error];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                return ['hit' => false, 'reason' => "hub_http_{$httpCode}"];
            }

            $data = json_decode($response ?: '{}', true);
            if (!is_array($data)) {
                return ['hit' => false, 'reason' => 'invalid_json_response'];
            }

            $assets = $data['assets'] ?? [];
            if (!is_array($assets) || empty($assets)) {
                return ['hit' => false, 'reason' => 'no_results'];
            }

            $pick = self::pickBestMatch($assets, $threshold);
            if ($pick === null) {
                return ['hit' => false, 'reason' => 'below_threshold', 'candidates' => count($assets)];
            }

            error_log("[HubSearch] Hit: " . ($pick['match']['asset_id'] ?? $pick['match']['local_id'] ?? 'unknown') .
                " (score={$pick['score']}, mode={$pick['mode']})");

            return [
                'hit' => true,
                'match' => $pick['match'],
                'score' => $pick['score'],
                'mode' => $pick['mode'],
                'asset_id' => $pick['match']['asset_id'] ?? null,
                'source_node_id' => $pick['match']['source_node_id'] ?? null,
                'chain_id' => $pick['match']['chain_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            // Hub unreachable is non-fatal; fall through to normal evolve
            error_log("[HubSearch] Failed (non-fatal): " . $e->getMessage());
            return ['hit' => false, 'reason' => 'fetch_error', 'error' => $e->getMessage()];
        }
    }
}
