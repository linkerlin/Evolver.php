<?php

declare(strict_types=1);

namespace Evolver;

/**
 * LLM Client for memory extraction and dedup decisions.
 * Uses OpenAI-compatible API.
 */
final class LlmClient
{
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_TIMEOUT = 30000;

    public function __construct(
        private ?string $apiKey = null,
        private string $model = self::DEFAULT_MODEL,
        private string $baseUrl = self::DEFAULT_BASE_URL,
        private int $timeoutMs = self::DEFAULT_TIMEOUT,
        private ?\Closure $log = null,
    ) {
        // Try to get API key from environment if not provided
        if ($this->apiKey === null) {
            $this->apiKey = getenv('OPENAI_API_KEY') ?: null;
        }
    }

    /**
     * Check if the client is available (has API key).
     */
    public function isAvailable(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Send a prompt and parse the JSON response.
     *
     * @template T
     * @param string $prompt The prompt to send
     * @param string $label Label for logging
     * @return T|null Parsed JSON response or null on failure
     */
    public function completeJson(string $prompt, string $label = 'generic'): ?array
    {
        if (!$this->isAvailable()) {
            $this->log("[{$label}] LLM client not available (no API key)");
            return null;
        }

        $ch = curl_init("{$this->baseUrl}/chat/completions");
        if ($ch === false) {
            $this->log("[{$label}] Failed to initialize cURL");
            return null;
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a memory extraction assistant. Always respond with valid JSON only.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->apiKey}",
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // Note: curl_close() is deprecated in PHP 8.5, handles are auto-closed

        if ($error) {
            $this->log("[{$label}] cURL error: {$error}");
            return null;
        }

        if ($httpCode !== 200 || !is_string($response)) {
            $this->log("[{$label}] HTTP error: {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("[{$label}] Failed to parse API response");
            return null;
        }

        $raw = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($raw) || $raw === '') {
            $this->log("[{$label}] Empty response content from model {$this->model}");
            return null;
        }

        $jsonStr = $this->extractJsonFromResponse($raw);
        if ($jsonStr === null) {
            $preview = $this->previewText($raw);
            $this->log("[{$label}] No JSON object found (chars=" . strlen($raw) . ", preview={$preview})");
            return null;
        }

        $result = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $preview = $this->previewText($jsonStr);
            $this->log("[{$label}] JSON.parse failed: " . json_last_error_msg() . " (jsonPreview={$preview})");
            return null;
        }

        return $result;
    }

    /**
     * Create from configuration array.
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            apiKey: $config['api_key'] ?? null,
            model: $config['model'] ?? self::DEFAULT_MODEL,
            baseUrl: $config['base_url'] ?? self::DEFAULT_BASE_URL,
            timeoutMs: $config['timeout_ms'] ?? self::DEFAULT_TIMEOUT,
            log: $config['log'] ?? null,
        );
    }

    /**
     * Get the current model name.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Extract JSON from an LLM response that may be wrapped in markdown fences.
     */
    private function extractJsonFromResponse(string $text): ?string
    {
        // Try markdown code fence first (```json ... ``` or ``` ... ```)
        if (preg_match('/```(?:json)?\s*\n?([\s\S]*?)```/', $text, $matches)) {
            return trim($matches[1]);
        }

        // Try balanced brace extraction
        $firstBrace = strpos($text, '{');
        if ($firstBrace === false) {
            return null;
        }

        $depth = 0;
        $lastBrace = -1;
        $len = strlen($text);

        for ($i = $firstBrace; $i < $len; $i++) {
            if ($text[$i] === '{') {
                $depth++;
            } elseif ($text[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $lastBrace = $i;
                    break;
                }
            }
        }

        if ($lastBrace === -1) {
            return null;
        }

        return substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
    }

    /**
     * Preview text for logging.
     */
    private function previewText(string $value, int $maxLen = 200): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($value));
        if ($normalized === null) {
            return '';
        }
        if (strlen($normalized) <= $maxLen) {
            return $normalized;
        }
        return substr($normalized, 0, $maxLen - 3) . '...';
    }

    /**
     * Log a message.
     */
    private function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)("memory-pro: llm-client {$message}");
        }
    }
}
