<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\RetrievalResult;
use Evolver\SmartMetadata;
use PHPUnit\Framework\TestCase;

/**
 * RetrievalResult tests.
 */
final class RetrievalResultTest extends TestCase
{
    public function testGetId(): void
    {
        $result = new RetrievalResult(['id' => 'test-id-123'], 0.8);

        $this->assertSame('test-id-123', $result->getId());
    }

    public function testGetText(): void
    {
        $result = new RetrievalResult(['text' => 'Test memory text'], 0.8);

        $this->assertSame('Test memory text', $result->getText());
    }

    public function testGetCategory(): void
    {
        $result = new RetrievalResult(['category' => 'fact'], 0.8);

        $this->assertSame('fact', $result->getCategory());
    }

    public function testGetScope(): void
    {
        $result = new RetrievalResult(['scope' => 'project-x'], 0.8);

        $this->assertSame('project-x', $result->getScope());
    }

    public function testGetDefaultScope(): void
    {
        $result = new RetrievalResult([], 0.8);

        $this->assertSame('global', $result->getScope());
    }

    public function testGetImportance(): void
    {
        $result = new RetrievalResult(['importance' => 0.9], 0.8);

        $this->assertSame(0.9, $result->getImportance());
    }

    public function testGetDefaultImportance(): void
    {
        $result = new RetrievalResult([], 0.8);

        $this->assertSame(0.7, $result->getImportance());
    }

    public function testGetMetadata(): void
    {
        $metadata = '{"abstract":"Test abstract","tier":"core"}';
        $result = new RetrievalResult(['metadata' => $metadata], 0.8);

        $meta = $result->getMetadata();

        $this->assertSame('Test abstract', $meta->abstract);
        $this->assertSame('core', $meta->tier);
    }

    public function testGetSourceScores(): void
    {
        $sources = [
            'vector' => ['score' => 0.85, 'rank' => 1],
            'bm25' => ['score' => 0.72, 'rank' => 2],
            'fused' => ['score' => 0.79],
        ];

        $result = new RetrievalResult([], 0.79, $sources);

        $this->assertSame(0.85, $result->getVectorScore());
        $this->assertSame(0.72, $result->getBM25Score());
        $this->assertSame(0.79, $result->getFusedScore());
    }

    public function testGetNullSourceScores(): void
    {
        $result = new RetrievalResult([], 0.8, []);

        $this->assertNull($result->getVectorScore());
        $this->assertNull($result->getBM25Score());
        $this->assertNull($result->getFusedScore());
    }

    public function testToArray(): void
    {
        $result = new RetrievalResult(
            ['id' => 'test-id', 'text' => 'Test', 'category' => 'fact', 'scope' => 'global', 'importance' => 0.8],
            0.75,
            ['vector' => ['score' => 0.75]]
        );

        $array = $result->toArray();

        $this->assertSame('test-id', $array['id']);
        $this->assertSame('Test', $array['text']);
        $this->assertSame(0.75, $array['score']);
        $this->assertSame('fact', $array['category']);
        $this->assertSame('global', $array['scope']);
        $this->assertSame(0.8, $array['importance']);
    }
}
