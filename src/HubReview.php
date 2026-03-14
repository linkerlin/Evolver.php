<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Hub Asset Review - submit usage-verified reviews after solidify.
 *
 * When an evolution cycle reuses a Hub asset (source_type = 'reused' or 'reference'),
 * we submit a review to POST /a2a/assets/:assetId/reviews after solidify completes.
 * Rating is derived from outcome: success -> 4-5, failure -> 1-2.
 * Reviews are non-blocking; errors never affect the solidify result.
 *
 * Ported from evolver/src/gep/hubReview.js
 */
final class HubReview
{
    private const REVIEW_HISTORY_MAX_ENTRIES = 500;
    private const REQUEST_TIMEOUT = 10;

    private GepA2AProtocol $protocol;
    private string $hubUrl;

    public function __construct(?GepA2AProtocol $protocol = null, ?string $hubUrl = null)
    {
        $this->protocol = $protocol ?? new GepA2AProtocol();
        if ($hubUrl !== null) {
            $this->hubUrl = rtrim($hubUrl, '/');
        } else {
            $this->hubUrl = rtrim(getenv('A2A_HUB_URL') ?: getenv('EVOMAP_HUB_URL') ?: '', '/');
        }
    }

    /**
     * Submit a Hub review for a reused asset.
     *
     * @param array $params {
     *   reusedAssetId: string,
     *   sourceType: string ('reused' | 'reference'),
     *   outcome: array{status: string, score?: float},
     *   gene?: array,
     *   signals?: string[],
     *   blast?: array{files: int, lines: int},
     *   constraintCheck?: array{violations: array},
     *   runId?: string
     * }
     * @return array{submitted: bool, reason?: string, rating?: int, asset_id?: string, error?: string}
     */
    public function submit(array $params): array
    {
        if (empty($this->hubUrl)) {
            return ['submitted' => false, 'reason' => 'no_hub_url'];
        }

        $reusedAssetId = $params['reusedAssetId'] ?? null;
        $sourceType = $params['sourceType'] ?? null;
        $outcome = $params['outcome'] ?? null;
        $gene = $params['gene'] ?? null;
        $signals = $params['signals'] ?? [];
        $blast = $params['blast'] ?? null;
        $constraintCheck = $params['constraintCheck'] ?? null;
        $runId = $params['runId'] ?? null;

        if (!is_string($reusedAssetId) || $reusedAssetId === '') {
            return ['submitted' => false, 'reason' => 'no_reused_asset_id'];
        }

        if ($sourceType !== 'reused' && $sourceType !== 'reference') {
            return ['submitted' => false, 'reason' => 'not_hub_sourced'];
        }

        if ($this->alreadyReviewed($reusedAssetId)) {
            return ['submitted' => false, 'reason' => 'already_reviewed'];
        }

        $rating = $this->deriveRating($outcome, $constraintCheck);
        $content = $this->buildContent($outcome, $gene, $signals, $blast, $sourceType);
        $senderId = $this->protocol->getNodeId();

        $endpoint = $this->hubUrl . '/a2a/assets/' . urlencode($reusedAssetId) . '/reviews';

        try {
            $response = $this->httpPost($endpoint, [
                'sender_id' => $senderId,
                'rating' => $rating,
                'content' => $content,
            ]);

            if ($response['status'] >= 200 && $response['status'] < 300) {
                $this->markReviewed($reusedAssetId, $rating, true);

                $this->logAssetCall([
                    'run_id' => $runId,
                    'action' => 'hub_review_submitted',
                    'asset_id' => $reusedAssetId,
                    'extra' => ['rating' => $rating, 'outcome_status' => $outcome['status'] ?? null],
                ]);

                return ['submitted' => true, 'rating' => $rating, 'asset_id' => $reusedAssetId];
            }

            $errCode = $response['body']['error'] ?? $response['body']['code'] ?? ('http_' . $response['status']);

            if ($errCode === 'already_reviewed') {
                $this->markReviewed($reusedAssetId, $rating, false);
            }

            $this->logAssetCall([
                'run_id' => $runId,
                'action' => 'hub_review_rejected',
                'asset_id' => $reusedAssetId,
                'extra' => ['rating' => $rating, 'error' => $errCode],
            ]);

            return ['submitted' => false, 'reason' => $errCode, 'rating' => $rating];
        } catch (\Throwable $e) {
            $this->logAssetCall([
                'run_id' => $runId,
                'action' => 'hub_review_failed',
                'asset_id' => $reusedAssetId,
                'extra' => ['rating' => $rating, 'reason' => 'fetch_error', 'error' => $e->getMessage()],
            ]);

            return ['submitted' => false, 'reason' => 'fetch_error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Derive rating from outcome and constraint check.
     *
     * @param array|null $outcome
     * @param array|null $constraintCheck
     * @return int Rating 1-5
     */
    public function deriveRating(?array $outcome, ?array $constraintCheck): int
    {
        if ($outcome !== null && ($outcome['status'] ?? null) === 'success') {
            $score = (float)($outcome['score'] ?? 0);
            return $score >= 0.85 ? 5 : 4;
        }

        $hasConstraintViolation = $constraintCheck !== null
            && isset($constraintCheck['violations'])
            && is_array($constraintCheck['violations'])
            && count($constraintCheck['violations']) > 0;

        return $hasConstraintViolation ? 1 : 2;
    }

    /**
     * Build review content string.
     *
     * @param array|null $outcome
     * @param array|null $gene
     * @param array $signals
     * @param array|null $blast
     * @param string|null $sourceType
     * @return string
     */
    public function buildContent(?array $outcome, ?array $gene, array $signals, ?array $blast, ?string $sourceType): string
    {
        $parts = [];

        $status = $outcome['status'] ?? 'unknown';
        $score = isset($outcome['score']) && is_numeric($outcome['score'])
            ? number_format((float)$outcome['score'], 2)
            : '?';

        $parts[] = 'Outcome: ' . $status . ' (score: ' . $score . ')';
        $parts[] = 'Reuse mode: ' . ($sourceType ?? 'unknown');

        if ($gene !== null && isset($gene['id'])) {
            $parts[] = 'Gene: ' . $gene['id'] . ' (' . ($gene['category'] ?? 'unknown') . ')';
        }

        if (!empty($signals)) {
            $parts[] = 'Signals: ' . implode(', ', array_slice($signals, 0, 6));
        }

        if ($blast !== null) {
            $parts[] = 'Blast radius: ' . ($blast['files'] ?? 0) . ' file(s), ' . ($blast['lines'] ?? 0) . ' line(s)';
        }

        if ($status === 'success') {
            $parts[] = 'The fetched asset was successfully applied and solidified.';
        } else {
            $parts[] = 'The fetched asset did not lead to a successful evolution cycle.';
        }

        return substr(implode("\n", $parts), 0, 2000);
    }

    /**
     * Get the history file path.
     */
    public static function getHistoryFilePath(): string
    {
        return Paths::getEvolutionDir() . '/hub_review_history.json';
    }

    /**
     * Load review history from file.
     *
     * @return array<string, array{at: int, rating: int, success: bool}>
     */
    private function loadHistory(): array
    {
        $file = self::getHistoryFilePath();
        if (!file_exists($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save review history to file.
     *
     * @param array $history
     */
    private function saveHistory(array $history): void
    {
        $file = self::getHistoryFilePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Trim old entries if exceeding max
        $keys = array_keys($history);
        if (count($keys) > self::REVIEW_HISTORY_MAX_ENTRIES) {
            $sorted = array_map(function ($k) use ($history) {
                return ['k' => $k, 't' => $history[$k]['at'] ?? 0];
            }, $keys);
            usort($sorted, function ($a, $b) {
                return $a['t'] <=> $b['t'];
            });

            $toRemove = array_slice($sorted, 0, count($keys) - self::REVIEW_HISTORY_MAX_ENTRIES);
            foreach ($toRemove as $entry) {
                unset($history[$entry['k']]);
            }
        }

        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        rename($tmp, $file);
    }

    /**
     * Check if an asset has already been reviewed.
     */
    private function alreadyReviewed(string $assetId): bool
    {
        $history = $this->loadHistory();
        return isset($history[$assetId]);
    }

    /**
     * Mark an asset as reviewed.
     */
    private function markReviewed(string $assetId, int $rating, bool $success): void
    {
        $history = $this->loadHistory();
        $history[$assetId] = [
            'at' => time() * 1000, // Use milliseconds to match JS
            'rating' => $rating,
            'success' => $success,
        ];
        $this->saveHistory($history);
    }

    /**
     * Perform HTTP POST request.
     *
     * @param string $url
     * @param array $data
     * @return array{status: int, body: array}
     */
    private function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException("Failed to initialize curl");
        }

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $this->buildAuthHeaders()
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            throw new \RuntimeException("HTTP error: " . $error);
        }

        $body = [];
        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return ['status' => (int)$httpCode, 'body' => $body];
    }

    /**
     * Build authentication headers.
     */
    private function buildAuthHeaders(): array
    {
        $headers = [];
        $secret = $this->protocol->getHubNodeSecret();
        if ($secret) {
            $headers[] = 'Authorization: Bearer ' . $secret;
        }
        return $headers;
    }

    /**
     * Log asset call to JSONL file (non-blocking, non-fatal).
     *
     * @param array $entry
     */
    private function logAssetCall(array $entry): void
    {
        try {
            $logPath = Paths::getEvolutionDir() . '/asset_call_log.jsonl';
            $dir = dirname($logPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $record = array_merge(['timestamp' => date('c')], $entry);
            file_put_contents($logPath, json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
            // Non-fatal: never block evolution for logging failure
        }
    }

    /**
     * Get the configured hub URL.
     */
    public function getHubUrl(): string
    {
        return $this->hubUrl;
    }
}
