<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Content-addressable hashing for GEP assets.
 * Provides canonical JSON serialization and SHA-256 based asset IDs.
 * This enables deduplication, tamper detection, and cross-node consistency.
 * 
 * PHP port of contentHash.js from EvoMap/evolver.
 */
final class ContentHash
{
    /** Schema version for all GEP asset types */
    public const SCHEMA_VERSION = '1.6.0';

    /**
     * Canonical JSON: deterministic serialization with sorted keys at all levels.
     * Arrays preserve order; non-finite numbers become null; undefined/null becomes null.
     * 
     * @param mixed $obj
     * @return string
     */
    public static function canonicalize(mixed $obj): string
    {
        if ($obj === null) {
            return 'null';
        }

        if (is_bool($obj)) {
            return $obj ? 'true' : 'false';
        }

        if (is_int($obj) || is_float($obj)) {
            if (!is_finite($obj)) {
                return 'null';
            }
            return (string) $obj;
        }

        if (is_string($obj)) {
            return json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_array($obj)) {
            // Check if it's a sequential array (list) or associative array (map)
            $isList = array_is_list($obj);
            
            if ($isList) {
                $items = [];
                foreach ($obj as $item) {
                    $items[] = self::canonicalize($item);
                }
                return '[' . implode(',', $items) . ']';
            } else {
                // Associative array - sort keys
                $keys = array_keys($obj);
                sort($keys, SORT_STRING);
                $pairs = [];
                foreach ($keys as $k) {
                    $pairs[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) 
                        . ':' 
                        . self::canonicalize($obj[$k]);
                }
                return '{' . implode(',', $pairs) . '}';
            }
        }

        if (is_object($obj)) {
            // Convert object to array and canonicalize
            $arr = (array) $obj;
            $keys = array_keys($arr);
            sort($keys, SORT_STRING);
            $pairs = [];
            foreach ($keys as $k) {
                $pairs[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) 
                    . ':' 
                    . self::canonicalize($arr[$k]);
            }
            return '{' . implode(',', $pairs) . '}';
        }

        return 'null';
    }

    /**
     * Compute a content-addressable asset ID.
     * Excludes self-referential fields (asset_id itself) from the hash input.
     * Returns "sha256:<hex>".
     * 
     * @param array|object $obj
     * @param array<string> $excludeFields Fields to exclude from hash computation
     * @return string|null
     */
    public static function computeAssetId(array|object $obj, array $excludeFields = ['asset_id']): ?string
    {
        if (empty($obj)) {
            return null;
        }

        // Convert to array if object
        $arr = is_object($obj) ? (array) $obj : $obj;

        // Remove excluded fields
        $clean = [];
        foreach ($arr as $k => $v) {
            if (in_array($k, $excludeFields, true)) {
                continue;
            }
            $clean[$k] = $v;
        }

        $canonical = self::canonicalize($clean);
        $hash = hash('sha256', $canonical);
        return 'sha256:' . $hash;
    }

    /**
     * Verify that an object's asset_id matches its content.
     * 
     * @param array|object $obj
     * @return bool
     */
    public static function verifyAssetId(array|object $obj): bool
    {
        $arr = is_object($obj) ? (array) $obj : $obj;
        
        if (!isset($arr['asset_id']) || !is_string($arr['asset_id'])) {
            return false;
        }

        $claimed = $arr['asset_id'];
        $computed = self::computeAssetId($obj);
        
        return $claimed === $computed;
    }

    /**
     * Generate a unique local ID for assets.
     * This is different from asset_id - it's used for local reference.
     * 
     * @param string $prefix
     * @return string
     */
    public static function generateLocalId(string $prefix): string
    {
        return $prefix . '_' . time() . '_' . bin2hex(random_bytes(4));
    }
}
