<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\AssetCallLog;
use Evolver\Database;
use PHPUnit\Framework\TestCase;

/**
 * AssetCallLog tests.
 */
final class AssetCallLogTest extends TestCase
{
    private Database $db;
    private AssetCallLog $log;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->log = new AssetCallLog($this->db);
    }

    public function testLogAssetCall(): void
    {
        $asset = ['id' => 'gene_repair_001', 'type' => 'Gene'];
        $context = ['intent' => 'repair', 'signals' => ['error']];

        $this->log->log('selected', $asset, $context, true);

        $history = $this->log->getHistory();
        $this->assertCount(1, $history);
        $this->assertEquals('gene_repair_001', $history[0]['asset_id']);
        $this->assertEquals('Gene', $history[0]['asset_type']);
        $this->assertEquals('selected', $history[0]['action']);
        $this->assertTrue($history[0]['success']);
    }

    public function testLogWithMinimalData(): void
    {
        $this->log->log('applied', ['id' => 'capsule_001']);

        $history = $this->log->getHistory();
        $this->assertCount(1, $history);
        $this->assertEquals('capsule_001', $history[0]['asset_id']);
        $this->assertEquals('unknown', $history[0]['asset_type']);
        $this->assertTrue($history[0]['success']);
    }

    public function testGetHistoryByAssetId(): void
    {
        $this->log->log('selected', ['id' => 'gene_001', 'type' => 'Gene']);
        $this->log->log('selected', ['id' => 'gene_002', 'type' => 'Gene']);
        $this->log->log('applied', ['id' => 'gene_001', 'type' => 'Gene']);

        $history = $this->log->getHistory('gene_001');

        $this->assertCount(2, $history);
        foreach ($history as $entry) {
            $this->assertEquals('gene_001', $entry['asset_id']);
        }
    }

    public function testGetHistoryWithLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->log->log('selected', ['id' => "gene_{$i}", 'type' => 'Gene']);
        }

        $history = $this->log->getHistory(null, 5);
        $this->assertCount(5, $history);
    }

    public function testGetFrequentlyUsed(): void
    {
        // Log multiple calls for same assets
        for ($i = 0; $i < 5; $i++) {
            $this->log->log('selected', ['id' => 'gene_popular', 'type' => 'Gene'], [], true);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->log->log('selected', ['id' => 'gene_medium', 'type' => 'Gene'], [], true);
        }
        $this->log->log('selected', ['id' => 'gene_rare', 'type' => 'Gene'], [], true);

        $frequent = $this->log->getFrequentlyUsed(10);

        $this->assertCount(3, $frequent);
        $this->assertEquals('gene_popular', $frequent[0]['asset_id']);
        $this->assertEquals(5, $frequent[0]['call_count']);
    }

    public function testGetFrequentlyUsedByType(): void
    {
        $this->log->log('selected', ['id' => 'gene_001', 'type' => 'Gene'], [], true);
        $this->log->log('selected', ['id' => 'gene_002', 'type' => 'Gene'], [], true);
        $this->log->log('selected', ['id' => 'capsule_001', 'type' => 'Capsule'], [], true);

        $geneFrequent = $this->log->getFrequentlyUsed(10, 'Gene');
        $this->assertCount(2, $geneFrequent);

        $capsuleFrequent = $this->log->getFrequentlyUsed(10, 'Capsule');
        $this->assertCount(1, $capsuleFrequent);
    }

    public function testSuccessRateCalculation(): void
    {
        // 4 successful, 1 failed = 80% success rate
        for ($i = 0; $i < 4; $i++) {
            $this->log->log('applied', ['id' => 'gene_test', 'type' => 'Gene'], [], true);
        }
        $this->log->log('applied', ['id' => 'gene_test', 'type' => 'Gene'], [], false);

        $frequent = $this->log->getFrequentlyUsed();
        $this->assertEquals(0.8, $frequent[0]['success_rate']);
        $this->assertEquals(4, $frequent[0]['success_count']);
    }

    public function testSummarize(): void
    {
        $this->log->log('selected', ['id' => 'gene_001', 'type' => 'Gene'], [], true);
        $this->log->log('applied', ['id' => 'gene_001', 'type' => 'Gene'], [], true);
        $this->log->log('selected', ['id' => 'capsule_001', 'type' => 'Capsule'], [], false);

        $summary = $this->log->summarize();

        $this->assertEquals(3, $summary['total_calls']);
        $this->assertEquals(2, $summary['unique_assets']);
        $this->assertEquals(3, $summary['recent_24h']);
        $this->assertCount(2, $summary['by_type']);
        $this->assertCount(2, $summary['by_action']);
    }

    public function testGetRecommendations(): void
    {
        // Log some successful calls with intent in context
        for ($i = 0; $i < 3; $i++) {
            $this->log->log('selected', ['id' => 'gene_repair', 'type' => 'Gene'], ['intent' => 'repair'], true);
        }
        $this->log->log('selected', ['id' => 'gene_optimize', 'type' => 'Gene'], ['intent' => 'optimize'], true);

        $recommendations = $this->log->getRecommendations([], 'repair', 5);

        $this->assertNotEmpty($recommendations);
        $this->assertEquals('gene_repair', $recommendations[0]['asset_id']);
        $this->assertGreaterThanOrEqual(0.5, $recommendations[0]['success_rate']);
    }

    public function testCleanup(): void
    {
        // Log some entries
        for ($i = 0; $i < 5; $i++) {
            $this->log->log('selected', ['id' => "gene_{$i}", 'type' => 'Gene']);
        }

        // Verify entries exist
        $history = $this->log->getHistory();
        $this->assertCount(5, $history);

        // Cleanup with 0 days should not delete recent entries
        $deleted = $this->log->cleanup(0);
        $this->assertEquals(0, $deleted);

        // History should still have all entries
        $history = $this->log->getHistory();
        $this->assertCount(5, $history);

        // Test reset instead for complete cleanup
        $this->log->reset();
        $history = $this->log->getHistory();
        $this->assertEmpty($history);
    }

    public function testReset(): void
    {
        $this->log->log('selected', ['id' => 'gene_001', 'type' => 'Gene']);
        $this->log->log('applied', ['id' => 'capsule_001', 'type' => 'Capsule']);

        $this->log->reset();

        $history = $this->log->getHistory();
        $this->assertEmpty($history);

        $summary = $this->log->summarize();
        $this->assertEquals(0, $summary['total_calls']);
    }

    public function testContextJsonEncoding(): void
    {
        $context = [
            'signals' => ['error', 'warning'],
            'intent' => 'repair',
            'metadata' => ['key' => 'value'],
        ];

        $this->log->log('selected', ['id' => 'gene_001', 'type' => 'Gene'], $context);

        $history = $this->log->getHistory();
        $this->assertIsArray($history[0]['context']);
        $this->assertEquals(['error', 'warning'], $history[0]['context']['signals']);
    }
}
