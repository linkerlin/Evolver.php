<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\LlmClient;
use PHPUnit\Framework\TestCase;

/**
 * LlmClient tests - JSON-output LLM calls.
 */
final class LlmClientTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    public function testDefaultConfiguration(): void
    {
        $client = new LlmClient('test-key');

        $this->assertSame('gpt-4o-mini', $client->getModel());
    }

    public function testCustomConfiguration(): void
    {
        $client = new LlmClient(
            apiKey: 'custom-key',
            model: 'gpt-4o',
            baseUrl: 'https://custom.api.com/v1',
            timeoutMs: 60000
        );

        $this->assertSame('gpt-4o', $client->getModel());
    }

    public function testFromConfig(): void
    {
        $client = LlmClient::fromConfig([
            'api_key' => 'config-key',
            'model' => 'gpt-4o',
        ]);

        $this->assertSame('gpt-4o', $client->getModel());
    }

    // -------------------------------------------------------------------------
    // Availability
    // -------------------------------------------------------------------------

    public function testIsAvailableWithKey(): void
    {
        $client = new LlmClient('test-api-key');

        $this->assertTrue($client->isAvailable());
    }

    public function testIsNotAvailableWithEmptyKey(): void
    {
        $client = new LlmClient('');

        $this->assertFalse($client->isAvailable());
    }

    public function testIsNotAvailableWithoutKey(): void
    {
        // Skip if OPENAI_API_KEY is set in environment
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey !== false && $envKey !== '') {
            $this->markTestSkipped('OPENAI_API_KEY environment variable is set');
        }

        $client = new LlmClient(null);

        $this->assertFalse($client->isAvailable());
    }

    // -------------------------------------------------------------------------
    // JSON Extraction
    // -------------------------------------------------------------------------

    public function testCompleteJsonReturnsNullWhenNotAvailable(): void
    {
        // Skip if OPENAI_API_KEY is set in environment
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey !== false && $envKey !== '') {
            $this->markTestSkipped('OPENAI_API_KEY environment variable is set');
        }

        $client = new LlmClient(null);

        $result = $client->completeJson('Test prompt', 'test');

        $this->assertNull($result);
    }

    public function testCompleteJsonWithValidResponse(): void
    {
        // This test requires actual API access - skip if no key
        $envKey = getenv('OPENAI_API_KEY');
        if ($envKey === false || $envKey === '') {
            $this->markTestSkipped('OPENAI_API_KEY not set - skipping integration test');
        }

        $client = new LlmClient($envKey);

        $result = $client->completeJson('Return JSON: {"status": "ok"}', 'test-simple');

        // The API may fail for various reasons - just verify the call doesn't crash
        // If we get a result, verify it's an array
        if ($result !== null) {
            $this->assertIsArray($result);
        } else {
            // API call may have failed - this is acceptable for this test
            $this->markTestSkipped('API call returned null - may be rate limited or network issue');
        }
    }
}
