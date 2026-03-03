<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\GepAssetStore;
use PHPUnit\Framework\TestCase;

final class GepAssetStoreTest extends TestCase
{
    private Database $db;
    private GepAssetStore $store;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->store = new GepAssetStore($this->db);
    }

    // =========================================================================
    // Gene Operations
    // =========================================================================

    public function testUpsertGeneInsertsNewGene(): void
    {
        $gene = [
            'id' => 'test_gene_1',
            'category' => 'repair',
            'signals_match' => ['error', 'syntax'],
            'strategy' => 'fix_syntax',
        ];

        $this->store->upsertGene($gene);

        $loaded = $this->store->getGene('test_gene_1');
        $this->assertNotNull($loaded);
        $this->assertSame('test_gene_1', $loaded['id']);
        $this->assertSame('repair', $loaded['category']);
    }

    public function testUpsertGeneUpdatesExistingGene(): void
    {
        $gene = [
            'id' => 'test_gene_2',
            'category' => 'repair',
            'strategy' => 'original',
        ];
        $this->store->upsertGene($gene);

        $gene['strategy'] = 'updated';
        $this->store->upsertGene($gene);

        $loaded = $this->store->getGene('test_gene_2');
        $this->assertSame('updated', $loaded['strategy']);
    }

    public function testUpsertGeneRequiresId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gene must have an id');

        $this->store->upsertGene(['category' => 'repair']);
    }

    public function testLoadGenesReturnsAllGenes(): void
    {
        $this->store->upsertGene(['id' => 'gene_1', 'category' => 'repair']);
        $this->store->upsertGene(['id' => 'gene_2', 'category' => 'optimize']);

        $genes = $this->store->loadGenes();
        $this->assertCount(2, $genes);
    }

    public function testGetGeneReturnsNullForNonExistent(): void
    {
        $result = $this->store->getGene('non_existent');
        $this->assertNull($result);
    }

    public function testGetGeneByAssetIdReturnsGene(): void
    {
        $gene = [
            'id' => 'gene_with_asset',
            'category' => 'repair',
        ];
        $this->store->upsertGene($gene);

        // Get the stored gene to find its auto-generated asset_id
        $stored = $this->store->getGene('gene_with_asset');
        $this->assertNotNull($stored);
        $this->assertArrayHasKey('asset_id', $stored);

        $byAssetId = $this->store->getGeneByAssetId($stored['asset_id']);
        $this->assertNotNull($byAssetId);
        $this->assertSame('gene_with_asset', $byAssetId['id']);
    }

    public function testDeleteGeneRemovesGene(): void
    {
        $this->store->upsertGene(['id' => 'to_delete', 'category' => 'repair']);
        $this->assertNotNull($this->store->getGene('to_delete'));

        $this->store->deleteGene('to_delete');

        $this->assertNull($this->store->getGene('to_delete'));
    }

    // =========================================================================
    // Capsule Operations
    // =========================================================================

    public function testAppendCapsuleInsertsNewCapsule(): void
    {
        $capsule = [
            'id' => 'capsule_1',
            'gene' => 'test_gene',
            'confidence' => 0.85,
            'content' => 'Test capsule content',
        ];

        $this->store->appendCapsule($capsule);

        $loaded = $this->store->getCapsule('capsule_1');
        $this->assertNotNull($loaded);
        $this->assertSame('capsule_1', $loaded['id']);
        $this->assertSame('test_gene', $loaded['gene']);
    }

    public function testAppendCapsuleGeneratesIdIfMissing(): void
    {
        $capsule = [
            'gene' => 'test_gene',
            'content' => 'No ID provided',
        ];

        $this->store->appendCapsule($capsule);

        $capsules = $this->store->loadCapsules();
        $this->assertNotEmpty($capsules);
        $this->assertArrayHasKey('id', $capsules[0]);
    }

    public function testLoadCapsulesReturnsLimitedResults(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->store->appendCapsule([
                'id' => "capsule_limit_{$i}",
                'gene' => 'test_gene',
            ]);
        }

        $capsules = $this->store->loadCapsules(10);
        $this->assertCount(10, $capsules);
    }

    public function testGetCapsuleReturnsNullForNonExistent(): void
    {
        $result = $this->store->getCapsule('non_existent_capsule');
        $this->assertNull($result);
    }

    public function testGetCapsuleByAssetIdReturnsCapsule(): void
    {
        $capsule = [
            'id' => 'capsule_with_asset',
            'gene' => 'test_gene',
        ];
        $this->store->appendCapsule($capsule);

        $stored = $this->store->getCapsule('capsule_with_asset');
        $this->assertNotNull($stored);
        $this->assertArrayHasKey('asset_id', $stored);

        $byAssetId = $this->store->getCapsuleByAssetId($stored['asset_id']);
        $this->assertNotNull($byAssetId);
        $this->assertSame('capsule_with_asset', $byAssetId['id']);
    }

    public function testAppendCapsuleWithOutcome(): void
    {
        $capsule = [
            'id' => 'capsule_outcome',
            'gene' => 'test_gene',
            'outcome' => [
                'status' => 'success',
                'score' => 0.9,
            ],
        ];

        $this->store->appendCapsule($capsule);

        $loaded = $this->store->getCapsule('capsule_outcome');
        $this->assertNotNull($loaded);
        $this->assertSame('success', $loaded['outcome']['status']);
        $this->assertSame(0.9, $loaded['outcome']['score']);
    }

    // =========================================================================
    // Event Operations
    // =========================================================================

    public function testAppendEventInsertsEvent(): void
    {
        $event = [
            'id' => 'event_1',
            'intent' => 'repair',
            'signals' => ['error', 'syntax'],
            'outcome' => ['status' => 'success', 'score' => 0.8],
        ];

        $this->store->appendEvent($event);

        $events = $this->store->loadRecentEvents(10);
        $this->assertCount(1, $events);
        $this->assertSame('event_1', $events[0]['id']);
    }

    public function testAppendEventGeneratesIdIfMissing(): void
    {
        $event = [
            'intent' => 'optimize',
            'signals' => ['performance'],
        ];

        $this->store->appendEvent($event);

        $events = $this->store->loadRecentEvents(10);
        $this->assertNotEmpty($events);
        $this->assertArrayHasKey('id', $events[0]);
    }

    public function testGetLastEventIdReturnsMostRecent(): void
    {
        $this->store->appendEvent(['id' => 'event_first', 'intent' => 'repair']);
        sleep(1); // Ensure different timestamps
        $this->store->appendEvent(['id' => 'event_last', 'intent' => 'repair']);

        $lastId = $this->store->getLastEventId();
        $this->assertSame('event_last', $lastId);
    }

    public function testLoadRecentEventsReturnsChronologicalOrder(): void
    {
        $this->store->appendEvent(['id' => 'old_event', 'intent' => 'repair']);
        $this->store->appendEvent(['id' => 'new_event', 'intent' => 'repair']);

        $events = $this->store->loadRecentEvents(10);
        $this->assertSame('old_event', $events[0]['id']);
        $this->assertSame('new_event', $events[1]['id']);
    }

    // =========================================================================
    // Failed Capsule Operations
    // =========================================================================

    public function testAppendFailedCapsule(): void
    {
        $failed = [
            'gene' => 'test_gene',
            'trigger' => ['signal1', 'signal2'],
            'failure_reason' => 'Test failure',
        ];

        $this->store->appendFailedCapsule($failed);

        $loaded = $this->store->loadFailedCapsules(10);
        $this->assertCount(1, $loaded);
        $this->assertSame('test_gene', $loaded[0]['gene']);
        $this->assertSame('Test failure', $loaded[0]['failure_reason']);
    }

    public function testLoadFailedCapsulesReturnsLimitedResults(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->store->appendFailedCapsule([
                'id' => "failed_{$i}",
                'gene' => 'test_gene',
                'failure_reason' => "Failure {$i}",
            ]);
        }

        $failed = $this->store->loadFailedCapsules(10);
        $this->assertCount(10, $failed);
    }

    // =========================================================================
    // Sync Status Operations
    // =========================================================================

    public function testUpdateSyncStatusInsertsNew(): void
    {
        $this->store->updateSyncStatus('gene', 'local_1', 'asset_1', 'synced');

        $pending = $this->store->getPendingSyncAssets('gene');
        $this->assertEmpty($pending); // Not pending, so should not be returned
    }

    public function testUpdateSyncStatusWithPending(): void
    {
        $this->store->updateSyncStatus('gene', 'pending_1', null, 'pending');

        $pending = $this->store->getPendingSyncAssets('gene');
        $this->assertCount(1, $pending);
        $this->assertSame('pending_1', $pending[0]['local_id']);
    }

    public function testUpdateSyncStatusUpdatesExisting(): void
    {
        $this->store->updateSyncStatus('gene', 'local_2', null, 'pending');
        $this->store->updateSyncStatus('gene', 'local_2', 'asset_2', 'synced');

        $pending = $this->store->getPendingSyncAssets('gene');
        $this->assertEmpty($pending);
    }

    // =========================================================================
    // Stats
    // =========================================================================

    public function testGetStatsReturnsCorrectCounts(): void
    {
        $this->store->upsertGene(['id' => 'stats_gene', 'category' => 'repair']);
        $this->store->appendCapsule(['id' => 'stats_capsule', 'gene' => 'stats_gene']);
        $this->store->appendEvent(['id' => 'stats_event', 'intent' => 'repair']);
        $this->store->appendFailedCapsule(['gene' => 'stats_gene', 'failure_reason' => 'test']);
        $this->store->updateSyncStatus('gene', 'pending_stats', null, 'pending');

        $stats = $this->store->getStats();

        $this->assertSame(1, $stats['genes']);
        $this->assertSame(1, $stats['capsules']);
        $this->assertSame(1, $stats['events']);
        $this->assertSame(1, $stats['failed_capsules']);
        $this->assertSame(1, $stats['pending_sync']);
    }

    // =========================================================================
    // GDI Operations
    // =========================================================================

    public function testLoadCapsulesByGdiSortsByScore(): void
    {
        // Create capsules with different confidence/success values
        $this->store->appendCapsule([
            'id' => 'gdi_low',
            'gene' => 'test_gene',
            'confidence' => 0.5,
            'success_streak' => 1,
        ]);
        $this->store->appendCapsule([
            'id' => 'gdi_high',
            'gene' => 'test_gene',
            'confidence' => 0.9,
            'success_streak' => 5,
        ]);

        $sorted = $this->store->loadCapsulesByGdi(10, true);
        $this->assertNotEmpty($sorted);
        // High confidence + high streak should be first when descending
        $this->assertSame('gdi_high', $sorted[0]['id']);
    }

    public function testComputeSuccessStreakReturnsCorrectCount(): void
    {
        // Create successful capsules
        for ($i = 0; $i < 3; $i++) {
            $this->store->appendCapsule([
                'id' => "streak_success_{$i}",
                'gene' => 'streak_gene',
                'outcome' => ['status' => 'success', 'score' => 0.8],
            ]);
        }

        $streak = $this->store->computeSuccessStreak('streak_gene');
        $this->assertSame(3, $streak);
    }
}
