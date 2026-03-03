<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Asset call log for tracking Gene/Capsule usage history.
 * Enables intelligent recommendations based on usage patterns.
 */
final class AssetCallLog
{
    private Database $db;

    public function __construct(Database $database)
    {
        $this->db = $database;
        $this->ensureTable();
    }

    /**
     * Ensure the asset_call_log table exists.
     */
    private function ensureTable(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS asset_call_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id TEXT NOT NULL,
                asset_type TEXT NOT NULL,
                action TEXT NOT NULL,
                context TEXT,
                success INTEGER DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_asset_call_log_asset_id ON asset_call_log(asset_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_asset_call_log_asset_type ON asset_call_log(asset_type)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_asset_call_log_created_at ON asset_call_log(created_at)');
    }

    /**
     * Log an asset call.
     *
     * @param string $action Action performed (e.g., 'selected', 'applied', 'recommended')
     * @param array $asset Asset data with at least 'id' and 'type' keys
     * @param array $context Additional context (signals, intent, etc.)
     * @param bool $success Whether the call was successful
     */
    public function log(string $action, array $asset, array $context = [], bool $success = true): void
    {
        $assetId = $asset['id'] ?? $asset['asset_id'] ?? 'unknown';
        $assetType = $asset['type'] ?? ($asset['category'] ?? 'unknown');
        $contextJson = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;

        $this->db->exec(
            <<<'SQL'
                INSERT INTO asset_call_log (asset_id, asset_type, action, context, success, created_at)
                VALUES (:asset_id, :asset_type, :action, :context, :success, datetime('now'))
            SQL,
            [
                ':asset_id' => $assetId,
                ':asset_type' => $assetType,
                ':action' => $action,
                ':context' => $contextJson,
                ':success' => $success ? 1 : 0,
            ]
        );
    }

    /**
     * Get call history for a specific asset or all assets.
     *
     * @param string|null $assetId Filter by asset ID (null for all)
     * @param int $limit Maximum number of records to return
     * @return array List of call records
     */
    public function getHistory(?string $assetId = null, int $limit = 100): array
    {
        if ($assetId !== null) {
            $rows = $this->db->fetchAll(
                <<<'SQL'
                    SELECT * FROM asset_call_log
                    WHERE asset_id = :asset_id
                    ORDER BY created_at DESC
                    LIMIT :limit
                SQL,
                [':asset_id' => $assetId, ':limit' => $limit]
            );
        } else {
            $rows = $this->db->fetchAll(
                <<<'SQL'
                    SELECT * FROM asset_call_log
                    ORDER BY created_at DESC
                    LIMIT :limit
                SQL,
                [':limit' => $limit]
            );
        }

        $history = [];
        foreach ($rows as $row) {
            $row['context'] = $row['context'] ? json_decode($row['context'], true) : null;
            $row['success'] = (bool)$row['success'];
            $history[] = $row;
        }

        return $history;
    }

    /**
     * Get most frequently used assets.
     *
     * @param int $limit Maximum number of assets to return
     * @param string|null $assetType Filter by asset type (null for all)
     * @return array List of assets with usage counts
     */
    public function getFrequentlyUsed(int $limit = 10, ?string $assetType = null): array
    {
        if ($assetType !== null) {
            return $this->db->fetchAll(
                <<<'SQL'
                    SELECT asset_id, asset_type, COUNT(*) as call_count,
                           SUM(success) as success_count,
                           ROUND(AVG(success), 2) as success_rate
                    FROM asset_call_log
                    WHERE asset_type = :asset_type
                    GROUP BY asset_id, asset_type
                    ORDER BY call_count DESC, success_rate DESC
                    LIMIT :limit
                SQL,
                [':asset_type' => $assetType, ':limit' => $limit]
            );
        }

        return $this->db->fetchAll(
            <<<'SQL'
                SELECT asset_id, asset_type, COUNT(*) as call_count,
                       SUM(success) as success_count,
                       ROUND(AVG(success), 2) as success_rate
                FROM asset_call_log
                GROUP BY asset_id, asset_type
                ORDER BY call_count DESC, success_rate DESC
                LIMIT :limit
            SQL,
            [':limit' => $limit]
        );
    }

    /**
     * Get summary statistics for asset usage.
     *
     * @return array Summary statistics
     */
    public function summarize(): array
    {
        // Total calls
        $totalResult = $this->db->fetchOne("SELECT COUNT(*) as total FROM asset_call_log");
        $totalCalls = $totalResult['total'] ?? 0;

        // Calls by type
        $byTypeResult = $this->db->fetchAll(<<<'SQL'
            SELECT asset_type, COUNT(*) as count,
                   ROUND(AVG(success), 2) as success_rate
            FROM asset_call_log
            GROUP BY asset_type
            ORDER BY count DESC
        SQL);

        // Calls by action
        $byActionResult = $this->db->fetchAll(<<<'SQL'
            SELECT action, COUNT(*) as count
            FROM asset_call_log
            GROUP BY action
            ORDER BY count DESC
        SQL);

        // Recent activity (last 24 hours)
        $recentResult = $this->db->fetchOne(<<<'SQL'
            SELECT COUNT(*) as count
            FROM asset_call_log
            WHERE created_at >= datetime('now', '-24 hours')
        SQL);

        // Unique assets
        $uniqueResult = $this->db->fetchOne("SELECT COUNT(DISTINCT asset_id) as count FROM asset_call_log");

        return [
            'total_calls' => $totalCalls,
            'unique_assets' => $uniqueResult['count'] ?? 0,
            'recent_24h' => $recentResult['count'] ?? 0,
            'by_type' => $byTypeResult,
            'by_action' => $byActionResult,
        ];
    }

    /**
     * Get recommendations based on usage history.
     * Returns assets that have high success rates for similar contexts.
     *
     * @param array $signals Current signals
     * @param string $intent Current intent
     * @param int $limit Maximum recommendations
     * @return array Recommended assets
     */
    public function getRecommendations(array $signals, string $intent, int $limit = 5): array
    {
        // Find assets with similar intent in context
        return $this->db->fetchAll(
            <<<'SQL'
                SELECT asset_id, asset_type, COUNT(*) as call_count,
                       ROUND(AVG(success), 2) as success_rate
                FROM asset_call_log
                WHERE success = 1
                  AND context LIKE :intent_pattern
                GROUP BY asset_id, asset_type
                HAVING success_rate >= 0.5
                ORDER BY success_rate DESC, call_count DESC
                LIMIT :limit
            SQL,
            [':intent_pattern' => '%' . $intent . '%', ':limit' => $limit]
        );
    }

    /**
     * Clean up old log entries.
     *
     * @param int $daysToKeep Number of days of history to keep
     * @return int Number of deleted rows
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        // Get count before cleanup
        $beforeResult = $this->db->fetchOne("SELECT COUNT(*) as count FROM asset_call_log");
        $beforeCount = $beforeResult['count'] ?? 0;

        $this->db->exec(
            "DELETE FROM asset_call_log WHERE created_at < datetime('now', '-{$daysToKeep} days')"
        );

        // Get count after cleanup
        $afterResult = $this->db->fetchOne("SELECT COUNT(*) as count FROM asset_call_log");
        $afterCount = $afterResult['count'] ?? 0;

        return $beforeCount - $afterCount;
    }

    /**
     * Reset the entire log.
     */
    public function reset(): void
    {
        $this->db->exec("DELETE FROM asset_call_log");
    }
}
