<?php

declare(strict_types=1);

namespace Evolver;

/**
 * SQLite database connection with WAL mode, mmap optimization,
 * automatic schema migration, and health check capabilities.
 */
final class Database
{
    private \SQLite3 $db;
    private string $path;
    private array $migrationLog = [];

    private const DEFAULT_BUSY_TIMEOUT_MS = 15000;
    private const MAX_LOCK_RETRIES = 4;

    /** Current schema version */
    private const SCHEMA_VERSION = '1.9.0';

    public function __construct(string $path)
    {
        $this->path = $path;
        
        // Ensure directory exists
        $this->ensureDirectoryExists();
        
        // 检查and repair database file if needed
        $this->checkAndRepairDatabase();
        
        // Open database
        $this->openDatabase();
        
        // 运行migrations
        $this->runMigrations();
        
        // Verify and create all indexes
        $this->verifyIndexes();
    }

    /**
     * Ensure the database directory exists.
     */
    private function ensureDirectoryExists(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            $created = mkdir($dir, 0755, true);
            if (!$created) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
            $this->migrationLog[] = "Created directory: {$dir}";
        }
    }

    /**
     * 检查database file health and repair if needed.
     */
    private function checkAndRepairDatabase(): void
    {
        if (!file_exists($this->path)) {
            // New database, nothing to repair
            return;
        }

        // 检查 file is readable/writable
        if (!is_readable($this->path)) {
            throw new \RuntimeException("Database file is not readable: {$this->path}");
        }
        if (!is_writable($this->path)) {
            throw new \RuntimeException("Database file is not writable: {$this->path}");
        }

        // Try to open and check integrity
        try {
            $testDb = new \SQLite3($this->path, SQLITE3_OPEN_READONLY);
            $result = $testDb->query('PRAGMA integrity_check');
            if ($result) {
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row && $row['integrity_check'] !== 'ok') {
                    // Integrity check failed, try to repair
                    $this->repairDatabase();
                }
            }
            $testDb->close();
        } catch (\Exception $e) {
            // Database might be corrupted, try repair
            $this->repairDatabase();
        }
    }

    /**
     * Attempt to repair a corrupted database.
     */
    private function repairDatabase(): void
    {
        $backupPath = $this->path . '.backup.' . date('YmdHis');
        
        // Backup corrupted file
        if (copy($this->path, $backupPath)) {
            $this->migrationLog[] = "Created backup of corrupted database: {$backupPath}";
        }

        // Try to dump and restore
        try {
            $dumpPath = $this->path . '.dump.sql';
            exec("sqlite3 " . escapeshellarg($this->path) . " .dump > " . escapeshellarg($dumpPath) . " 2>&1", $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($dumpPath) && filesize($dumpPath) > 0) {
                // 移除corrupted file
                unlink($this->path);
                
                // Restore from dump
                exec("sqlite3 " . escapeshellarg($this->path) . " < " . escapeshellarg($dumpPath) . " 2>&1", $output, $returnCode);
                
                if ($returnCode === 0) {
                    $this->migrationLog[] = "Database repaired successfully from dump";
                } else {
                    throw new \RuntimeException("Failed to restore database from dump");
                }
                
                // 清理up dump file
                unlink($dumpPath);
            } else {
                throw new \RuntimeException("Database is corrupted and cannot be repaired");
            }
        } catch (\Exception $e) {
            // If repair fails, remove corrupted file and start fresh
            rename($this->path, $this->path . '.corrupted.' . date('YmdHis'));
            $this->migrationLog[] = "Database was corrupted, starting fresh (old file backed up)";
        }
    }

    /**
     * Open database connection with optimal settings.
     */
    private function openDatabase(): void
    {
        $this->db = new \SQLite3($this->path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->db->enableExceptions(true);

        $busyTimeoutMs = $this->resolveBusyTimeoutMs();
        $this->db->busyTimeout($busyTimeoutMs);
        $this->db->exec('PRAGMA busy_timeout=' . $busyTimeoutMs);

        // Performance and reliability settings
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA mmap_size=134217728'); // 128MB mmap
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=-65536'); // 64MB cache
        $this->db->exec('PRAGMA temp_store=MEMORY');
        $this->db->exec('PRAGMA wal_autocheckpoint=1000');
        $this->db->exec('PRAGMA foreign_keys=ON');
    }

    /**
     * 运行database migrations to ensure schema is up to date.
     */
    private function runMigrations(): void
    {
        // 获取current schema version
        $currentVersion = $this->getSchemaVersion();
        
        if ($currentVersion === self::SCHEMA_VERSION) {
            // Already up to date
            return;
        }

        $this->migrationLog[] = "Migrating from {$currentVersion} to " . self::SCHEMA_VERSION;

        // 运行base schema creation
        $this->createBaseTables();
        
        // 运行version-specific migrations
        if (version_compare($currentVersion, '1.5.0', '<')) {
            $this->migrateTo150();
        }
        
        if (version_compare($currentVersion, '1.6.0', '<')) {
            $this->migrateTo160();
        }
        if (version_compare($currentVersion, '1.7.0', '<')) {
            $this->migrateTo170();
        }
        if (version_compare($currentVersion, '1.8.0', '<')) {
            $this->migrateTo180();
        }
        if (version_compare($currentVersion, '1.9.0', '<')) {
            $this->migrateTo190();
        }

        // 更新schema version
        $this->setSchemaVersion(self::SCHEMA_VERSION);
        
        $this->migrationLog[] = "Migration completed successfully";
    }

    /**
     * 获取current schema version from database.
     */
    private function getSchemaVersion(): string
    {
        try {
            $result = $this->db->query("SELECT version FROM schema_version WHERE id = 1");
            if ($result) {
                $row = $result->fetchArray(SQLITE3_ASSOC);
                if ($row) {
                    return $row['version'];
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist yet
        }
        return '0.0.0';
    }

    /**
     * 设置schema version in database.
     */
    private function setSchemaVersion(string $version): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_version (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                version TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO schema_version (id, version, updated_at) 
            VALUES (1, :version, datetime('now'))
            ON CONFLICT(id) DO UPDATE SET 
                version = excluded.version,
                updated_at = excluded.updated_at
        SQL);
        
        $stmt->bindValue(':version', $version, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * 创建base tables (initial schema).
     */
    private function createBaseTables(): void
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
        SQL);
    }

    /**
     * Migrate to schema version 1.5.0.
     */
    private function migrateTo150(): void
    {
        $this->migrationLog[] = "Running migration to 1.5.0";

        // 添加asset_id columns
        $this->addColumnIfNotExists('genes', 'asset_id', 'TEXT');
        $this->addColumnIfNotExists('genes', 'schema_version', 'TEXT DEFAULT "1.5.0"');
        $this->addColumnIfNotExists('capsules', 'asset_id', 'TEXT');
        $this->addColumnIfNotExists('capsules', 'outcome_status', 'TEXT DEFAULT "success"');
        $this->addColumnIfNotExists('capsules', 'outcome_score', 'REAL DEFAULT 0.5');
        $this->addColumnIfNotExists('capsules', 'env_fingerprint', 'TEXT');
        $this->addColumnIfNotExists('capsules', 'success_streak', 'INTEGER DEFAULT 0');
        $this->addColumnIfNotExists('capsules', 'content', 'TEXT');
        $this->addColumnIfNotExists('events', 'env_fingerprint', 'TEXT');
        $this->addColumnIfNotExists('events', 'mutations_tried', 'INTEGER DEFAULT 1');
        $this->addColumnIfNotExists('events', 'total_cycles', 'INTEGER DEFAULT 1');
    }

    /**
     * Migrate to schema version 1.6.0.
     */
    private function migrateTo160(): void
    {
        $this->migrationLog[] = "Running migration to 1.6.0";

        // 创建sync_status table
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sync_status (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_type TEXT NOT NULL,
                local_id TEXT NOT NULL,
                asset_id TEXT,
                sync_status TEXT DEFAULT 'pending',
                last_sync_attempt TEXT,
                sync_error TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        // 更新existing records to have proper schema version
        $this->db->exec("UPDATE genes SET schema_version = '1.6.0' WHERE schema_version IS NULL OR schema_version = '1.5.0'");
    }

    /**
     * D1: run_tracker 表，用于统计 run→solidify 闭环覆盖率。
     */
    private function migrateTo170(): void
    {
        $this->migrationLog[] = "Running migration to 1.7.0";
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS run_tracker (
                run_id TEXT PRIMARY KEY,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                solidified_at TEXT
            )
        SQL);
    }

    /**
     * D2: Smart Metadata 列，用于长期记忆生命周期管理。
     */
    private function migrateTo180(): void
    {
        $this->migrationLog[] = "Running migration to 1.8.0";

        // 为 capsules 表添加 smart metadata 列
        $this->addColumnIfNotExists('capsules', 'smart_metadata', 'TEXT DEFAULT "{}"');
        $this->addColumnIfNotExists('capsules', 'tier', 'TEXT DEFAULT "working"');
        $this->addColumnIfNotExists('capsules', 'access_count', 'INTEGER DEFAULT 0');
        $this->addColumnIfNotExists('capsules', 'last_accessed_at', 'INTEGER DEFAULT 0');
        $this->addColumnIfNotExists('capsules', 'importance', 'REAL DEFAULT 0.5');

        // 为 events 表添加 smart metadata 列
        $this->addColumnIfNotExists('events', 'smart_metadata', 'TEXT DEFAULT "{}"');
        $this->addColumnIfNotExists('events', 'tier', 'TEXT DEFAULT "working"');
        $this->addColumnIfNotExists('events', 'access_count', 'INTEGER DEFAULT 0');

        // 为 genes 表添加 smart metadata 列
        $this->addColumnIfNotExists('genes', 'smart_metadata', 'TEXT DEFAULT "{}"');
        $this->addColumnIfNotExists('genes', 'tier', 'TEXT DEFAULT "working"');

        // 创建向量索引表 (为 Phase 3 准备)
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vector_index (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                text TEXT NOT NULL,
                vector TEXT,
                metadata TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        // 添加 tier 相关索引
        $this->addIndexIfNotExists('capsules', 'idx_capsules_tier', 'tier');
        $this->addIndexIfNotExists('capsules', 'idx_capsules_access', 'access_count DESC');
        $this->addIndexIfNotExists('events', 'idx_events_tier', 'tier');
        $this->addIndexIfNotExists('vector_index', 'idx_vector_type', 'type');
    }

    /**
     * Migration to 1.9.0: FTS5 全文搜索表，替代向量搜索。
     */
    private function migrateTo190(): void
    {
        $this->migrationLog[] = "Running migration to 1.9.0";

        // 创建 FTS5 虚拟表用于全文搜索
        // tokenize="unicode61" 支持中文，但需要配合单字分词
        $this->db->exec(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS memory_fts USING fts5(
                id,
                type,
                text,
                text_tokens,  -- 分词后的文本（中文单字分词）
                metadata,
                tokenize='unicode61'
            )
        SQL);

        // 迁移已有数据到 FTS5 表
        $this->migrateVectorIndexToFts();
    }

    /**
     * 迁移 vector_index 数据到 memory_fts。
     */
    private function migrateVectorIndexToFts(): void
    {
        try {
            // 检查是否有数据需要迁移
            $row = $this->fetchOne('SELECT COUNT(*) as count FROM vector_index');
            if ($row && $row['count'] > 0) {
                // 检查 FTS 表是否已有数据
                $ftsRow = $this->fetchOne('SELECT COUNT(*) as count FROM memory_fts');
                if ($ftsRow && $ftsRow['count'] === 0) {
                    $this->migrationLog[] = 'Migrating vector_index to memory_fts';

                    // 批量迁移
                    $rows = $this->fetchAll('SELECT id, type, text, metadata FROM vector_index');
                    foreach ($rows as $row) {
                        $textTokens = self::tokenizeChinese($row['text']);
                        $this->exec(
                            'INSERT INTO memory_fts (id, type, text, text_tokens, metadata) VALUES (:id, :type, :text, :text_tokens, :metadata)',
                            [
                                ':id' => $row['id'],
                                ':type' => $row['type'],
                                ':text' => $row['text'],
                                ':text_tokens' => $textTokens,
                                ':metadata' => $row['metadata'] ?? '{}',
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            // FTS5 表可能不存在，忽略错误
            $this->migrationLog[] = 'Note: Could not migrate to memory_fts - ' . $e->getMessage();
        }
    }

    /**
     * 中文单字分词：将中文文本拆分为单字，用空格分隔。
     * 英文保持原样（按词分）。
     */
    public static function tokenizeChinese(string $text): string
    {
        $result = '';
        $len = mb_strlen($text, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            // 检查是否为中文字符 (CJK Unified Ideographs)
            $codePoint = mb_ord($char, 'UTF-8');
            if ($codePoint >= 0x4E00 && $codePoint <= 0x9FFF) {
                // 中文字符，添加空格分隔
                $result .= ' ' . $char . ' ';
            } elseif (preg_match('/[\s\p{P}]/u', $char)) {
                // 空白或标点，转换为空格
                $result .= ' ';
            } else {
                // 英文/数字等，保持原样
                $result .= $char;
            }
        }

        // 合并多余空格
        return preg_replace('/\s+/', ' ', trim($result));
    }

    /**
     * 添加 an index to a table if it doesn't exist.
     */
    private function addIndexIfNotExists(string $table, string $indexName, string $columns): void
    {
        try {
            $safeTable = $this->validateSqlIdentifier($table);
            $safeIndexName = $this->validateSqlIdentifier($indexName);
            $columnList = array_map('trim', explode(',', $columns));
            $safeColumns = implode(', ', array_map([$this, 'validateSqlIdentifier'], $columnList));

            $this->db->exec("CREATE INDEX IF NOT EXISTS {$safeIndexName} ON {$safeTable}({$safeColumns})");
        } catch (\Exception $e) {
            $this->migrationLog[] = "Warning: Could not create index {$indexName}: " . $e->getMessage();
        }
    }

    /**
     * Validate SQL identifier (table/column/index name) for safety.
     * Only allows alphanumeric characters and underscores.
     */
    private function validateSqlIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }
        return $identifier;
    }

    /**
     * Validate SQL column type for safety.
     * Only allows specific safe SQLite types with optional constraints.
     */
    private function validateSqlType(string $type): string
    {
        $allowedTypes = [
            'INTEGER', 'INT',
            'TEXT',
            'REAL',
            'BLOB',
            'NUMERIC',
            'BOOLEAN',
            'DATETIME',
            'DATE',
            'TIME',
            'VARCHAR',
            'CHAR',
            'DECIMAL',
            'FLOAT',
            'DOUBLE',
        ];
        
        // Extract base type (handle: TEXT, TEXT DEFAULT 'x', VARCHAR(255), etc.)
        $trimmedType = trim($type);
        $upperType = strtoupper($trimmedType);
        
        // Match the base type at the start
        if (!preg_match('/^([a-zA-Z]+)/', $upperType, $matches)) {
            throw new \InvalidArgumentException("Invalid SQL type format: {$type}");
        }
        
        $baseType = $matches[1];
        
        if (!in_array($baseType, $allowedTypes, true)) {
            throw new \InvalidArgumentException("Invalid SQL type: {$type}");
        }
        
        // Validate the full type definition allows only safe characters
        // Allows: alphanumeric, spaces, parentheses, commas, underscores, quotes, braces for defaults
        if (!preg_match('/^[a-zA-Z0-9_\s\(\),\'"\.\{\}]+$/', $trimmedType)) {
            throw new \InvalidArgumentException("Invalid SQL type format: {$type}");
        }
        
        return $trimmedType;
    }

    /**
     * 添加a column to a table if it doesn't exist.
     */
    private function addColumnIfNotExists(string $table, string $column, string $type): void
    {
        try {
            // Validate inputs to prevent SQL injection
            $safeTable = $this->validateSqlIdentifier($table);
            $safeColumn = $this->validateSqlIdentifier($column);
            $safeType = $this->validateSqlType($type);
            
            $result = $this->db->query("PRAGMA table_info({$safeTable})");
            $exists = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === $column) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $this->db->exec("ALTER TABLE {$safeTable} ADD COLUMN {$safeColumn} {$safeType}");
                $this->migrationLog[] = "Added column: {$safeTable}.{$safeColumn}";
            }
        } catch (\InvalidArgumentException $e) {
            $this->migrationLog[] = "Error: Invalid identifier in migration: " . $e->getMessage();
            throw $e;
        } catch (\Exception $e) {
            $this->migrationLog[] = "Warning: Could not add column {$table}.{$column}: " . $e->getMessage();
        }
    }

    /**
     * Verify and create all required indexes.
     */
    private function verifyIndexes(): void
    {
        $indexes = [
            ['genes', 'idx_genes_category', 'category'],
            ['genes', 'idx_genes_asset_id', 'asset_id'],
            ['capsules', 'idx_capsules_gene_id', 'gene_id'],
            ['capsules', 'idx_capsules_asset_id', 'asset_id'],
            ['events', 'idx_events_intent', 'intent'],
            ['events', 'idx_events_created', 'created_at'],
            ['sync_status', 'idx_sync_status_asset', 'asset_type, local_id'],
            ['sync_status', 'idx_sync_status_status', 'sync_status'],
        ];

        foreach ($indexes as [$table, $indexName, $columns]) {
            try {
                // Validate inputs to prevent SQL injection
                $safeTable = $this->validateSqlIdentifier($table);
                $safeIndexName = $this->validateSqlIdentifier($indexName);
                // Columns can be comma-separated, validate each one
                $columnList = array_map('trim', explode(',', $columns));
                $safeColumns = implode(', ', array_map([$this, 'validateSqlIdentifier'], $columnList));
                
                $this->db->exec("CREATE INDEX IF NOT EXISTS {$safeIndexName} ON {$safeTable}({$safeColumns})");
            } catch (\InvalidArgumentException $e) {
                $this->migrationLog[] = "Error: Invalid identifier in index creation: " . $e->getMessage();
            } catch (\Exception $e) {
                $this->migrationLog[] = "Warning: Could not create index {$indexName}: " . $e->getMessage();
            }
        }
    }

    /**
     * 获取migration log.
     */
    public function getMigrationLog(): array
    {
        return $this->migrationLog;
    }

    /**
     * 获取database health status.
     */
    public function getHealthStatus(): array
    {
        $status = [
            'path' => $this->path,
            'exists' => file_exists($this->path),
            'writable' => is_writable($this->path),
            'readable' => is_readable($this->path),
            'size_bytes' => file_exists($this->path) ? filesize($this->path) : 0,
            'schema_version' => $this->getSchemaVersion(),
            'migrations' => $this->migrationLog,
        ];

        // 检查integrity
        try {
            $result = $this->db->query('PRAGMA integrity_check');
            if ($result) {
                $row = $result->fetchArray(SQLITE3_ASSOC);
                $status['integrity_check'] = $row['integrity_check'] ?? 'unknown';
            }
        } catch (\Exception $e) {
            $status['integrity_check'] = 'error: ' . $e->getMessage();
        }

        return $status;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function getDb(): \SQLite3
    {
        return $this->db;
    }

    public function query(string $sql, array $params = []): \SQLite3Result|bool
    {
        return $this->executeWithLockRetry(function () use ($sql, $params): \SQLite3Result|bool {
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
        });
    }

    public function exec(string $sql, array $params = []): bool
    {
        return $this->executeWithLockRetry(function () use ($sql, $params): bool {
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
        });
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

    private function resolveBusyTimeoutMs(): int
    {
        $raw = getenv('EVOLVER_DB_BUSY_TIMEOUT_MS');
        if ($raw === false || $raw === '') {
            return self::DEFAULT_BUSY_TIMEOUT_MS;
        }

        $value = (int) $raw;
        if ($value < 100) {
            return 100;
        }
        if ($value > 60000) {
            return 60000;
        }
        return $value;
    }

    private function isLockContention(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database schema is locked')
            || str_contains($message, 'database is busy')
            || str_contains($message, 'sqlite_busy')
            || str_contains($message, 'sqlite_locked');
    }

    private function executeWithLockRetry(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if (!$this->isLockContention($e) || $attempt >= self::MAX_LOCK_RETRIES) {
                    throw $e;
                }

                $backoffUs = (50_000 * (2 ** $attempt)) + random_int(0, 10_000);
                usleep($backoffUs);
                $attempt++;
            }
        }
    }
}
