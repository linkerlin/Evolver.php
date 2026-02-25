<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Gene/Capsule/Event asset store backed by SQLite.
 */
final class GepAssetStore
{
    private Database $db;

    public function __construct(Database $database)
    {
        $this->db = $database;
        $this->seedDefaultGenes();
    }

    // -------------------------------------------------------------------------
    // Gene operations
    // -------------------------------------------------------------------------

    public function upsertGene(array $gene): void
    {
        $id = $gene['id'] ?? throw new \InvalidArgumentException('Gene must have an id');
        $category = $gene['category'] ?? 'repair';
        $data = json_encode($gene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->db->fetchOne('SELECT id FROM genes WHERE id = ?', [$id]);
        if ($existing) {
            $this->db->exec(
                'UPDATE genes SET category = ?, data = ?, updated_at = datetime(\'now\') WHERE id = ?',
                [$category, $data, $id]
            );
        } else {
            $this->db->exec(
                'INSERT INTO genes (id, category, data) VALUES (?, ?, ?)',
                [$id, $category, $data]
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

    public function deleteGene(string $id): void
    {
        $this->db->exec('DELETE FROM genes WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    // Capsule operations
    // -------------------------------------------------------------------------

    public function appendCapsule(array $capsule): void
    {
        $id = $capsule['id'] ?? ('capsule_' . time() . '_' . bin2hex(random_bytes(4)));
        $capsule['id'] = $id;
        $geneId = $capsule['gene'] ?? null;
        $confidence = (float)($capsule['confidence'] ?? 0.5);
        $data = json_encode($capsule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $existing = $this->db->fetchOne('SELECT id FROM capsules WHERE id = ?', [$id]);
        if ($existing) {
            $this->db->exec(
                'UPDATE capsules SET gene_id = ?, confidence = ?, data = ? WHERE id = ?',
                [$geneId, $confidence, $data, $id]
            );
        } else {
            $this->db->exec(
                'INSERT INTO capsules (id, gene_id, confidence, data) VALUES (?, ?, ?, ?)',
                [$id, $geneId, $confidence, $data]
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

    // -------------------------------------------------------------------------
    // Event operations
    // -------------------------------------------------------------------------

    public function appendEvent(array $event): void
    {
        $id = $event['id'] ?? ('evt_' . time() . '_' . bin2hex(random_bytes(4)));
        $event['id'] = $id;
        $intent = $event['intent'] ?? 'repair';
        $signals = json_encode($event['signals'] ?? [], JSON_UNESCAPED_UNICODE);
        $genesUsed = json_encode($event['genes_used'] ?? [], JSON_UNESCAPED_UNICODE);
        $outcomeStatus = $event['outcome']['status'] ?? 'success';
        $outcomeScore = (float)($event['outcome']['score'] ?? 0.5);
        $data = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->db->exec(
            'INSERT OR REPLACE INTO events (id, intent, signals, genes_used, outcome_status, outcome_score, data) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $intent, $signals, $genesUsed, $outcomeStatus, $outcomeScore, $data]
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
        $id = $failedCapsule['id'] ?? ('failed_' . time() . '_' . bin2hex(random_bytes(4)));
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
    // Stats
    // -------------------------------------------------------------------------

    public function getStats(): array
    {
        $geneCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM genes');
        $capsuleCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM capsules');
        $eventCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM events');
        $failedCount = $this->db->fetchOne('SELECT COUNT(*) as cnt FROM failed_capsules');

        return [
            'genes' => (int)($geneCount['cnt'] ?? 0),
            'capsules' => (int)($capsuleCount['cnt'] ?? 0),
            'events' => (int)($eventCount['cnt'] ?? 0),
            'failed_capsules' => (int)($failedCount['cnt'] ?? 0),
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
                $this->upsertGene($gene);
            }
        }
    }
}
