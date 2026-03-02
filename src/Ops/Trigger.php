<?php

declare(strict_types=1);

namespace Evolver\Ops;

use Evolver\Paths;

/**
 * Evolver Wake Trigger - Writes a signal file that the wrapper can poll.
 *
 * Ported from evolver/src/ops/trigger.js
 */
final class Trigger
{
    /**
     * Get the wake signal file path.
     */
    private static function getWakeFile(): string
    {
        return Paths::getMemoryDir() . '/evolver_wake.signal';
    }

    /**
     * Send wake signal.
     */
    public static function send(): bool
    {
        try {
            $wakeFile = self::getWakeFile();
            $dir = dirname($wakeFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($wakeFile, 'WAKE');
            echo "[Trigger] Wake signal sent to {$wakeFile}\n";
            return true;
        } catch (\Throwable $e) {
            echo "[Trigger] Failed: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * Clear wake signal.
     */
    public static function clear(): void
    {
        try {
            $wakeFile = self::getWakeFile();
            if (file_exists($wakeFile)) {
                unlink($wakeFile);
            }
        } catch (\Throwable) {
            // Ignore
        }
    }

    /**
     * Check if wake signal is pending.
     */
    public static function isPending(): bool
    {
        return file_exists(self::getWakeFile());
    }
}
