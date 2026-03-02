<?php

declare(strict_types=1);

namespace Evolver;

/**
 * GEP资源的可寻址内容哈希。
 * 提供规范的JSON序列化和基于SHA-256的资源ID。
 * 支持去重、篡改检测和跨节点一致性。
 * 
 * 来自EvoMap/evolver的contentHash.js的PHP移植版本。
 */
final class ContentHash
{
    /** GEP资源类型的模式版本 */
    public const SCHEMA_VERSION = '1.6.0';

    /**
     * 规范JSON：各层级按键排序的确定性序列化。
     * 数组保持顺序；非有限数字变为null；undefined/null变为null。
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
            // 检查 it's a sequential array (list) or associative array (map)
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
     * 计算可寻址内容的资源ID。
     * 从哈希输入中排除自引用字段（asset_id本身）。
     * 返回 "sha256:<hex>"。
     * 
     * @param array|object $obj
     * @param array<string> $excludeFields 从哈希计算中排除的字段
     * @return string|null
     */
    public static function computeAssetId(array|object $obj, array $excludeFields = ['asset_id']): ?string
    {
        if (empty($obj)) {
            return null;
        }

        // Convert to array if object
        $arr = is_object($obj) ? (array) $obj : $obj;

        // 移除excluded fields
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
     * 验证对象的asset_id与其内容匹配。
     * 
     * @param array|object $obj
     * @param string $assetId
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
     * 为资源生成唯一的本地ID。
     * 这与asset_id不同 - 它用于本地引用。
     * 
     * @param string $prefix
     * @return string
     */
    public static function generateLocalId(string $prefix): string
    {
        return $prefix . '_' . time() . '_' . bin2hex(random_bytes(4));
    }
}
