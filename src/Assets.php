<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Asset formatting utilities.
 *
 * Ported from evolver/src/gep/assets.js
 */
final class Assets
{
    /**
     * Format asset preview for prompt inclusion.
     * Handles stringified JSON, arrays, and error cases gracefully.
     */
    public static function formatAssetPreview(mixed $preview): string
    {
        if (empty($preview)) {
            return '(none)';
        }

        if (is_string($preview)) {
            try {
                $parsed = json_decode($preview, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($parsed) && count($parsed) > 0) {
                    return json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                }
                return $preview; // Keep as string if not array or empty
            } catch (\JsonException) {
                return $preview; // Keep as string if parse fails
            }
        }

        if (is_array($preview)) {
            return json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return '(none)';
    }

    /**
     * Validate and normalize an asset object.
     * Ensures schema version and ID are present.
     */
    public static function normalizeAsset(mixed $asset): mixed
    {
        if (!is_array($asset)) {
            return $asset;
        }

        if (!isset($asset['schema_version'])) {
            $asset['schema_version'] = ContentHash::SCHEMA_VERSION;
        }

        if (!isset($asset['asset_id'])) {
            try {
                $asset['asset_id'] = ContentHash::computeAssetId($asset);
            } catch (\Throwable) {
                // Keep asset_id unset if computation fails
            }
        }

        return $asset;
    }

    /**
     * Format multiple assets for preview.
     */
    public static function formatAssetsPreview(array $assets, int $maxChars = 2000): string
    {
        $lines = [];
        foreach ($assets as $asset) {
            $type = $asset['type'] ?? 'Unknown';
            $id = $asset['id'] ?? $asset['asset_id'] ?? 'no-id';
            $lines[] = "- [{$type}] {$id}";

            if (isset($asset['title'])) {
                $lines[] = "  Title: {$asset['title']}";
            }
            if (isset($asset['category'])) {
                $lines[] = "  Category: {$asset['category']}";
            }
        }

        $result = implode("\n", $lines);
        if (strlen($result) > $maxChars) {
            $result = substr($result, 0, $maxChars - 20) . "\n...[TRUNCATED]";
        }

        return $result;
    }
}
