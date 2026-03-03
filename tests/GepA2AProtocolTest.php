<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\GepA2AProtocol;
use PHPUnit\Framework\TestCase;

final class GepA2AProtocolTest extends TestCase
{
    private GepA2AProtocol $protocol;

    protected function setUp(): void
    {
        $this->protocol = new GepA2AProtocol();
    }

    public function testGenerateMessageIdFormat(): void
    {
        $id = GepA2AProtocol::generateMessageId();

        $this->assertStringStartsWith('msg_', $id);
        $this->assertMatchesRegularExpression('/^msg_\d+_[a-f0-9]{8}$/', $id);
    }

    public function testGetNodeIdFormat(): void
    {
        $nodeId = $this->protocol->getNodeId();

        $this->assertStringStartsWith('node_', $nodeId);
    }

    public function testGetNodeIdCachesResult(): void
    {
        $id1 = $this->protocol->getNodeId();
        $id2 = $this->protocol->getNodeId();

        $this->assertEquals($id1, $id2);
    }

    public function testBuildMessageWithValidType(): void
    {
        $message = $this->protocol->buildMessage('hello', ['test' => 'data']);

        $this->assertEquals('gep-a2a', $message['protocol']);
        $this->assertEquals('1.0.0', $message['protocol_version']);
        $this->assertEquals('hello', $message['message_type']);
        $this->assertArrayHasKey('message_id', $message);
        $this->assertArrayHasKey('timestamp', $message);
        $this->assertEquals(['test' => 'data'], $message['payload']);
    }

    public function testBuildMessageWithInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message type');

        $this->protocol->buildMessage('invalid_type');
    }

    public function testBuildHello(): void
    {
        $message = $this->protocol->buildHello([
            'capabilities' => ['repair'],
            'geneCount' => 5,
            'capsuleCount' => 10,
        ]);

        $this->assertEquals('hello', $message['message_type']);
        $this->assertEquals(['repair'], $message['payload']['capabilities']);
        $this->assertEquals(5, $message['payload']['gene_count']);
        $this->assertEquals(10, $message['payload']['capsule_count']);
    }

    public function testBuildPublishWithValidAsset(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'test_gene', 'strategy' => []];
        $message = $this->protocol->buildPublish($asset);

        $this->assertEquals('publish', $message['message_type']);
        $this->assertEquals('Gene', $message['payload']['asset_type']);
        $this->assertEquals('test_gene', $message['payload']['local_id']);
        $this->assertArrayHasKey('signature', $message['payload']);
    }

    public function testBuildPublishWithInvalidAssetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must have type and id');

        $this->protocol->buildPublish(['invalid' => 'asset']);
    }

    public function testBuildPublishBundle(): void
    {
        $gene = ['type' => 'Gene', 'id' => 'g1'];
        $capsule = ['type' => 'Capsule', 'id' => 'c1'];

        $message = $this->protocol->buildPublishBundle($gene, $capsule);

        $this->assertEquals('publish', $message['message_type']);
        $this->assertCount(2, $message['payload']['assets']);
    }

    public function testBuildPublishBundleWithEvent(): void
    {
        $gene = ['type' => 'Gene', 'id' => 'g1'];
        $capsule = ['type' => 'Capsule', 'id' => 'c1'];
        $event = ['type' => 'EvolutionEvent', 'id' => 'e1'];

        $message = $this->protocol->buildPublishBundle($gene, $capsule, $event);

        $this->assertCount(3, $message['payload']['assets']);
    }

    public function testBuildFetch(): void
    {
        $message = $this->protocol->buildFetch([
            'assetType' => 'Gene',
            'localId' => 'test',
        ]);

        $this->assertEquals('fetch', $message['message_type']);
        $this->assertEquals('Gene', $message['payload']['asset_type']);
        $this->assertEquals('test', $message['payload']['local_id']);
    }

    public function testBuildReport(): void
    {
        $message = $this->protocol->buildReport([
            'assetId' => 'asset123',
            'validationReport' => ['valid' => true],
        ]);

        $this->assertEquals('report', $message['message_type']);
        $this->assertEquals('asset123', $message['payload']['target_asset_id']);
    }

    public function testBuildDecisionAccept(): void
    {
        $message = $this->protocol->buildDecision('accept', [
            'assetId' => 'asset123',
            'reason' => 'Valid asset',
        ]);

        $this->assertEquals('decision', $message['message_type']);
        $this->assertEquals('accept', $message['payload']['decision']);
    }

    public function testBuildDecisionInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('decision must be one of');

        $this->protocol->buildDecision('invalid', []);
    }

    public function testBuildRevoke(): void
    {
        $message = $this->protocol->buildRevoke([
            'assetId' => 'asset123',
            'reason' => 'Deprecated',
        ]);

        $this->assertEquals('revoke', $message['message_type']);
        $this->assertEquals('Deprecated', $message['payload']['reason']);
    }

    public function testIsValidProtocolMessageWithValid(): void
    {
        $message = $this->protocol->buildMessage('hello');
        $this->assertTrue(GepA2AProtocol::isValidProtocolMessage($message));
    }

    public function testIsValidProtocolMessageWithInvalid(): void
    {
        $this->assertFalse(GepA2AProtocol::isValidProtocolMessage([]));
        $this->assertFalse(GepA2AProtocol::isValidProtocolMessage(['protocol' => 'other']));
        $this->assertFalse(GepA2AProtocol::isValidProtocolMessage([
            'protocol' => 'gep-a2a',
            'message_type' => 'invalid',
        ]));
    }

    public function testUnwrapAssetFromMessageWithPublish(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'g1'];
        $message = $this->protocol->buildPublish($asset);

        $unwrapped = GepA2AProtocol::unwrapAssetFromMessage($message);

        $this->assertEquals('Gene', $unwrapped['type']);
        $this->assertEquals('g1', $unwrapped['id']);
    }

    public function testUnwrapAssetFromMessageWithPlainAsset(): void
    {
        $asset = ['type' => 'Capsule', 'id' => 'c1'];

        $unwrapped = GepA2AProtocol::unwrapAssetFromMessage($asset);

        $this->assertEquals('Capsule', $unwrapped['type']);
    }

    public function testBuildHeartbeat(): void
    {
        $heartbeat = $this->protocol->buildHeartbeat(5000);

        $this->assertArrayHasKey('node_id', $heartbeat);
        $this->assertArrayHasKey('timestamp', $heartbeat);
        $this->assertEquals(5000, $heartbeat['uptime_ms']);
    }

    public function testGetHeartbeatStats(): void
    {
        $stats = $this->protocol->getHeartbeatStats();

        $this->assertArrayHasKey('started_at', $stats);
        $this->assertArrayHasKey('uptime_ms', $stats);
        $this->assertArrayHasKey('heartbeat_count', $stats);
    }
}
