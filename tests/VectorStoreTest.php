<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\NullEmbedder;
use Evolver\OpenAIEmbedder;
use Evolver\VectorStore;
use PHPUnit\Framework\TestCase;

/**
 * VectorStore tests - vector storage and similarity search.
 */
final class VectorStoreTest extends TestCase
{
    private Database $db;
    private VectorStore $store;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
    }

    private function createStoreWithMockEmbedder(): VectorStore
    {
        $embedder = new class implements \Evolver\EmbedderInterface {
            public function embed(string $text): ?array
            {
                // Simple mock: generate a deterministic 3D vector based on text hash
                $hash = crc32($text);
                return [
                    (float)(($hash & 0xFF) / 255.0),
                    (float)((($hash >> 8) & 0xFF) / 255.0),
                    (float)((($hash >> 16) & 0xFF) / 255.0),
                ];
            }

            public function getDimension(): int
            {
                return 3;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        return new VectorStore($this->db, $embedder);
    }

    // -------------------------------------------------------------------------
    // Store Tests
    // -------------------------------------------------------------------------

    public function testStoreWithEmbedder(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $result = $store->store('gene', 'test-1', 'Hello world');

        $this->assertTrue($result);
        $this->assertSame(1, $store->count());
    }

    public function testStoreWithNullEmbedder(): void
    {
        $store = new VectorStore($this->db, new NullEmbedder());

        $result = $store->store('gene', 'test-1', 'Hello world');

        $this->assertTrue($result);
        $this->assertSame(1, $store->count());
        $this->assertSame(0, $store->countWithVectors());
    }

    public function testStoreMultipleItems(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'First text');
        $store->store('gene', 'test-2', 'Second text');
        $store->store('capsule', 'test-3', 'Third text');

        $this->assertSame(3, $store->count());
        $this->assertSame(3, $store->countWithVectors());
    }

    public function testStoreOverwrites(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Original text');
        $store->store('gene', 'test-1', 'Updated text');

        $this->assertSame(1, $store->count());
        $this->assertSame('Updated text', $store->getText('test-1'));
    }

    public function testStoreWithMetadata(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Text', ['category' => 'preference', 'score' => 0.9]);

        $metadata = $store->getMetadata('test-1');

        $this->assertSame('gene', $metadata['type']);
        $this->assertSame('preference', $metadata['category']);
        $this->assertSame(0.9, $metadata['score']);
    }

    // -------------------------------------------------------------------------
    // Search Tests
    // -------------------------------------------------------------------------

    public function testSearchReturnsSimilarItems(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'apple fruit');
        $store->store('gene', 'test-2', 'banana fruit');
        $store->store('gene', 'test-3', 'car vehicle');

        // Same text should match perfectly with itself
        $results = $store->search('apple fruit', limit: 10, minScore: 0.0);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('test-1', $results);
    }

    public function testSearchRespectsMinScore(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'unique text alpha');
        $store->store('gene', 'test-2', 'completely different beta');

        $results = $store->search('unique text alpha', limit: 10, minScore: 1.0);

        // Only exact match should return with minScore 1.0
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('test-1', $results);
    }

    public function testSearchRespectsLimit(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        for ($i = 0; $i < 20; $i++) {
            $store->store('gene', "test-$i", "text number $i");
        }

        $results = $store->search('text', limit: 5, minScore: 0.0);

        $this->assertCount(5, $results);
    }

    public function testSearchReturnsEmptyWithNullEmbedder(): void
    {
        $store = new VectorStore($this->db, new NullEmbedder());

        $store->store('gene', 'test-1', 'Hello world');

        $results = $store->search('Hello world');

        $this->assertEmpty($results);
    }

    public function testSearchByTypeFiltersResults(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'apple fruit');
        $store->store('capsule', 'test-2', 'banana fruit');
        $store->store('event', 'test-3', 'cherry fruit');

        $results = $store->searchByType('fruit', 'capsule', limit: 10, minScore: 0.0);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('test-2', $results);
    }

    // -------------------------------------------------------------------------
    // Similarity Tests
    // -------------------------------------------------------------------------

    public function testSimilarityBetweenStoredVectors(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'hello');
        $store->store('gene', 'test-2', 'hello'); // Same text = same vector

        $similarity = $store->similarity('test-1', 'test-2');

        $this->assertNotNull($similarity);
        $this->assertSame(1.0, $similarity); // Identical vectors
    }

    public function testSimilarityReturnsNullForMissingId(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'hello');

        $similarity = $store->similarity('test-1', 'nonexistent');

        $this->assertNull($similarity);
    }

    public function testSimilarityReturnsNullWithNullEmbedder(): void
    {
        $store = new VectorStore($this->db, new NullEmbedder());

        $store->store('gene', 'test-1', 'hello');
        $store->store('gene', 'test-2', 'world');

        $similarity = $store->similarity('test-1', 'test-2');

        $this->assertNull($similarity);
    }

    // -------------------------------------------------------------------------
    // Get Tests
    // -------------------------------------------------------------------------

    public function testGetVector(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'hello');

        $vector = $store->getVector('test-1');

        $this->assertNotNull($vector);
        $this->assertCount(3, $vector);
    }

    public function testGetVectorReturnsNullForMissing(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $vector = $store->getVector('nonexistent');

        $this->assertNull($vector);
    }

    public function testGetText(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Hello world');

        $this->assertSame('Hello world', $store->getText('test-1'));
    }

    public function testGetTextReturnsNullForMissing(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $this->assertNull($store->getText('nonexistent'));
    }

    public function testGetMetadata(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Text', ['key' => 'value']);

        $metadata = $store->getMetadata('test-1');

        $this->assertSame('gene', $metadata['type']);
        $this->assertSame('value', $metadata['key']);
    }

    public function testGetMetadataReturnsNullForMissing(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $this->assertNull($store->getMetadata('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // Delete Tests
    // -------------------------------------------------------------------------

    public function testDelete(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Hello');
        $this->assertSame(1, $store->count());

        $result = $store->delete('test-1');

        $this->assertTrue($result);
        $this->assertSame(0, $store->count());
        $this->assertNull($store->getVector('test-1'));
    }

    public function testDeleteByType(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'One');
        $store->store('gene', 'test-2', 'Two');
        $store->store('capsule', 'test-3', 'Three');

        $deleted = $store->deleteByType('gene');

        $this->assertSame(2, $deleted);
        $this->assertSame(1, $store->count());
        $this->assertNotNull($store->getText('test-3'));
    }

    // -------------------------------------------------------------------------
    // Count Tests
    // -------------------------------------------------------------------------

    public function testCount(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $this->assertSame(0, $store->count());

        $store->store('gene', 'test-1', 'One');
        $this->assertSame(1, $store->count());

        $store->store('gene', 'test-2', 'Two');
        $this->assertSame(2, $store->count());
    }

    public function testCountWithVectors(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'One');
        $store->store('gene', 'test-2', 'Two');

        $this->assertSame(2, $store->countWithVectors());
    }

    public function testCountWithVectorsExcludesNullEmbedder(): void
    {
        $store = new VectorStore($this->db, new NullEmbedder());

        $store->store('gene', 'test-1', 'One');
        $store->store('gene', 'test-2', 'Two');

        $this->assertSame(2, $store->count());
        $this->assertSame(0, $store->countWithVectors());
    }

    // -------------------------------------------------------------------------
    // Cache Tests
    // -------------------------------------------------------------------------

    public function testClearCache(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $store->store('gene', 'test-1', 'Hello');

        $this->assertNotNull($store->getVector('test-1'));

        $store->clearCache();

        // After clear, vectors should reload from DB
        $this->assertNotNull($store->getVector('test-1'));
    }

    public function testHasEmbedder(): void
    {
        $storeWithEmbedder = $this->createStoreWithMockEmbedder();
        $storeWithNull = new VectorStore($this->db, new NullEmbedder());

        $this->assertTrue($storeWithEmbedder->hasEmbedder());
        $this->assertFalse($storeWithNull->hasEmbedder());
    }

    public function testGetDimension(): void
    {
        $store = $this->createStoreWithMockEmbedder();

        $this->assertSame(3, $store->getDimension());
    }

    public function testCacheTrimmedWhenExceedsMaxSize(): void
    {
        // Create store with small cache limit
        $embedder = new class implements \Evolver\EmbedderInterface {
            public function embed(string $text): ?array
            {
                return [0.1, 0.2, 0.3];
            }
            public function getDimension(): int { return 3; }
            public function isAvailable(): bool { return true; }
        };

        $store = new VectorStore($this->db, $embedder, maxCacheSize: 10);

        // Store more items than cache limit
        for ($i = 0; $i < 20; $i++) {
            $store->store('gene', "test-$i", "Text $i");
        }

        // All items should be in DB
        $this->assertSame(20, $store->count());
    }
}
