<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\ContentHash;
use PHPUnit\Framework\TestCase;

/**
 * Content Hash tests - extracted from EvolverTest.php
 */
final class ContentHashTest extends TestCase
{
    public function testCanonicalizeBasic(): void
    {
        $input = ['b' => 2, 'a' => 1];
        $canonical = ContentHash::canonicalize($input);

        // Should return a JSON string with sorted keys
        $this->assertIsString($canonical);
        $this->assertStringContainsString('"a":1', $canonical);
        $this->assertStringContainsString('"b":2', $canonical);
    }

    public function testCanonicalizeNested(): void
    {
        $input = ['z' => ['b' => 2, 'a' => 1], 'a' => 1];
        $canonical = ContentHash::canonicalize($input);

        // Should return a JSON string with nested sorted keys
        $this->assertIsString($canonical);
        $this->assertStringContainsString('"a"', $canonical);
        $this->assertStringContainsString('"z"', $canonical);
    }

    public function testCanonicalizeList(): void
    {
        $input = [3, 1, 2];
        $canonical = ContentHash::canonicalize($input);

        // Lists should maintain order
        $this->assertEquals('[3,1,2]', $canonical);
    }

    public function testCanonicalizeEmpty(): void
    {
        $this->assertEquals('null', ContentHash::canonicalize(null));
        $this->assertEquals('[]', ContentHash::canonicalize([]));
        $this->assertEquals('{}', ContentHash::canonicalize(new \stdClass()));
    }

    public function testComputeAssetIdFormat(): void
    {
        $input = ['type' => 'Gene', 'id' => 'gene_test'];
        $assetId = ContentHash::computeAssetId($input);

        // Should start with sha256:
        $this->assertStringStartsWith('sha256:', $assetId);
        // Should be hex string after prefix
        $hashPart = substr($assetId, 7);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hashPart);
    }

    public function testComputeAssetIdDeterministic(): void
    {
        $input = ['type' => 'Gene', 'id' => 'gene_test'];
        $assetId1 = ContentHash::computeAssetId($input);
        $assetId2 = ContentHash::computeAssetId($input);

        // Should produce the same hash for the same input
        $this->assertEquals($assetId1, $assetId2);
    }

    public function testComputeAssetIdDifferentInputs(): void
    {
        $input1 = ['type' => 'Gene', 'id' => 'gene_a'];
        $input2 = ['type' => 'Gene', 'id' => 'gene_b'];

        $assetId1 = ContentHash::computeAssetId($input1);
        $assetId2 = ContentHash::computeAssetId($input2);

        // Different inputs should produce different hashes
        $this->assertNotEquals($assetId1, $assetId2);
    }

    public function testComputeAssetIdExcludesFields(): void
    {
        $input1 = ['type' => 'Gene', 'id' => 'gene_test', 'asset_id' => 'sha256:abc123'];
        $input2 = ['type' => 'Gene', 'id' => 'gene_test', 'asset_id' => 'different'];

        $assetId1 = ContentHash::computeAssetId($input1);
        $assetId2 = ContentHash::computeAssetId($input2);

        // Should produce same hash since asset_id is excluded
        $this->assertEquals($assetId1, $assetId2);
    }

    public function testVerifyAssetIdValid(): void
    {
        $input = ['type' => 'Gene', 'id' => 'gene_test'];
        $assetId = ContentHash::computeAssetId($input);
        $input['asset_id'] = $assetId;

        $this->assertTrue(ContentHash::verifyAssetId($input));
    }

    public function testVerifyAssetIdInvalid(): void
    {
        $input = ['type' => 'Gene', 'id' => 'gene_test'];
        $input['asset_id'] = 'sha256:' . str_repeat('00', 32);

        $this->assertFalse(ContentHash::verifyAssetId($input));
    }

    public function testVerifyAssetIdMissing(): void
    {
        $input = ['type' => 'Gene', 'id' => 'gene_test'];

        // Should return false when asset_id is missing
        $this->assertFalse(ContentHash::verifyAssetId($input));
    }

    public function testSchemaVersionConstant(): void
    {
        $this->assertEquals('1.6.0', ContentHash::SCHEMA_VERSION);
    }

    public function testGenerateLocalId(): void
    {
        $id1 = ContentHash::generateLocalId('gene');
        $id2 = ContentHash::generateLocalId('gene');

        // Should start with prefix
        $this->assertStringStartsWith('gene_', $id1);
        $this->assertStringStartsWith('gene_', $id2);

        // Should be unique
        $this->assertNotEquals($id1, $id2);
    }
}
