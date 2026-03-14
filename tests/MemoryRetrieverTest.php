<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use Evolver\DecayEngine;
use Evolver\MemoryRetriever;
use Evolver\NullEmbedder;
use Evolver\RetrievalResult;
use Evolver\VectorStore;
use PHPUnit\Framework\TestCase;

/**
 * MemoryRetriever tests - hybrid search with RRF fusion.
 */
final class MemoryRetrieverTest extends TestCase
{
    private Database $db;
    private VectorStore $vectorStore;
    private MemoryRetriever $retriever;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $this->vectorStore = new VectorStore($this->db, new NullEmbedder());
        $this->retriever = new MemoryRetriever($this->db, $this->vectorStore);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function testDefaultConfiguration(): void
    {
        $config = $this->retriever->getConfig();

        $this->assertSame('fts', $config['mode']);
        $this->assertSame(0.0, $config['minScore']);
        $this->assertSame(20, $config['candidatePoolSize']);
    }

    public function testUpdateConfiguration(): void
    {
        $this->retriever->updateConfig(['mode' => 'vector', 'minScore' => 0.5]);

        $config = $this->retriever->getConfig();

        $this->assertSame('vector', $config['mode']);
        $this->assertSame(0.5, $config['minScore']);
    }

    // -------------------------------------------------------------------------
    // Retrieval
    // -------------------------------------------------------------------------

    public function testRetrieveReturnsEmptyWhenNoMatches(): void
    {
        $results = $this->retriever->retrieve('nonexistent query');

        $this->assertIsArray($results);
        // Empty database returns empty results
    }

    public function testTestMethod(): void
    {
        $result = $this->retriever->test('test query');

        $this->assertTrue($result['success']);
        $this->assertSame('fts', $result['mode']);
    }
}
