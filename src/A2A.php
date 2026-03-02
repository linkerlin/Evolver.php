<?php

declare(strict_types=1);

namespace Evolver;

/**
 * A2A (Agent-to-Agent) asset handling.
 * Handles blast radius checks, confidence lowering, broadcast eligibility.
 *
 * Ported from evolver/src/gep/a2a.js
 */
final class A2A
{
    private GepAssetStore $assetStore;

    public function __construct(?GepAssetStore $assetStore = null)
    {
        $this->assetStore = $assetStore ?? new GepAssetStore();
    }

    /**
     * Check if an object is an allowed A2A asset type.
     */
    public static function isAllowedA2AAsset(mixed $obj): bool
    {
        if (!is_array($obj) && !is_object($obj)) {
            return false;
        }
        $arr = is_object($obj) ? (array) $obj : $obj;
        $type = $arr['type'] ?? null;
        return in_array($type, ['Gene', 'Capsule', 'EvolutionEvent'], true);
    }

    /**
     * Safe number conversion with fallback.
     */
    private static function safeNumber(mixed $x, ?float $fallback = null): ?float
    {
        $n = is_numeric($x) ? (float) $x : null;
        return ($n !== null && is_finite($n)) ? $n : $fallback;
    }

    /**
     * Get blast radius limits from environment.
     */
    public static function getBlastRadiusLimits(): array
    {
        $maxFiles = self::safeNumber($_ENV['A2A_MAX_FILES'] ?? null, 5.0);
        $maxLines = self::safeNumber($_ENV['A2A_MAX_LINES'] ?? null, 200.0);
        return [
            'maxFiles' => is_finite($maxFiles) ? (int) $maxFiles : 5,
            'maxLines' => is_finite($maxLines) ? (int) $maxLines : 200,
        ];
    }

    /**
     * Check if blast radius is within safe limits.
     */
    public static function isBlastRadiusSafe(?array $blastRadius): bool
    {
        $limits = self::getBlastRadiusLimits();
        $files = 0;
        $lines = 0;

        if ($blastRadius !== null) {
            $files = isset($blastRadius['files']) && is_numeric($blastRadius['files'])
                ? max(0, (int) $blastRadius['files'])
                : 0;
            $lines = isset($blastRadius['lines']) && is_numeric($blastRadius['lines'])
                ? max(0, (int) $blastRadius['lines'])
                : 0;
        }

        return $files <= $limits['maxFiles'] && $lines <= $limits['maxLines'];
    }

    /**
     * Clamp a number to [0, 1] range.
     */
    private static function clamp01(mixed $n): float
    {
        $x = is_numeric($n) ? (float) $n : 0;
        if (!is_finite($x)) {
            return 0;
        }
        return max(0, min(1, $x));
    }

    /**
     * Lower confidence for external candidate assets.
     */
    public static function lowerConfidence(array|object $asset, array $opts = []): ?array
    {
        $factor = isset($opts['factor']) && is_numeric($opts['factor'])
            ? (float) $opts['factor']
            : 0.6;
        $receivedFrom = $opts['source'] ?? 'external';
        $receivedAt = $opts['received_at'] ?? date('c');

        $cloned = is_object($asset) ? (array) $asset : $asset;

        if (!self::isAllowedA2AAsset($cloned)) {
            return null;
        }

        if (($cloned['type'] ?? null) === 'Capsule') {
            if (isset($cloned['confidence']) && is_numeric($cloned['confidence'])) {
                $cloned['confidence'] = self::clamp01((float) $cloned['confidence'] * $factor);
            }
        }

        if (!isset($cloned['a2a']) || !is_array($cloned['a2a'])) {
            $cloned['a2a'] = [];
        }
        $cloned['a2a']['status'] = 'external_candidate';
        $cloned['a2a']['source'] = $receivedFrom;
        $cloned['a2a']['received_at'] = $receivedAt;
        $cloned['a2a']['confidence_factor'] = $factor;

        if (!isset($cloned['schema_version'])) {
            $cloned['schema_version'] = ContentHash::SCHEMA_VERSION;
        }
        if (!isset($cloned['asset_id'])) {
            try {
                $cloned['asset_id'] = ContentHash::computeAssetId($cloned);
            } catch (\Throwable) {
            }
        }

        return $cloned;
    }

    /**
     * Read evolution events from asset store.
     */
    private function readEvolutionEvents(): array
    {
        $events = $this->assetStore->readAllEvents();
        return array_filter($events, fn($e) => isset($e['type']) && $e['type'] === 'EvolutionEvent');
    }

    /**
     * Compute consecutive success streak for a capsule.
     */
    public function computeCapsuleSuccessStreak(string $capsuleId, ?array $events = null): int
    {
        if (empty($capsuleId)) {
            return 0;
        }
        $list = $events ?? $this->readEvolutionEvents();
        $streak = 0;

        // Iterate in reverse
        for ($i = count($list) - 1; $i >= 0; $i--) {
            $ev = $list[$i];
            if (!isset($ev['type']) || $ev['type'] !== 'EvolutionEvent') {
                continue;
            }
            if (!isset($ev['capsule_id']) || (string) $ev['capsule_id'] !== $capsuleId) {
                continue;
            }
            $status = $ev['outcome']['status'] ?? 'unknown';
            if ($status === 'success') {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Check if a capsule is eligible for broadcast.
     */
    public function isCapsuleBroadcastEligible(array $capsule, array $opts = []): bool
    {
        if (($capsule['type'] ?? null) !== 'Capsule') {
            return false;
        }

        $score = $capsule['outcome']['score'] ?? null;
        if ($score === null || !is_numeric($score) || (float) $score < 0.7) {
            return false;
        }

        $blast = $capsule['blast_radius'] ?? $capsule['outcome']['blast_radius'] ?? null;
        if (!self::isBlastRadiusSafe($blast)) {
            return false;
        }

        $events = $opts['events'] ?? $this->readEvolutionEvents();
        $streak = $this->computeCapsuleSuccessStreak($capsule['id'] ?? '', $events);
        if ($streak < 2) {
            return false;
        }

        return true;
    }

    /**
     * Export capsules that are eligible for broadcast.
     */
    public function exportEligibleCapsules(array $params = []): array
    {
        $list = $params['capsules'] ?? [];
        $events = $params['events'] ?? $this->readEvolutionEvents();
        $eligible = [];

        foreach ($list as $c) {
            if ($this->isCapsuleBroadcastEligible($c, ['events' => $events])) {
                if (!isset($c['schema_version'])) {
                    $c['schema_version'] = ContentHash::SCHEMA_VERSION;
                }
                if (!isset($c['asset_id'])) {
                    try {
                        $c['asset_id'] = ContentHash::computeAssetId($c);
                    } catch (\Throwable) {
                    }
                }
                $eligible[] = $c;
            }
        }

        return $eligible;
    }

    /**
     * Check if a gene is eligible for broadcast.
     */
    public static function isGeneBroadcastEligible(array $gene): bool
    {
        if (($gene['type'] ?? null) !== 'Gene') {
            return false;
        }
        if (!isset($gene['id']) || !is_string($gene['id'])) {
            return false;
        }
        if (!isset($gene['strategy']) || !is_array($gene['strategy']) || count($gene['strategy']) === 0) {
            return false;
        }
        if (!isset($gene['validation']) || !is_array($gene['validation']) || count($gene['validation']) === 0) {
            return false;
        }
        return true;
    }

    /**
     * Export genes that are eligible for broadcast.
     */
    public static function exportEligibleGenes(array $params = []): array
    {
        $list = $params['genes'] ?? [];
        $eligible = [];

        foreach ($list as $g) {
            if (self::isGeneBroadcastEligible($g)) {
                if (!isset($g['schema_version'])) {
                    $g['schema_version'] = ContentHash::SCHEMA_VERSION;
                }
                if (!isset($g['asset_id'])) {
                    try {
                        $g['asset_id'] = ContentHash::computeAssetId($g);
                    } catch (\Throwable) {
                    }
                }
                $eligible[] = $g;
            }
        }

        return $eligible;
    }

    /**
     * Parse A2A input (JSON array, object, or NDJSON).
     */
    public static function parseA2AInput(string $text): array
    {
        $raw = trim($text);
        if (empty($raw)) {
            return [];
        }

        try {
            $maybe = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($maybe)) {
                return array_filter(array_map(
                    fn($item) => GepA2AProtocol::unwrapAssetFromMessage($item) ?? $item,
                    $maybe
                ));
            }
            if (is_array($maybe)) {
                $unwrapped = GepA2AProtocol::unwrapAssetFromMessage($maybe);
                return $unwrapped ? [$unwrapped] : [$maybe];
            }
        } catch (\JsonException) {
        }

        // Try NDJSON
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $items = [];
        foreach ($lines as $line) {
            try {
                $obj = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $uw = GepA2AProtocol::unwrapAssetFromMessage($obj);
                $items[] = $uw ?? $obj;
            } catch (\JsonException) {
                continue;
            }
        }

        return $items;
    }

    /**
     * Read text file if exists.
     */
    public static function readTextIfExists(?string $filePath): string
    {
        if (empty($filePath) || !file_exists($filePath)) {
            return '';
        }
        try {
            return file_get_contents($filePath) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }
}
