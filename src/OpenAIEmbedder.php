<?php

declare(strict_types=1);

namespace Evolver;

/**
 * OpenAI embedding provider.
 *
 * Supports text-embedding-3-small and text-embedding-3-large models.
 */
final class OpenAIEmbedder implements EmbedderInterface
{
    private const DEFAULT_MODEL = 'text-embedding-3-small';
    private const DEFAULT_DIMENSIONS = 1536;
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private ?string $apiKey = null,
        public readonly string $model = self::DEFAULT_MODEL,
        private int $dimensions = self::DEFAULT_DIMENSIONS,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeout = 30,
    ) {
        // Try to get API key from environment if not provided
        if ($this->apiKey === null) {
            $this->apiKey = getenv('OPENAI_API_KEY') ?: null;
        }
    }

    public function embed(string $text): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $ch = curl_init("{$this->baseUrl}/embeddings");
        if ($ch === false) {
            return null;
        }

        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        // Only include dimensions for models that support it
        if (str_starts_with($this->model, 'text-embedding-3')) {
            $payload['dimensions'] = $this->dimensions;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->apiKey}",
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200 || !is_string($response)) {
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data['data'][0]['embedding'] ?? null;
    }

    public function getDimension(): int
    {
        return $this->dimensions;
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Get the model name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Create from configuration array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            apiKey: $config['api_key'] ?? null,
            model: $config['model'] ?? self::DEFAULT_MODEL,
            dimensions: $config['dimensions'] ?? self::DEFAULT_DIMENSIONS,
            baseUrl: $config['base_url'] ?? self::DEFAULT_BASE_URL,
            timeout: $config['timeout'] ?? 30,
        );
    }
}
