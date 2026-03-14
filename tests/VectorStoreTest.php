<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\VectorStore;
use PHPUnit\Framework\TestCase;

/**
 * VectorStore tests - FTS5 full-text search storage.
 */
final class VectorStoreTest extends TestCase
{
    private Database $db;
    private VectorStore $store;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->store = new VectorStore($this->db);
    }

    // -------------------------------------------------------------------------
    // Store Tests
    // -------------------------------------------------------------------------

    public function testStoreBasic(): void
    {
        $result = $this->store->store('gene', 'test-1', 'Hello world');

        $this->assertTrue($result);
        $this->assertSame(1, $this->store->count());
    }

    public function testStoreMultipleItems(): void
    {
        $this->store->store('gene', 'test-1', 'First text');
        $this->store->store('gene', 'test-2', 'Second text');
        $this->store->store('capsule', 'test-3', 'Third text');

        $this->assertSame(3, $this->store->count());
    }

    public function testStoreOverwrites(): void
    {
        $this->store->store('gene', 'test-1', 'Original text');
        $this->store->store('gene', 'test-1', 'Updated text');

        $this->assertSame(1, $this->store->count());
        $this->assertSame('Updated text', $this->store->getText('test-1'));
    }

    public function testStoreWithMetadata(): void
    {
        $this->store->store('gene', 'test-1', 'Text', ['category' => 'preference', 'score' => 0.9]);

        $metadata = $this->store->getMetadata('test-1');

        $this->assertSame('gene', $metadata['type']);
        $this->assertSame('preference', $metadata['category']);
        $this->assertSame(0.9, $metadata['score']);
    }

    public function testStoreChineseText(): void
    {
        $result = $this->store->store('capsule', 'test-cn', '这是一个中文测试');

        $this->assertTrue($result);
        $this->assertSame('这是一个中文测试', $this->store->getText('test-cn'));
    }

    // -------------------------------------------------------------------------
    // Search Tests
    // -------------------------------------------------------------------------

    public function testSearchReturnsMatchingItems(): void
    {
        $this->store->store('gene', 'test-1', 'apple fruit');
        $this->store->store('gene', 'test-2', 'banana fruit');
        $this->store->store('gene', 'test-3', 'car vehicle');

        $results = $this->store->search('apple', limit: 10, minScore: 0.0);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('test-1', $results);
    }

    public function testSearchRespectsLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->store->store('gene', "test-$i", "text number $i");
        }

        $results = $this->store->search('text', limit: 5, minScore: 0.0);

        $this->assertLessThanOrEqual(5, count($results));
    }

    public function testSearchByTypeFiltersResults(): void
    {
        $this->store->store('gene', 'test-1', 'apple fruit');
        $this->store->store('capsule', 'test-2', 'banana fruit');
        $this->store->store('event', 'test-3', 'cherry fruit');

        $results = $this->store->searchByType('fruit', 'capsule', limit: 10, minScore: 0.0);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('test-2', $results);
    }

    public function testSearchChineseText(): void
    {
        $this->store->store('capsule', 'test-1', '今天天气很好');
        $this->store->store('capsule', 'test-2', '明天会下雨');
        $this->store->store('capsule', 'test-3', '我喜欢吃苹果');

        // 搜索单个中文字
        $results = $this->store->search('天气', limit: 10, minScore: 0.0);

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('test-1', $results);
    }

    // -------------------------------------------------------------------------
    // Similarity Tests
    // -------------------------------------------------------------------------

    public function testSimilarityBetweenTexts(): void
    {
        $this->store->store('gene', 'test-1', 'hello world');
        $this->store->store('gene', 'test-2', 'hello world'); // Same text

        $similarity = $this->store->similarity('test-1', 'test-2');

        $this->assertNotNull($similarity);
        $this->assertSame(1.0, $similarity); // Identical text
    }

    public function testSimilarityReturnsNullForMissingId(): void
    {
        $this->store->store('gene', 'test-1', 'hello');

        $similarity = $this->store->similarity('test-1', 'nonexistent');

        $this->assertNull($similarity);
    }

    // -------------------------------------------------------------------------
    // Get Tests
    // -------------------------------------------------------------------------

    public function testGetVectorReturnsNull(): void
    {
        $this->store->store('gene', 'test-1', 'hello');

        // Vector embedding is no longer supported
        $vector = $this->store->getVector('test-1');

        $this->assertNull($vector);
    }

    public function testGetText(): void
    {
        $this->store->store('gene', 'test-1', 'Hello world');

        $this->assertSame('Hello world', $this->store->getText('test-1'));
    }

    public function testGetTextReturnsNullForMissing(): void
    {
        $this->assertNull($this->store->getText('nonexistent'));
    }

    public function testGetMetadata(): void
    {
        $this->store->store('gene', 'test-1', 'Text', ['key' => 'value']);

        $metadata = $this->store->getMetadata('test-1');

        $this->assertSame('gene', $metadata['type']);
        $this->assertSame('value', $metadata['key']);
    }

    public function testGetMetadataReturnsNullForMissing(): void
    {
        $this->assertNull($this->store->getMetadata('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // Delete Tests
    // -------------------------------------------------------------------------

    public function testDelete(): void
    {
        $this->store->store('gene', 'test-1', 'Hello');
        $this->assertSame(1, $this->store->count());

        $result = $this->store->delete('test-1');

        $this->assertTrue($result);
        $this->assertSame(0, $this->store->count());
    }

    public function testDeleteByType(): void
    {
        $this->store->store('gene', 'test-1', 'One');
        $this->store->store('gene', 'test-2', 'Two');
        $this->store->store('capsule', 'test-3', 'Three');

        $deleted = $this->store->deleteByType('gene');

        $this->assertSame(2, $deleted);
        $this->assertSame(1, $this->store->count());
        $this->assertNotNull($this->store->getText('test-3'));
    }

    // -------------------------------------------------------------------------
    // Count Tests
    // -------------------------------------------------------------------------

    public function testCount(): void
    {
        $this->assertSame(0, $this->store->count());

        $this->store->store('gene', 'test-1', 'One');
        $this->assertSame(1, $this->store->count());

        $this->store->store('gene', 'test-2', 'Two');
        $this->assertSame(2, $this->store->count());
    }

    public function testCountWithVectors(): void
    {
        $this->store->store('gene', 'test-1', 'One');
        $this->store->store('gene', 'test-2', 'Two');

        // countWithVectors now returns same as count (no vectors)
        $this->assertSame(2, $this->store->countWithVectors());
    }

    // -------------------------------------------------------------------------
    // Embedder Tests (deprecated behavior)
    // -------------------------------------------------------------------------

    public function testHasEmbedderReturnsFalse(): void
    {
        $this->assertFalse($this->store->hasEmbedder());
    }

    public function testGetDimensionReturnsZero(): void
    {
        $this->assertSame(0, $this->store->getDimension());
    }

    public function testClearCache(): void
    {
        $this->store->store('gene', 'test-1', 'Hello');

        // clearCache is now a no-op
        $this->store->clearCache();

        $this->assertSame(1, $this->store->count());
    }

    // -------------------------------------------------------------------------
    // Chinese Tokenization Tests
    // -------------------------------------------------------------------------

    public function testTokenizeChinese(): void
    {
        $result = Database::tokenizeChinese('你好世界');
        $this->assertSame('你 好 世 界', $result);
    }

    public function testTokenizeMixedText(): void
    {
        $result = Database::tokenizeChinese('Hello 你好 World 世界');
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('你', $result);
        $this->assertStringContainsString('好', $result);
    }

    public function testTokenizeWithPunctuation(): void
    {
        $result = Database::tokenizeChinese('你好，世界！');
        $this->assertStringContainsString('你', $result);
        $this->assertStringContainsString('好', $result);
        $this->assertStringContainsString('世', $result);
        $this->assertStringContainsString('界', $result);
    }
}
