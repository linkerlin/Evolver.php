<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Gene/Capsule/Event asset store backed by SQLite.
 * Updated to support GEP 1.6.0 protocol with SHA-256 asset IDs.
 */
final class GepAssetStore
{
    private Database $db;

    public function __construct(Database $database)
    {
        $this->db = $database;
        $this->migrateSchema();
        $this->seedDefaultGenes();
    }

    /**
     * Migrate database schema to latest version.
     */
    private function migrateSchema(): void
    {
        // Check if we need to add asset_id column to genes table
        $columns = $this->db->fetchAll("PRAGMA table_info(genes)");
        $hasAssetId = false;
        $hasSchemaVersion = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'asset_id') $hasAssetId = true;
            if ($col['name'] === 'schema_version') $hasSchemaVersion = true;
        }

        if (!$hasAssetId) {
            $this->db->exec('ALTER TABLE genes ADD COLUMN asset_id TEXT');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_genes_asset_id ON genes(asset_id)');
        }

        if (!$hasSchemaVersion) {
            $this->db->exec('ALTER TABLE genes ADD COLUMN schema_version TEXT DEFAULT "1.5.0"');
        }

        // Check capsules table
        $columns = $this->db->fetchAll("PRAGMA table_info(capsules)");
        $hasAssetId = false;
        $hasOutcome = false;
        $hasEnvFingerprint = false;
        $hasSuccessStreak = false;
        $hasContent = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'asset_id') $hasAssetId = true;
            if ($col['name'] === 'outcome_status') $hasOutcome = true;
            if ($col['name'] === 'env_fingerprint') $hasEnvFingerprint = true;
            if ($col['name'] === 'success_streak') $hasSuccessStreak = true;
            if ($col['name'] === 'content') $hasContent = true;
        }

        if (!$hasAssetId) {
            $this->db->exec('ALTER TABLE capsules ADD COLUMN asset_id TEXT');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_capsules_asset_id ON capsules(asset_id)');
        }

        if (!$hasOutcome) {
            $this->db->exec('ALTER TABLE capsules ADD COLUMN outcome_status TEXT DEFAULT "success"');
            $this->db->exec('ALTER TABLE capsules ADD COLUMN outcome_score REAL DEFAULT 0.5');
        }

        if (!$hasEnvFingerprint) {
            $this->db->exec('ALTER TABLE capsules ADD COLUMN env_fingerprint TEXT');
        }

        if (!$hasSuccessStreak) {
            $this->db->exec('ALTER TABLE capsules ADD COLUMN success_streak INTEGER DEFAULT 0');
        }

        if (!$hasContent) {
            $this->db->exec('ALTER TABLE capsules ADD COLUMN content TEXT');
        }

        // Check events table for new columns
        $columns = $this->db->fetchAll("PRAGMA table_info(events)");
        $hasEnvFingerprint = false;
        $hasMutationsTried = false;
        $hasTotalCycles = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'env_fingerprint') $hasEnvFingerprint = true;
            if ($col['name'] === 'mutations_tried') $hasMutationsTried = true;
            if ($col['name'] === 'total_cycles') $hasTotalCycles = true;
        }

        if (!$hasEnvFingerprint) {
            $this->db->exec('ALTER TABLE events ADD COLUMN env_fingerprint TEXT');
        }

        if (!$hasMutationsTried) {
            $this->db->exec('ALTER TABLE events ADD COLUMN mutations_tried INTEGER DEFAULT 1');
        }

        if (!$hasTotalCycles) {
            $this->db->exec('ALTER TABLE events ADD COLUMN total_cycles INTEGER DEFAULT 1');
        }

        // Create sync_status table for network synchronization
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
            );
            CREATE INDEX IF NOT EXISTS idx_sync_status_asset ON sync_status(asset_type, local_id);
            CREATE INDEX IF NOT EXISTS idx_sync_status_status ON sync_status(sync_status);
        SQL);
    }

    // -------------------------------------------------------------------------
    // Gene operations
    // -------------------------------------------------------------------------

    public function upsertGene(array $gene): void
    {
        $id = $gene['id'] ?? throw new \InvalidArgumentException('Gene must have an id');
        $category = $gene['category'] ?? 'repair';
        
        // Compute asset_id if not present
        if (!isset($gene['asset_id'])) {
            $gene['asset_id'] = ContentHash::computeAssetId($gene);
        }
        $assetId = $gene['asset_id'];
        
        // Ensure schema_version
        if (!isset($gene['schema_version'])) {
            $gene['schema_version'] = ContentHash::SCHEMA_VERSION;
        }
        $schemaVersion = $gene['schema_version'];
        
        $data = json_encode($gene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->db->fetchOne('SELECT id FROM genes WHERE id = ?', [$id]);
        if ($existing) {
            $this->db->exec(
                'UPDATE genes SET category = ?, asset_id = ?, schema_version = ?, data = ?, updated_at = datetime(\'now\') WHERE id = ?',
                [$category, $assetId, $schemaVersion, $data, $id]
            );
        } else {
            $this->db->exec(
                'INSERT INTO genes (id, category, asset_id, schema_version, data) VALUES (?, ?, ?, ?, ?)',
                [$id, $category, $assetId, $schemaVersion, $data]
            );
        }
    }

    public function loadGenes(): array
    {
        $rows = $this->db->fetchAll('SELECT data FROM genes ORDER BY updated_at DESC');
        $genes = [];
        foreach ($rows as $row) {
            $gene = json_decode($row['data'], true);
            if (is_array($gene)) {
                $genes[] = $gene;
            }
        }
        return $genes;
    }

    public function getGene(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT data FROM genes WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        return json_decode($row['data'], true);
    }

    public function getGeneByAssetId(string $assetId): ?array
    {
        $row = $this->db->fetchOne('SELECT data FROM genes WHERE asset_id = ?', [$assetId]);
        if (!$row) {
            return null;
        }
        return json_decode($row['data'], true);
    }

    public function deleteGene(string $id): void
    {
        $this->db->exec('DELETE FROM genes WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    // Capsule operations
    // -------------------------------------------------------------------------

    public function appendCapsule(array $capsule): void
    {
        $id = $capsule['id'] ?? ContentHash::generateLocalId('capsule');
        $capsule['id'] = $id;
        
        // Compute asset_id if not present
        if (!isset($capsule['asset_id'])) {
            $capsule['asset_id'] = ContentHash::computeAssetId($capsule);
        }
        $assetId = $capsule['asset_id'];
        
        $geneId = $capsule['gene'] ?? null;
        $confidence = (float)($capsule['confidence'] ?? 0.5);
        $outcomeStatus = $capsule['outcome']['status'] ?? 'success';
        $outcomeScore = (float)($capsule['outcome']['score'] ?? 0.5);
        $successStreak = (int)($capsule['success_streak'] ?? 0);
        $content = $capsule['content'] ?? null;
        $envFingerprint = isset($capsule['env_fingerprint']) 
            ? json_encode($capsule['env_fingerprint'], JSON_UNESCAPED_UNICODE) 
            : null;
        
        $data = json_encode($capsule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->db->fetchOne('SELECT id FROM capsules WHERE id = ?', [$id]);
        if ($existing) {
            $this->db->exec(
                'UPDATE capsules SET gene_id = ?, asset_id = ?, confidence = ?, outcome_status = ?, outcome_score = ?, success_streak = ?, content = ?, env_fingerprint = ?, data = ? WHERE id = ?',
                [$geneId, $assetId, $confidence, $outcomeStatus, $outcomeScore, $successStreak, $content, $envFingerprint, $data, $id]
            );
        } else {
            $this->db->exec(
                'INSERT INTO capsules (id, gene_id, asset_id, confidence, outcome_status, outcome_score, success_streak, content, env_fingerprint, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $geneId, $assetId, $confidence, $outcomeStatus, $outcomeScore, $successStreak, $content, $envFingerprint, $data]
            );
        }
    }

    public function loadCapsules(int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            'SELECT data FROM capsules ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
        $capsules = [];
        foreach ($rows as $row) {
            $capsule = json_decode($row['data'], true);
            if (is_array($capsule)) {
                $capsules[] = $capsule;
            }
        }
        return $capsules;
    }

    public function getCapsule(string $id): ?array
    {
        $row = $this->db->fetchOne('SELECT data FROM capsules WHERE id = ?', [$id]);
        if (!$row) {
            return null;
        }
        return json_decode($row['data'], true);
    }

    public function getCapsuleByAssetId(string $assetId): ?array
    {
        $row = $this->db->fetchOne('SELECT data FROM capsules WHERE asset_id = ?', [$assetId]);
        if (!$row) {
            return null;
        }
        return json_decode($row['data'], true);
    }

    /**
     * Load capsules sorted by GDI score.
     */
    public function loadCapsulesByGdi(int $limit = 100, bool $descending = true): array
    {
        $rows = $this->db->fetchAll(
            'SELECT data FROM capsules ORDER BY created_at DESC LIMIT ?',
            [$limit * 2]
        );
        $capsules = [];
        foreach ($rows as $row) {
            $capsule = json_decode($row['data'], true);
            if (is_array($capsule)) {
                $capsules[] = $capsule;
            }
        }

        $gdiCalculator = new GdiCalculator();
        return $gdiCalculator->sortCapsulesByGdi($capsules, $descending);
    }

    /**
     * Load top capsules by GDI score.
     */
    public function loadTopCapsules(int $limit = 10): array
    {
        return $this->loadCapsulesByGdi($limit, true);
    }

    /**
     * Load capsules filtered by minimum GDI score.
     */
    public function loadCapsulesByMinGdi(float $minGdi, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            'SELECT data FROM capsules ORDER BY created_at DESC LIMIT ?',
            [$limit * 2]
        );
        $capsules = [];
        foreach ($rows as $row) {
            $capsule = json_decode($row['data'], true);
            if (is_array($capsule)) {
                $capsules[] = $capsule;
            }
        }

        $gdiCalculator = new GdiCalculator();
        return $gdiCalculator->filterCapsulesByMinGdi($capsules, $minGdi);
    }

    /**
     * Get GDI statistics for all capsules.
     */
    public function getCapsulesGdiStats(): array
    {
        $capsules = $this->loadCapsules(1000);
        $gdiCalculator = new GdiCalculator();
        return $gdiCalculator->getGdiStats($capsules);
    }

    /**
     * Compute success streak for a gene based on historical capsules.
     */
    public function computeSuccessStreak(string $geneId, array $signals = []): int
    {
        $rows = $this->db->fetchAll(
            'SELECT data FROM capsules WHERE gene_id = ? AND outcome_status = ? ORDER BY created_at DESC LIMIT 10',
            [$geneId, 'success']
        );
        
        $streak = 0;
        foreach ($rows as $row) {
            $capsule = json_decode($row['data'], true);
            if (($capsule['outcome']['status'] ?? '') === 'success') {
                $streak++;
            } else {
                break;
            }
        }
        
        return $streak;
    }

    // -------------------------------------------------------------------------
    // Event operations
    // -------------------------------------------------------------------------

    public function appendEvent(array $event): void
    {
        $id = $event['id'] ?? ContentHash::generateLocalId('evt');
        $event['id'] = $id;
        
        // Compute asset_id for the event if not present
        if (!isset($event['asset_id'])) {
            $event['asset_id'] = ContentHash::computeAssetId($event);
        }
        
        $intent = $event['intent'] ?? 'repair';
        $signals = json_encode($event['signals'] ?? [], JSON_UNESCAPED_UNICODE);
        $genesUsed = json_encode($event['genes_used'] ?? [], JSON_UNESCAPED_UNICODE);
        $outcomeStatus = $event['outcome']['status'] ?? 'success';
        $outcomeScore = (float)($event['outcome']['score'] ?? 0.5);
        $mutationsTried = (int)($event['mutations_tried'] ?? 1);
        $totalCycles = (int)($event['total_cycles'] ?? 1);
        $envFingerprint = isset($event['env_fingerprint']) 
            ? json_encode($event['env_fingerprint'], JSON_UNESCAPED_UNICODE) 
            : null;
        $data = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->exec(
            'INSERT OR REPLACE INTO events (id, intent, signals, genes_used, outcome_status, outcome_score, mutations_tried, total_cycles, env_fingerprint, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $intent, $signals, $genesUsed, $outcomeStatus, $outcomeScore, $mutationsTried, $totalCycles, $envFingerprint, $data]
        );
    }

    public function getLastEventId(): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM events ORDER BY created_at DESC LIMIT 1'
        );
        return $row ? $row['id'] : null;
    }

    public function loadRecentEvents(int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            'SELECT data FROM events ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
        $events = [];
        foreach ($rows as $row) {
            $event = json_decode($row['data'], true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
        return array_reverse($events); // chronological order
    }

    // -------------------------------------------------------------------------
    // Failed capsule operations
    // -------------------------------------------------------------------------

    public function appendFailedCapsule(array $failedCapsule): void
    {
        $id = $failedCapsule['id'] ?? ContentHash::generateLocalId('failed');
        $failedCapsule['id'] = $id;
        $geneId = $failedCapsule['gene'] ?? null;
        $trigger = json_encode($failedCapsule['trigger'] ?? [], JSON_UNESCAPED_UNICODE);
        $failureReason = $failedCapsule['failure_reason'] ?? null;
        $diffSnapshot = $failedCapsule['diff_snapshot'] ?? null;

        $this->db->exec(
            'INSERT OR REPLACE INTO failed_capsules (id, gene_id, trigger_signals, failure_reason, diff_snapshot) VALUES (?, ?, ?, ?, ?)',
            [$id, $geneId, $trigger, $failureReason, $diffSnapshot]
        );
    }

    public function loadFailedCapsules(int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, gene_id, trigger_signals, failure_reason, diff_snapshot, created_at FROM failed_capsules ORDER BY created_at DESC LIMIT ?',
            [$limit]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => $row['id'],
                'gene' => $row['gene_id'],
                'trigger' => json_decode($row['trigger_signals'] ?? '[]', true) ?? [],
                'failure_reason' => $row['failure_reason'],
                'diff_snapshot' => $row['diff_snapshot'],
                'created_at' => $row['created_at'],
            ];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Sync status operations
    // -------------------------------------------------------------------------

    public function updateSyncStatus(string $assetType, string $localId, ?string $assetId, string $status, ?string $error = null): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM sync_status WHERE asset_type = ? AND local_id = ?',
            [$assetType, $localId]
        );

        if ($existing) {
            $this->db->exec(
                'UPDATE sync_status SET asset_id = ?, sync_status = ?, last_sync_attempt = datetime(\'now\'), sync_error = ?, updated_at = datetime(\'now\') WHERE asset_type = ? AND local_id = ?',
                [$assetId, $status, $error, $assetType, $localId]
            );
        } else {
            $this->db->exec(
                'INSERT INTO sync_status (asset_type, local_id, asset_id, sync_status, sync_error) VALUES (?, ?, ?, ?, ?)',
                [$assetType, $localId, $assetId, $status, $error]
            );
        }
    }

    public function getPendingSyncAssets(string $assetType, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM sync_status WHERE asset_type = ? AND sync_status = ? ORDER BY created_at ASC LIMIT ?',
            [$assetType, 'pending', $limit]
        );
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    public function getStats(): array
    {
        $geneCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM genes');
        $capsuleCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM capsules');
        $eventCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM events');
        $failedCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM failed_capsules');
        $pendingSyncCount = $this->db->fetchOne("SELECT COUNT(*) as cnt FROM sync_status WHERE sync_status = 'pending'");

        return [
            'genes' => (int)($geneCount['cnt'] ?? 0),
            'capsules' => (int)($capsuleCount['cnt'] ?? 0),
            'events' => (int)($eventCount['cnt'] ?? 0),
            'failed_capsules' => (int)($failedCount['cnt'] ?? 0),
            'pending_sync' => (int)($pendingSyncCount['cnt'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Seed default genes
    // -------------------------------------------------------------------------

    private function seedDefaultGenes(): void
    {
        $dataFile = dirname(__DIR__) . '/data/default_genes.json';
        if (!file_exists($dataFile)) {
            return;
        }

        $existing = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM genes');
        if ((int)($existing['cnt'] ?? 0) > 0) {
            return; // already seeded
        }

        $genes = json_decode(file_get_contents($dataFile), true);
        if (!is_array($genes)) {
            return;
        }

        foreach ($genes as $gene) {
            if (is_array($gene) && isset($gene['id'])) {
                // Update to new schema version and compute asset_id
                $gene['schema_version'] = ContentHash::SCHEMA_VERSION;
                $gene['asset_id'] = ContentHash::computeAssetId($gene);
                $this->upsertGene($gene);
            }
        }
    }
}
