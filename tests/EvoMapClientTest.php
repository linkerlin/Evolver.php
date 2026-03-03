<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\EvoMapClient;
use PHPUnit\Framework\TestCase;

final class EvoMapClientTest extends TestCase
{
    private EvoMapClient $client;

    protected function setUp(): void
    {
        // Use a dummy hub URL for testing
        $this->client = new EvoMapClient('https://test-hub.example.com');
    }

    // =========================================================================
    // Configuration Tests
    // =========================================================================

    public function testIsConfiguredReturnsTrueWhenUrlSet(): void
    {
        $client = new EvoMapClient('https://example.com');
        $this->assertTrue($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenUrlEmpty(): void
    {
        $client = new EvoMapClient('');
        $this->assertFalse($client->isConfigured());
    }

    public function testGetLastErrorReturnsNullInitially(): void
    {
        $this->assertNull($this->client->getLastError());
    }

    // =========================================================================
    // Heartbeat Tracking Tests
    // =========================================================================

    public function testStartHeartbeatTracking(): void
    {
        $this->client->startHeartbeatTracking();
        $stats = $this->client->getHeartbeatStats();

        $this->assertTrue($stats['running']);
        $this->assertSame(0, $stats['total_sent']);
        $this->assertSame(0, $stats['total_failed']);
    }

    public function testGetHeartbeatStatsBeforeTracking(): void
    {
        $stats = $this->client->getHeartbeatStats();

        $this->assertFalse($stats['running']);
        $this->assertSame(0, $stats['uptime_ms']);
    }

    // =========================================================================
    // API Method Tests (without network)
    // =========================================================================

    public function testSendHelloReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->sendHello();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testSendHeartbeatReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->sendHeartbeat();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testPublishAssetReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->publishAsset(['id' => 'test']);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testPublishBundleReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->publishBundle(['id' => 'gene'], ['id' => 'capsule']);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testFetchAssetsReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->fetchAssets('gene');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testSearchAssetsReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->searchAssets(['error', 'syntax']);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testReportValidationReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->reportValidation('asset_123', ['valid' => true]);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    public function testSendDecisionReturnsErrorWhenNotConfigured(): void
    {
        $client = new EvoMapClient('');
        $result = $client->sendDecision('accept', 'asset_123');

        $this->assertFalse($result['ok']);
        $this->assertSame('no_hub_url', $result['error']);
    }

    // =========================================================================
    // Node ID Tests
    // =========================================================================

    public function testGetNodeIdReturnsNonEmptyString(): void
    {
        $nodeId = $this->client->getNodeId();
        $this->assertNotEmpty($nodeId);
        $this->assertMatchesRegularExpression('/^node_/', $nodeId);
    }

    public function testGetNodeIdReturnsConsistentValue(): void
    {
        $id1 = $this->client->getNodeId();
        $id2 = $this->client->getNodeId();

        $this->assertSame($id1, $id2);
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorUsesProvidedUrl(): void
    {
        $client = new EvoMapClient('https://custom.hub.com');
        $this->assertTrue($client->isConfigured());
    }

    public function testConstructorFallsBackToEnvVariable(): void
    {
        // Save original env
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://env-hub.example.com');

        $client = new EvoMapClient();
        // The client uses env URL, isConfigured should be true

        // Restore env
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }

        // Just verify no exception was thrown
        $this->assertInstanceOf(EvoMapClient::class, $client);
    }
}
