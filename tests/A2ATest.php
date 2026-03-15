<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\A2A;
use Evolver\GepAssetStore;
use PHPUnit\Framework\TestCase;

final class A2ATest extends TestCase
{
    public function testIsAllowedA2AAssetWithGene(): void
    {
        $gene = ['type' => 'Gene', 'id' => 'test_gene'];
        $this->assertTrue(A2A::isAllowedA2AAsset($gene));
    }

    public function testIsAllowedA2AAssetWithCapsule(): void
    {
        $capsule = ['type' => 'Capsule', 'id' => 'test_capsule'];
        $this->assertTrue(A2A::isAllowedA2AAsset($capsule));
    }

    public function testIsAllowedA2AAssetWithEvolutionEvent(): void
    {
        $event = ['type' => 'EvolutionEvent', 'id' => 'test_event'];
        $this->assertTrue(A2A::isAllowedA2AAsset($event));
    }

    public function testIsAllowedA2AAssetWithInvalidType(): void
    {
        $invalid = ['type' => 'Invalid', 'id' => 'test'];
        $this->assertFalse(A2A::isAllowedA2AAsset($invalid));
    }

    public function testIsAllowedA2AAssetWithNonArray(): void
    {
        $this->assertFalse(A2A::isAllowedA2AAsset('string'));
        $this->assertFalse(A2A::isAllowedA2AAsset(null));
        $this->assertFalse(A2A::isAllowedA2AAsset(123));
    }

    public function testGetBlastRadiusLimitsDefaults(): void
    {
        $limits = A2A::getBlastRadiusLimits();
        $this->assertArrayHasKey('maxFiles', $limits);
        $this->assertArrayHasKey('maxLines', $limits);
        $this->assertEquals(5, $limits['maxFiles']);
        $this->assertEquals(200, $limits['maxLines']);
    }

    public function testIsBlastRadiusSafeWithNull(): void
    {
        $this->assertTrue(A2A::isBlastRadiusSafe(null));
    }

    public function testIsBlastRadiusSafeWithSafeValues(): void
    {
        $this->assertTrue(A2A::isBlastRadiusSafe(['files' => 3, 'lines' => 100]));
    }

    public function testIsBlastRadiusSafeWithUnsafeFiles(): void
    {
        $this->assertFalse(A2A::isBlastRadiusSafe(['files' => 10, 'lines' => 100]));
    }

    public function testIsBlastRadiusSafeWithUnsafeLines(): void
    {
        $this->assertFalse(A2A::isBlastRadiusSafe(['files' => 3, 'lines' => 500]));
    }

    public function testLowerConfidenceWithCapsule(): void
    {
        $capsule = ['type' => 'Capsule', 'confidence' => 0.9];
        $result = A2A::lowerConfidence($capsule);

        $this->assertIsArray($result);
        $this->assertEquals('external_candidate', $result['a2a']['status']);
        $this->assertEquals(0.54, $result['confidence'], '', 0.01); // 0.9 * 0.6
    }

    public function testLowerConfidenceWithGene(): void
    {
        $gene = ['type' => 'Gene', 'id' => 'test'];
        $result = A2A::lowerConfidence($gene);

        $this->assertIsArray($result);
        $this->assertEquals('external_candidate', $result['a2a']['status']);
    }

    public function testLowerConfidenceWithInvalidAsset(): void
    {
        $invalid = ['type' => 'Invalid'];
        $this->assertNull(A2A::lowerConfidence($invalid));
    }

    public function testIsGeneBroadcastEligibleWithValidGene(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'test_gene',
            'strategy' => ['step1' => 'do something'],
            'validation' => ['check1' => 'verify'],
        ];
        $this->assertTrue(A2A::isGeneBroadcastEligible($gene));
    }

    public function testIsGeneBroadcastEligibleWithMissingStrategy(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'test_gene',
            'validation' => ['check1' => 'verify'],
        ];
        $this->assertFalse(A2A::isGeneBroadcastEligible($gene));
    }

    public function testIsGeneBroadcastEligibleWithMissingValidation(): void
    {
        $gene = [
            'type' => 'Gene',
            'id' => 'test_gene',
            'strategy' => ['step1' => 'do something'],
        ];
        $this->assertFalse(A2A::isGeneBroadcastEligible($gene));
    }

    public function testParseA2AInputWithJsonArray(): void
    {
        $input = '[{"type":"Gene","id":"g1"},{"type":"Capsule","id":"c1"}]';
        $result = A2A::parseA2AInput($input);

        $this->assertCount(2, $result);
    }

    public function testParseA2AInputWithEmptyString(): void
    {
        $this->assertEmpty(A2A::parseA2AInput(''));
    }

    public function testParseA2AInputWithNdjson(): void
    {
        $input = "{\"type\":\"Gene\",\"id\":\"g1\"}\n{\"type\":\"Capsule\",\"id\":\"c1\"}";
        $result = A2A::parseA2AInput($input);

        $this->assertGreaterThanOrEqual(1, count($result));
    }

    public function testExportEligibleGenes(): void
    {
        $genes = [
            [
                'type' => 'Gene',
                'id' => 'valid_gene',
                'strategy' => ['step1' => 'do'],
                'validation' => ['check1' => 'verify'],
            ],
            [
                'type' => 'Gene',
                'id' => 'invalid_gene',
                // Missing strategy and validation
            ],
        ];

        $result = A2A::exportEligibleGenes(['genes' => $genes]);
        $this->assertCount(1, $result);
        $this->assertEquals('valid_gene', $result[0]['id']);
    }
}
