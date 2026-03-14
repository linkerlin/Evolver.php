<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\NullEmbedder;
use Evolver\OpenAIEmbedder;
use PHPUnit\Framework\TestCase;

/**
 * Embedder tests - NullEmbedder and OpenAIEmbedder.
 */
final class EmbedderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // NullEmbedder
    // -------------------------------------------------------------------------

    public function testNullEmbedderReturnsNull(): void
    {
        $embedder = new NullEmbedder();

        $this->assertNull($embedder->embed('test text'));
    }

    public function testNullEmbedderDimensionIsZero(): void
    {
        $embedder = new NullEmbedder();

        $this->assertSame(0, $embedder->getDimension());
    }

    public function testNullEmbedderIsNotAvailable(): void
    {
        $embedder = new NullEmbedder();

        $this->assertFalse($embedder->isAvailable());
    }

    // -------------------------------------------------------------------------
    // OpenAIEmbedder - Configuration
    // -------------------------------------------------------------------------

    public function testOpenAIEmbedderDefaultConfiguration(): void
    {
        $embedder = new OpenAIEmbedder('test-key');

        $this->assertSame('text-embedding-3-small', $embedder->getModel());
        $this->assertSame(1536, $embedder->getDimension());
    }

    public function testOpenAIEmbedderCustomConfiguration(): void
    {
        $embedder = new OpenAIEmbedder(
            apiKey: 'custom-key',
            model: 'text-embedding-3-large',
            dimensions: 3072,
            baseUrl: 'https://custom.api.com/v1',
            timeout: 60,
        );

        $this->assertSame('text-embedding-3-large', $embedder->getModel());
        $this->assertSame(3072, $embedder->getDimension());
    }

    public function testOpenAIEmbedderFromConfig(): void
    {
        $embedder = OpenAIEmbedder::fromConfig([
            'api_key' => 'config-key',
            'model' => 'text-embedding-3-large',
            'dimensions' => 2560,
        ]);

        $this->assertSame('text-embedding-3-large', $embedder->getModel());
        $this->assertSame(2560, $embedder->getDimension());
    }

    public function testOpenAIEmbedderIsAvailableWithKey(): void
    {
        $embedder = new OpenAIEmbedder('test-api-key');

        $this->assertTrue($embedder->isAvailable());
    }

    public function testOpenAIEmbedderIsNotAvailableWithoutKey(): void
    {
        // Skip if OPENAI_API_KEY is set in environment (constructor falls back to getenv)
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey !== false && $envKey !== '') {
            $this->markTestSkipped('OPENAI_API_KEY environment variable is set');
        }

        $embedder = new OpenAIEmbedder(null);

        $this->assertFalse($embedder->isAvailable());
    }

    public function testOpenAIEmbedderIsNotAvailableWithEmptyKey(): void
    {
        $embedder = new OpenAIEmbedder('');

        $this->assertFalse($embedder->isAvailable());
    }

    public function testOpenAIEmbedderReturnsNullWhenNotAvailable(): void
    {
        // Skip if OPENAI_API_KEY is set in environment
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey !== false && $envKey !== '') {
            $this->markTestSkipped('OPENAI_API_KEY environment variable is set');
        }

        $embedder = new OpenAIEmbedder(null);

        $this->assertNull($embedder->embed('test text'));
    }
}
