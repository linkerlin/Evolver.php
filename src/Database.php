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

    /** Current schema version */
    private const SCHEMA_VERSION = '1.6.0';

    public function __construct(string $path)
    {
        $this->path = $path;
        
        // Ensure directory exists
        $this->ensureDirectoryExists();
        
        // Check and repair database file if needed
        $this->checkAndRepairDatabase();
        
        // Open database
        $this->openDatabase();
        
        // Run migrations
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
     * Check database file health and repair if needed.
     */
    private function checkAndRepairDatabase(): void
    {
        if (!file_exists($this->path)) {
            // New database, nothing to repair
            return;
        }

        // Check if file is readable/writable
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
                // Remove corrupted file
                unlink($this->path);
                
                // Restore from dump
                exec("sqlite3 " . escapeshellarg($this->path) . " < " . escapeshellarg($dumpPath) . " 2>&1", $output, $returnCode);
                
                if ($returnCode === 0) {
                    $this->migrationLog[] = "Database repaired successfully from dump";
                } else {
                    throw new \RuntimeException("Failed to restore database from dump");
                }
                
                // Clean up dump file
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
     * Run database migrations to ensure schema is up to date.
     */
    private function runMigrations(): void
    {
        // Get current schema version
        $currentVersion = $this->getSchemaVersion();
        
        if ($currentVersion === self::SCHEMA_VERSION) {
            // Already up to date
            return;
        }

        $this->migrationLog[] = "Migrating from {$currentVersion} to " . self::SCHEMA_VERSION;

        // Run base schema creation
        $this->createBaseTables();
        
        // Run version-specific migrations
        if (version_compare($currentVersion, '1.5.0', '<')) {
            $this->migrateTo150();
        }
        
        if (version_compare($currentVersion, '1.6.0', '<')) {
            $this->migrateTo160();
        }

        // Update schema version
        $this->setSchemaVersion(self::SCHEMA_VERSION);
        
        $this->migrationLog[] = "Migration completed successfully";
    }

    /**
     * Get current schema version from database.
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
     * Set schema version in database.
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
     * Create base tables (initial schema).
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

        // Add asset_id columns
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

        // Create sync_status table
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

        // Update existing records to have proper schema version
        $this->db->exec("UPDATE genes SET schema_version = '1.6.0' WHERE schema_version IS NULL OR schema_version = '1.5.0'");
    }

    /**
     * Add a column to a table if it doesn't exist.
     */
    private function addColumnIfNotExists(string $table, string $column, string $type): void
    {
        try {
            $result = $this->db->query("PRAGMA table_info({$table})");
            $exists = false;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                if ($row['name'] === $column) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
                $this->migrationLog[] = "Added column: {$table}.{$column}";
            }
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
                $this->db->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$table}({$columns})");
            } catch (\Exception $e) {
                $this->migrationLog[] = "Warning: Could not create index {$indexName}: " . $e->getMessage();
            }
        }
    }

    /**
     * Get migration log.
     */
    public function getMigrationLog(): array
    {
        return $this->migrationLog;
    }

    /**
     * Get database health status.
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

        // Check integrity
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
