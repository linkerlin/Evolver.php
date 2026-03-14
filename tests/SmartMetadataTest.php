<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SmartMetadata;
use PHPUnit\Framework\TestCase;

/**
 * SmartMetadata tests - covers L0/L1/L2 hierarchy, lifecycle, versioning.
 */
final class SmartMetadataTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction & Serialization
    // -------------------------------------------------------------------------

    public function testEmptyConstruction(): void
    {
        $meta = SmartMetadata::empty();

        $this->assertSame('', $meta->abstract);
        $this->assertSame('', $meta->overview);
        $this->assertSame('', $meta->content);
        $this->assertSame('working', $meta->tier);
        $this->assertSame(0, $meta->accessCount);
        $this->assertSame(0.7, $meta->confidence);
    }

    public function testConstructionFromArray(): void
    {
        $meta = new SmartMetadata([
            'abstract' => 'Test abstract',
            'tier' => 'core',
            'confidence' => 0.95,
        ]);

        $this->assertSame('Test abstract', $meta->abstract);
        $this->assertSame('core', $meta->tier);
        $this->assertSame(0.95, $meta->confidence);
    }

    public function testFromAbstract(): void
    {
        $meta = SmartMetadata::fromAbstract('User prefers dark mode');

        $this->assertSame('User prefers dark mode', $meta->abstract);
        $this->assertSame('', $meta->overview);
        $this->assertSame('', $meta->content);
    }

    public function testFromHierarchy(): void
    {
        $meta = SmartMetadata::fromHierarchy(
            'User prefers dark mode',
            '## Preference: UI Theme\n- Dark mode enabled',
            'The user has explicitly stated a preference for dark mode UI theme...'
        );

        $this->assertSame('User prefers dark mode', $meta->abstract);
        $this->assertStringContainsString('## Preference', $meta->overview);
        $this->assertStringContainsString('explicitly stated', $meta->content);
    }

    public function testToJsonFromJsonRoundtrip(): void
    {
        $original = new SmartMetadata([
            'abstract' => 'Test',
            'tier' => 'core',
            'accessCount' => 5,
            'confidence' => 0.9,
            'factKey' => 'user.theme',
        ]);

        $json = $original->toJson();
        $restored = SmartMetadata::fromJson($json);

        $this->assertSame($original->abstract, $restored->abstract);
        $this->assertSame($original->tier, $restored->tier);
        $this->assertSame($original->accessCount, $restored->accessCount);
        $this->assertSame($original->confidence, $restored->confidence);
        $this->assertSame($original->factKey, $restored->factKey);
    }

    public function testFromJsonHandlesInvalidJson(): void
    {
        $meta = SmartMetadata::fromJson('not valid json');

        // Should return empty instance
        $this->assertSame('', $meta->abstract);
        $this->assertSame('working', $meta->tier);
    }

    public function testToArray(): void
    {
        $meta = new SmartMetadata([
            'abstract' => 'Test',
            'tier' => 'peripheral',
        ]);

        $array = $meta->toArray();

        $this->assertArrayHasKey('abstract', $array);
        $this->assertArrayHasKey('overview', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('tier', $array);
        $this->assertArrayHasKey('accessCount', $array);
        $this->assertArrayHasKey('confidence', $array);
        $this->assertSame('Test', $array['abstract']);
        $this->assertSame('peripheral', $array['tier']);
    }

    // -------------------------------------------------------------------------
    // Tier Checks
    // -------------------------------------------------------------------------

    public function testIsCore(): void
    {
        $core = new SmartMetadata(['tier' => 'core']);
        $working = new SmartMetadata(['tier' => 'working']);

        $this->assertTrue($core->isCore());
        $this->assertFalse($working->isCore());
    }

    public function testIsWorking(): void
    {
        $working = new SmartMetadata(['tier' => 'working']);
        $peripheral = new SmartMetadata(['tier' => 'peripheral']);

        $this->assertTrue($working->isWorking());
        $this->assertFalse($peripheral->isWorking());
    }

    public function testIsPeripheral(): void
    {
        $peripheral = new SmartMetadata(['tier' => 'peripheral']);
        $core = new SmartMetadata(['tier' => 'core']);

        $this->assertTrue($peripheral->isPeripheral());
        $this->assertFalse($core->isPeripheral());
    }

    public function testGetDecayFloor(): void
    {
        $this->assertSame(0.9, (new SmartMetadata(['tier' => 'core']))->getDecayFloor());
        $this->assertSame(0.7, (new SmartMetadata(['tier' => 'working']))->getDecayFloor());
        $this->assertSame(0.5, (new SmartMetadata(['tier' => 'peripheral']))->getDecayFloor());
        $this->assertSame(0.5, (new SmartMetadata(['tier' => 'unknown']))->getDecayFloor());
    }

    // -------------------------------------------------------------------------
    // Tier Transitions
    // -------------------------------------------------------------------------

    public function testPromoteTo(): void
    {
        $meta = new SmartMetadata(['tier' => 'peripheral']);

        $result = $meta->promoteTo('working');

        $this->assertTrue($result);
        $this->assertSame('working', $meta->tier);
    }

    public function testPromoteToCoreFromPeripheral(): void
    {
        $meta = new SmartMetadata(['tier' => 'peripheral']);

        $result = $meta->promoteTo('core');

        $this->assertTrue($result);
        $this->assertSame('core', $meta->tier);
    }

    public function testPromoteToSameTierFails(): void
    {
        $meta = new SmartMetadata(['tier' => 'working']);

        $result = $meta->promoteTo('working');

        $this->assertFalse($result);
        $this->assertSame('working', $meta->tier);
    }

    public function testPromoteToLowerTierFails(): void
    {
        $meta = new SmartMetadata(['tier' => 'core']);

        $result = $meta->promoteTo('working');

        $this->assertFalse($result);
        $this->assertSame('core', $meta->tier);
    }

    public function testPromoteToInvalidTierFails(): void
    {
        $meta = new SmartMetadata(['tier' => 'working']);

        $result = $meta->promoteTo('invalid');

        $this->assertFalse($result);
        $this->assertSame('working', $meta->tier);
    }

    public function testDemoteTo(): void
    {
        $meta = new SmartMetadata(['tier' => 'core']);

        $result = $meta->demoteTo('working');

        $this->assertTrue($result);
        $this->assertSame('working', $meta->tier);
    }

    public function testDemoteToPeripheralFromCore(): void
    {
        $meta = new SmartMetadata(['tier' => 'core']);

        $result = $meta->demoteTo('peripheral');

        $this->assertTrue($result);
        $this->assertSame('peripheral', $meta->tier);
    }

    public function testDemoteToSameTierFails(): void
    {
        $meta = new SmartMetadata(['tier' => 'working']);

        $result = $meta->demoteTo('working');

        $this->assertFalse($result);
        $this->assertSame('working', $meta->tier);
    }

    public function testDemoteToHigherTierFails(): void
    {
        $meta = new SmartMetadata(['tier' => 'peripheral']);

        $result = $meta->demoteTo('working');

        $this->assertFalse($result);
        $this->assertSame('peripheral', $meta->tier);
    }

    // -------------------------------------------------------------------------
    // Access Recording
    // -------------------------------------------------------------------------

    public function testRecordAccess(): void
    {
        $meta = new SmartMetadata(['accessCount' => 0]);
        $timestamp = 1700000000000;

        $meta->recordAccess($timestamp);

        $this->assertSame(1, $meta->accessCount);
        $this->assertSame($timestamp, $meta->lastAccessedAt);
    }

    public function testRecordAccessMultipleTimes(): void
    {
        $meta = new SmartMetadata(['accessCount' => 0]);

        $meta->recordAccess(1000);
        $meta->recordAccess(2000);
        $meta->recordAccess(3000);

        $this->assertSame(3, $meta->accessCount);
        $this->assertSame(3000, $meta->lastAccessedAt);
    }

    public function testRecordAccessAutoTimestamp(): void
    {
        $meta = new SmartMetadata();

        $before = (int)(microtime(true) * 1000);
        $meta->recordAccess();
        $after = (int)(microtime(true) * 1000);

        $this->assertGreaterThanOrEqual($before, $meta->lastAccessedAt);
        $this->assertLessThanOrEqual($after, $meta->lastAccessedAt);
    }

    // -------------------------------------------------------------------------
    // Temporal Versioning
    // -------------------------------------------------------------------------

    public function testIsInvalidated(): void
    {
        $valid = new SmartMetadata();
        $invalidated = new SmartMetadata(['invalidatedAt' => 1700000000000]);

        $this->assertFalse($valid->isInvalidated());
        $this->assertTrue($invalidated->isInvalidated());
    }

    public function testHasSuperseded(): void
    {
        $normal = new SmartMetadata();
        $superseder = new SmartMetadata(['supersedes' => 'old-memory-id']);

        $this->assertFalse($normal->hasSuperseded());
        $this->assertTrue($superseder->hasSuperseded());
    }

    public function testIsSuperseded(): void
    {
        $normal = new SmartMetadata();
        $superseded = new SmartMetadata(['supersededBy' => 'new-memory-id']);

        $this->assertFalse($normal->isSuperseded());
        $this->assertTrue($superseded->isSuperseded());
    }

    public function testInvalidate(): void
    {
        $meta = new SmartMetadata();
        $timestamp = 1700000000000;

        $meta->invalidate($timestamp, 'new-memory-id');

        $this->assertSame($timestamp, $meta->invalidatedAt);
        $this->assertSame('new-memory-id', $meta->supersededBy);
        $this->assertTrue($meta->isInvalidated());
        $this->assertTrue($meta->isSuperseded());
    }

    public function testMarkAsSuperseding(): void
    {
        $meta = new SmartMetadata();

        $meta->markAsSuperseding('old-memory-id');

        $this->assertSame('old-memory-id', $meta->supersedes);
        $this->assertTrue($meta->hasSuperseded());
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function testAddRelation(): void
    {
        $meta = new SmartMetadata();

        $meta->addRelation('memory-123', 'supports', 0.8);

        $this->assertCount(1, $meta->relations);
        $this->assertSame('memory-123', $meta->relations[0]['targetId']);
        $this->assertSame('supports', $meta->relations[0]['type']);
        $this->assertSame(0.8, $meta->relations[0]['strength']);
    }

    public function testAddMultipleRelations(): void
    {
        $meta = new SmartMetadata();

        $meta->addRelation('mem-1', 'supports', 0.9);
        $meta->addRelation('mem-2', 'contradicts', 0.5);
        $meta->addRelation('mem-3', 'related', 1.0);

        $this->assertCount(3, $meta->relations);
    }

    // -------------------------------------------------------------------------
    // Support Info
    // -------------------------------------------------------------------------

    public function testAddSupport(): void
    {
        $meta = new SmartMetadata(['confidence' => 0.5]);

        $meta->addSupport('conversation-123', 1.0);

        $this->assertArrayHasKey('sources', $meta->supportInfo);
        $this->assertCount(1, $meta->supportInfo['sources']);
        $this->assertSame('conversation-123', $meta->supportInfo['sources'][0]['source']);
    }

    public function testAddSupportIncreasesConfidence(): void
    {
        $meta = new SmartMetadata(['confidence' => 0.5]);

        $meta->addSupport('source-1', 1.0);
        // 0.5 + (1.0 * 0.1) = 0.6
        $this->assertSame(0.6, $meta->confidence);

        $meta->addSupport('source-2', 1.0);
        // 0.5 + (2.0 * 0.1) = 0.7
        $this->assertSame(0.7, $meta->confidence);
    }

    public function testAddSupportCapsConfidenceAtOne(): void
    {
        $meta = new SmartMetadata(['confidence' => 0.5]);

        // Add many supports
        for ($i = 0; $i < 10; $i++) {
            $meta->addSupport("source-{$i}", 1.0);
        }

        $this->assertLessThanOrEqual(1.0, $meta->confidence);
    }

    // -------------------------------------------------------------------------
    // Text Extraction
    // -------------------------------------------------------------------------

    public function testGetSearchableTextPrefersAbstract(): void
    {
        $meta = new SmartMetadata([
            'abstract' => 'Short abstract',
            'overview' => 'Longer overview',
            'content' => 'Full content',
        ]);

        $this->assertSame('Short abstract', $meta->getSearchableText());
    }

    public function testGetSearchableTextFallsBackToOverview(): void
    {
        $meta = new SmartMetadata([
            'overview' => 'Longer overview',
            'content' => 'Full content',
        ]);

        $this->assertSame('Longer overview', $meta->getSearchableText());
    }

    public function testGetSearchableTextFallsBackToContent(): void
    {
        $meta = new SmartMetadata([
            'content' => 'Full content',
        ]);

        $this->assertSame('Full content', $meta->getSearchableText());
    }

    public function testGetContextTextPrefersOverview(): void
    {
        $meta = new SmartMetadata([
            'abstract' => 'Short abstract',
            'overview' => 'Longer overview',
            'content' => 'Full content',
        ]);

        $this->assertSame('Longer overview', $meta->getContextText());
    }

    public function testGetContextTextFallsBackToAbstract(): void
    {
        $meta = new SmartMetadata([
            'abstract' => 'Short abstract',
            'content' => 'Full content',
        ]);

        $this->assertSame('Short abstract', $meta->getContextText());
    }

    public function testGetContextTextFallsBackToContent(): void
    {
        $meta = new SmartMetadata([
            'content' => 'Full content',
        ]);

        $this->assertSame('Full content', $meta->getContextText());
    }

    // -------------------------------------------------------------------------
    // Clone with modifications
    // -------------------------------------------------------------------------

    public function testWithCreatesModifiedClone(): void
    {
        $original = new SmartMetadata([
            'abstract' => 'Original',
            'tier' => 'working',
        ]);

        $modified = $original->with([
            'tier' => 'core',
            'confidence' => 0.95,
        ]);

        $this->assertSame('Original', $original->abstract);
        $this->assertSame('working', $original->tier);

        $this->assertSame('Original', $modified->abstract);
        $this->assertSame('core', $modified->tier);
        $this->assertSame(0.95, $modified->confidence);
    }

    public function testWithIgnoresInvalidProperties(): void
    {
        $meta = new SmartMetadata(['abstract' => 'Test']);

        $result = $meta->with(['nonExistentProperty' => 'value']);

        $this->assertSame('Test', $result->abstract);
        $this->assertFalse(property_exists($result, 'nonExistentProperty'));
    }
}
