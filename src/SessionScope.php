<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Session Scope Isolation.
 *
 * When EVOLVER_SESSION_SCOPE is set (e.g., to a Discord channel ID or project name),
 * evolution state, memory graph, and assets are isolated to a per-scope subdirectory.
 * This prevents cross-channel/cross-project memory contamination.
 *
 * When NOT set, everything works as before (global scope, backward compatible).
 */
class SessionScope
{
    private const ENV_VAR = 'EVOLVER_SESSION_SCOPE';
    private const MAX_LENGTH = 128;

    /**
     * Cached scope value.
     */
    private static ?string $cachedScope = null;
    private static bool $initialized = false;

    /**
     * Get the current session scope from environment.
     *
     * @return string|null Sanitized scope or null if not set
     */
    public static function get(): ?string
    {
        if (self::$initialized) {
            return self::$cachedScope;
        }

        self::$initialized = true;
        self::$cachedScope = self::sanitize(self::readEnv());

        return self::$cachedScope;
    }

    /**
     * Read the raw scope value from environment.
     *
     * Checks both $_ENV and getenv() for compatibility.
     *
     * @return string Raw scope value or empty string
     */
    private static function readEnv(): string
    {
        // Try $_ENV first, then getenv()
        $raw = $_ENV[self::ENV_VAR] ?? '';
        if ($raw === '') {
            $envValue = getenv(self::ENV_VAR);
            $raw = $envValue !== false ? $envValue : '';
        }

        return trim($raw);
    }

    /**
     * Sanitize scope value for safe use in file paths.
     *
     * @param string $raw Raw input value
     * @return string|null Sanitized value or null if invalid
     */
    public static function sanitize(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        // Sanitize: only allow alphanumeric, dash, underscore, dot
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $raw);
        $safe = substr($safe, 0, self::MAX_LENGTH);

        // Prevent path traversal
        if (empty($safe) || preg_match('/^\.{1,2}$/', $safe) || str_contains($safe, '..')) {
            return null;
        }

        return $safe;
    }

    /**
     * Check if a scope is currently active.
     *
     * @return bool True if scope is set and valid
     */
    public static function isActive(): bool
    {
        return self::get() !== null;
    }

    /**
     * Apply scope to a base directory path.
     *
     * If scope is active, returns {baseDir}/scopes/{scope}.
     * If scope is not active, returns baseDir unchanged.
     *
     * @param string $baseDir Base directory path
     * @return string Scoped path or base directory
     */
    public static function applyToPath(string $baseDir): string
    {
        $scope = self::get();
        if ($scope === null) {
            return $baseDir;
        }

        return $baseDir . DIRECTORY_SEPARATOR . 'scopes' . DIRECTORY_SEPARATOR . $scope;
    }

    /**
     * Get the scopes directory for a base path.
     *
     * @param string $baseDir Base directory path
     * @return string Path to scopes directory
     */
    public static function getScopesDir(string $baseDir): string
    {
        return $baseDir . DIRECTORY_SEPARATOR . 'scopes';
    }

    /**
     * Clear the cached scope value (useful for testing).
     */
    public static function reset(): void
    {
        self::$cachedScope = null;
        self::$initialized = false;
    }

    /**
     * Set a scope value directly (useful for testing).
     *
     * @param string|null $scope Scope value to set
     */
    public static function setForTest(?string $scope): void
    {
        self::$cachedScope = $scope;
        self::$initialized = true;
    }
}
