<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Assets;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    public function testFormatAssetPreviewWithEmpty(): void
    {
        $this->assertEquals('(none)', Assets::formatAssetPreview(null));
        $this->assertEquals('(none)', Assets::formatAssetPreview(''));
        $this->assertEquals('(none)', Assets::formatAssetPreview([]));
    }

    public function testFormatAssetPreviewWithString(): void
    {
        $this->assertEquals('simple text', Assets::formatAssetPreview('simple text'));
    }

    public function testFormatAssetPreviewWithJsonString(): void
    {
        $json = '{"type":"Gene","id":"test"}';
        $result = Assets::formatAssetPreview($json);

        $this->assertStringContainsString('Gene', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testFormatAssetPreviewWithArray(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'test_gene'];
        $result = Assets::formatAssetPreview($asset);

        $this->assertStringContainsString('Gene', $result);
        $this->assertStringContainsString('test_gene', $result);
    }

    public function testNormalizeAssetAddsSchemaVersion(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'test'];
        $result = Assets::normalizeAsset($asset);

        $this->assertArrayHasKey('schema_version', $result);
    }

    public function testNormalizeAssetAddsAssetId(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'test'];
        $result = Assets::normalizeAsset($asset);

        $this->assertArrayHasKey('asset_id', $result);
    }

    public function testNormalizeAssetPreservesExistingValues(): void
    {
        $asset = ['type' => 'Gene', 'id' => 'test', 'schema_version' => '1.0.0'];
        $result = Assets::normalizeAsset($asset);

        $this->assertEquals('1.0.0', $result['schema_version']);
    }

    public function testNormalizeAssetReturnsNonArrayAsIs(): void
    {
        $this->assertEquals('string', Assets::normalizeAsset('string'));
        $this->assertEquals(null, Assets::normalizeAsset(null));
        $this->assertEquals(123, Assets::normalizeAsset(123));
    }

    public function testFormatAssetsPreview(): void
    {
        $assets = [
            ['type' => 'Gene', 'id' => 'gene1', 'title' => 'Test Gene'],
            ['type' => 'Capsule', 'asset_id' => 'capsule1', 'category' => 'repair'],
        ];

        $result = Assets::formatAssetsPreview($assets);

        $this->assertStringContainsString('Gene', $result);
        $this->assertStringContainsString('gene1', $result);
        $this->assertStringContainsString('Test Gene', $result);
        $this->assertStringContainsString('Capsule', $result);
        $this->assertStringContainsString('repair', $result);
    }

    public function testFormatAssetsPreviewTruncatesLongOutput(): void
    {
        $assets = [];
        for ($i = 0; $i < 100; $i++) {
            $assets[] = ['type' => 'Gene', 'id' => 'gene_' . str_repeat('x', 100)];
        }

        $result = Assets::formatAssetsPreview($assets, 500);

        $this->assertLessThanOrEqual(520, strlen($result)); // maxChars + truncation marker
        $this->assertStringContainsString('TRUNCATED', $result);
    }
}
