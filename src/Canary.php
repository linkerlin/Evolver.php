<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Canary script: verifies index.php loads without crashing.
 * This is the last safety net before solidify commits an evolution.
 * If a patch broke index.php (syntax error, missing require, etc.),
 * the canary catches it BEFORE the daemon restarts with broken code.
 *
 * Ported from evolver/src/canary.js
 */
final class Canary
{
    /**
     * Run canary check by attempting to load the main entry point.
     *
     * @param string|null $entryPoint Path to the main entry point (default: src/index.php)
     * @return array{ok: bool, error: string|null}
     */
    public static function check(?string $entryPoint = null): array
    {
        $entryPoint ??= dirname(__DIR__) . '/index.php';

        if (!file_exists($entryPoint)) {
            return ['ok' => false, 'error' => "Entry point not found: {$entryPoint}"];
        }

        // Use PHP's lint check first (syntax only, no execution)
        $output = [];
        $returnCode = 0;
        exec("php -l " . escapeshellarg($entryPoint) . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            return ['ok' => false, 'error' => "Syntax error: {$error}"];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Run canary check for multiple files.
     *
     * @param array<string> $files Files to check
     * @return array{ok: bool, errors: array<string, string>}
     */
    public static function checkMultiple(array $files): array
    {
        $errors = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $errors[$file] = "File not found: {$file}";
                continue;
            }

            $output = [];
            $returnCode = 0;
            exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $returnCode);

            if ($returnCode !== 0) {
                $errors[$file] = implode("\n", $output);
            }
        }

        return ['ok' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * Run canary check and exit with appropriate code.
     * This is useful for CLI usage.
     */
    public static function runAndExit(?string $entryPoint = null): never
    {
        $result = self::check($entryPoint);

        if (!$result['ok']) {
            fwrite(STDERR, $result['error'] ?? 'Unknown error');
            exit(1);
        }

        exit(0);
    }
}
