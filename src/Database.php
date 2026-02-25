<?php

declare(strict_types=1);

namespace Evolver;

/**
 * SQLite database connection with WAL mode and mmap optimization.
 */
final class Database
{
    private \SQLite3 $db;

    public function __construct(private readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new \SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->db->enableExceptions(true);

        // Performance and reliability settings
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA mmap_size=134217728'); // 128MB mmap
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=-65536'); // 64MB cache
        $this->db->exec('PRAGMA temp_store=MEMORY');
        $this->db->exec('PRAGMA wal_autocheckpoint=1000');

        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS genes (
                id TEXT PRIMARY KEY,
                category TEXT NOT NULL DEFAULT 'repair',
                data TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS capsules (
                id TEXT PRIMARY KEY,
                gene_id TEXT,
                data TEXT NOT NULL,
                confidence REAL DEFAULT 0.5,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS events (
                id TEXT PRIMARY KEY,
                intent TEXT NOT NULL DEFAULT 'repair',
                signals TEXT,
                genes_used TEXT,
                outcome_status TEXT DEFAULT 'success',
                outcome_score REAL DEFAULT 0.5,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS failed_capsules (
                id TEXT PRIMARY KEY,
                gene_id TEXT,
                trigger_signals TEXT,
                failure_reason TEXT,
                diff_snapshot TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE INDEX IF NOT EXISTS idx_genes_category ON genes(category);
            CREATE INDEX IF NOT EXISTS idx_capsules_gene_id ON capsules(gene_id);
            CREATE INDEX IF NOT EXISTS idx_events_intent ON events(intent);
            CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at);
        SQL);
    }

    public function getDb(): \SQLite3
    {
        return $this->db;
    }

    public function query(string $sql, array $params = []): \SQLite3Result|bool
    {
        if (empty($params)) {
            return $this->db->query($sql);
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                is_null($value) => SQLITE3_NULL,
                default => SQLITE3_TEXT,
            };
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }
        return $stmt->execute();
    }

    public function exec(string $sql, array $params = []): bool
    {
        if (empty($params)) {
            return $this->db->exec($sql);
        }

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                is_null($value) => SQLITE3_NULL,
                default => SQLITE3_TEXT,
            };
            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }
        $result = $stmt->execute();
        return $result !== false;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $result = $this->query($sql, $params);
        if ($result === false) {
            return [];
        }
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params);
        if ($result === false) {
            return null;
        }
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row !== false ? $row : null;
    }

    public function lastInsertRowId(): int
    {
        return $this->db->lastInsertRowID();
    }

    public function close(): void
    {
        $this->db->close();
    }
}
