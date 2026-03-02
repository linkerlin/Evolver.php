<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Bridge - Prompt artifact writing and sessions_spawn rendering.
 *
 * Ported from evolver/src/gep/bridge.js
 */
final class Bridge
{
    /**
     * Ensure a directory exists.
     */
    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Clip text to max chars with truncation marker.
     */
    public static function clip(string $text, int $maxChars): string
    {
        if ($maxChars <= 0 || strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, max(0, $maxChars - 40)) . "\n...[TRUNCATED]...\n";
    }

    /**
     * Write prompt artifact files.
     */
    public static function writePromptArtifact(array $params): array
    {
        $memoryDir = trim($params['memoryDir'] ?? '');
        if (empty($memoryDir)) {
            throw new \InvalidArgumentException('bridge: missing memoryDir');
        }

        self::ensureDir($memoryDir);

        $cycleId = self::sanitize($params['cycleId'] ?? 'cycle', 'cycle');
        $runId = self::sanitize($params['runId'] ?? (string) time(), 'run');
        $base = "gep_prompt_{$cycleId}_{$runId}";

        $promptPath = $memoryDir . '/' . $base . '.txt';
        $metaPath = $memoryDir . '/' . $base . '.json';

        file_put_contents($promptPath, $params['prompt'] ?? '');

        $meta = [
            'type' => 'GepPromptArtifact',
            'at' => date('c'),
            'cycle_id' => $params['cycleId'] ?? null,
            'run_id' => $params['runId'] ?? null,
            'prompt_path' => $promptPath,
            'meta' => $params['meta'] ?? null,
        ];

        file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        return ['promptPath' => $promptPath, 'metaPath' => $metaPath];
    }

    /**
     * Render sessions_spawn call for wrapper.
     */
    public static function renderSessionsSpawnCall(array $params): string
    {
        $task = trim($params['task'] ?? '');
        if (empty($task)) {
            throw new \InvalidArgumentException('bridge: missing task');
        }

        $agentId = $params['agentId'] ?? 'main';
        $label = $params['label'] ?? 'gep_bridge';
        $cleanup = $params['cleanup'] ?? 'delete';

        // Output valid JSON so wrappers can parse with JSON.parse
        $payload = json_encode([
            'task' => $task,
            'agentId' => $agentId,
            'cleanup' => $cleanup,
            'label' => $label,
        ], JSON_UNESCAPED_UNICODE);

        return "sessions_spawn({$payload})";
    }

    /**
     * Sanitize string for use in filename.
     */
    private static function sanitize(string $input, string $fallback): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-#]/', '_', $input);
        return $safe ?? $fallback;
    }
}
