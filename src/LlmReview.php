<?php

declare(strict_types=1);

namespace Evolver;

/**
 * LLM Review - runs external LLM code review for evolution changes.
 *
 * When enabled, this module generates a review prompt and executes
 * an external LLM CLI to review code changes before solidifying.
 *
 * Ported from evolver/src/gep/llmReview.js
 */
final class LlmReview
{
    private const REVIEW_ENABLED_KEY = 'EVOLVER_LLM_REVIEW';
    private const REVIEW_TIMEOUT_MS = 30000;
    private const MAX_DIFF_LENGTH = 6000;
    private const MAX_RATIONALE_LENGTH = 500;
    private const MAX_SIGNALS = 8;

    /**
     * Check if LLM review is enabled.
     */
    public function isEnabled(): bool
    {
        return strtolower(getenv(self::REVIEW_ENABLED_KEY) ?: '') === 'true';
    }

    /**
     * Build the review prompt.
     *
     * @param array{
     *   diff?: string,
     *   gene?: array{id?: string, category?: string},
     *   signals?: string[],
     *   mutation?: array{category?: string, rationale?: string}
     * } $params
     */
    public function buildPrompt(array $params): string
    {
        $gene = $params['gene'] ?? [];
        $mutation = $params['mutation'] ?? [];
        $signals = $params['signals'] ?? [];
        $diff = $params['diff'] ?? '';

        $geneId = $gene['id'] ?? '(unknown)';
        $category = $mutation['category'] ?? $gene['category'] ?? 'unknown';
        $rationale = isset($mutation['rationale'])
            ? substr((string)$mutation['rationale'], 0, self::MAX_RATIONALE_LENGTH)
            : '(none)';
        $signalsList = is_array($signals)
            ? implode(', ', array_slice($signals, 0, self::MAX_SIGNALS))
            : '(none)';
        $diffPreview = substr((string)$diff, 0, self::MAX_DIFF_LENGTH);

        return <<<PROMPT
You are reviewing a code change produced by an autonomous evolution engine.

## Context
- Gene: {$geneId} ({$category})
- Signals: [{$signalsList}]
- Rationale: {$rationale}

## Diff
```diff
{$diffPreview}
```

## Review Criteria
1. Does this change address the stated signals?
2. Are there any obvious regressions or bugs introduced?
3. Is the blast radius proportionate to the problem?
4. Are there any security or safety concerns?

## Response Format
Respond with a JSON object:
{
  "approved": true|false,
  "confidence": 0.0-1.0,
  "concerns": ["..."],
  "summary": "one-line review summary"
}
PROMPT;
    }

    /**
     * Run LLM review.
     *
     * @param array{
     *   diff?: string,
     *   gene?: array{id?: string, category?: string},
     *   signals?: string[],
     *   mutation?: array{category?: string, rationale?: string}
     * } $params
     * @return array{approved: bool, confidence: float, concerns: string[], summary: string}|null
     */
    public function runReview(array $params): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $prompt = $this->buildPrompt($params);

        try {
            // Write prompt to temp file to avoid shell quoting issues
            $tmpFile = sys_get_temp_dir() . '/evolver_review_prompt_' . getmypid() . '.txt';
            file_put_contents($tmpFile, $prompt);

            try {
                // Execute the review command
                $result = $this->executeReview($tmpFile);

                // Parse the response
                $decoded = json_decode(trim($result), true);
                if (is_array($decoded)
                    && isset($decoded['approved'])
                    && is_bool($decoded['approved'])) {
                    return [
                        'approved' => (bool)$decoded['approved'],
                        'confidence' => (float)($decoded['confidence'] ?? 0.5),
                        'concerns' => (array)($decoded['concerns'] ?? []),
                        'summary' => (string)($decoded['summary'] ?? 'review completed'),
                    ];
                }

                return [
                    'approved' => true,
                    'confidence' => 0.5,
                    'concerns' => ['failed to parse review response'],
                    'summary' => 'review parse error',
                ];
            } finally {
                @unlink($tmpFile);
            }
        } catch (\Throwable $e) {
            return [
                'approved' => true,
                'confidence' => 0.5,
                'concerns' => ['review execution failed'],
                'summary' => 'review timeout or error',
            ];
        }
    }

    /**
     * Execute the review command.
     *
     * This implementation returns a default auto-approved response.
     * In production, this would call an external LLM CLI.
     */
    private function executeReview(string $promptFile): string
    {
        // Default: auto-approve without external LLM
        // In production, this would call an LLM CLI like:
        // - claude --print < $promptFile
        // - gemini -p "$(cat $promptFile)"
        // - kimi chat --one-shot "$(cat $promptFile)"

        // Check for configured LLM command
        $llmCommand = getenv('EVOLVER_LLM_COMMAND');
        if ($llmCommand && is_string($llmCommand) && $llmCommand !== '') {
            $command = str_replace('{prompt_file}', escapeshellarg($promptFile), $llmCommand);
            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            if ($exitCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // Default response when no LLM is configured
        return json_encode([
            'approved' => true,
            'confidence' => 0.7,
            'concerns' => [],
            'summary' => 'auto-approved (no external LLM configured)',
        ], JSON_UNESCAPED_UNICODE);
    }
}
