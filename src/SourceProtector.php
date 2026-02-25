<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Source protection mechanism to prevent self-destruction.
 * Core evolution engine files are marked as immutable to prevent
 * the system from accidentally modifying itself into an unrecoverable state.
 * 
 * PHP port inspired by EvoMap/evolver safety model.
 */
final class SourceProtector
{
    /** Default protected paths (relative to project root) */
    private const DEFAULT_PROTECTED_PATHS = [
        'src/McpServer.php',
        'src/Database.php',
        'src/GepAssetStore.php',
        'src/ContentHash.php',
        'src/SourceProtector.php',
        'src/EnvFingerprint.php',
        'evolver.php',
    ];

    /** Protected path patterns (wildcards supported) */
    private const PROTECTED_PATTERNS = [
        'src/Ops/*.php',  // Ops modules are critical infrastructure
        'vendor/*',       // Never modify dependencies
    ];

    private string $projectRoot;
    private array $protectedPaths;
    private array $protectedPatterns;

    public function __construct(?string $projectRoot = null, array $additionalProtectedPaths = [])
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__);
        $this->protectedPaths = array_merge(
            self::DEFAULT_PROTECTED_PATHS,
            $additionalProtectedPaths
        );
        $this->protectedPatterns = self::PROTECTED_PATTERNS;
    }

    /**
     * Check if a path is protected.
     * 
     * @param string $path Path to check (can be absolute or relative)
     * @return bool
     */
    public function isProtected(string $path): bool
    {
        // Normalize path
        $normalizedPath = $this->normalizePath($path);
        $relativePath = $this->getRelativePath($normalizedPath);

        // Check exact matches
        foreach ($this->protectedPaths as $protected) {
            if ($relativePath === $protected) {
                return true;
            }
            // Also check normalized version
            if ($normalizedPath === $this->normalizePath($this->projectRoot . '/' . $protected)) {
                return true;
            }
        }

        // Check pattern matches
        foreach ($this->protectedPatterns as $pattern) {
            if ($this->matchPattern($relativePath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate that a list of files doesn't contain any protected paths.
     * 
     * @param array<string> $files List of file paths to validate
     * @return array{ok: bool, violations: array<string>}
     */
    public function validateFiles(array $files): array
    {
        $violations = [];

        foreach ($files as $file) {
            if ($this->isProtected($file)) {
                $violations[] = $file;
            }
        }

        return [
            'ok' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Assert that files are not protected (throws exception if violated).
     * 
     * @param array<string> $files
     * @throws \RuntimeException
     */
    public function assertSafe(array $files): void
    {
        $result = $this->validateFiles($files);
        if (!$result['ok']) {
            throw new \RuntimeException(
                'Source protection violation: attempted to modify protected files: ' 
                . implode(', ', $result['violations'])
            );
        }
    }

    /**
     * Add additional protected paths at runtime.
     * 
     * @param array<string> $paths
     */
    public function addProtectedPaths(array $paths): void
    {
        foreach ($paths as $path) {
            $normalized = $this->normalizePath($path);
            if (!in_array($normalized, $this->protectedPaths, true)) {
                $this->protectedPaths[] = $normalized;
            }
        }
    }

    /**
     * Get list of all protected paths.
     * 
     * @return array<string>
     */
    public function getProtectedPaths(): array
    {
        return $this->protectedPaths;
    }

    /**
     * Check if source protection can be bypassed (emergency mode).
     * This should only be used in testing or recovery scenarios.
     * 
     * @return bool
     */
    public static function canBypass(): bool
    {
        $env = getenv('EVOLVER_BYPASS_PROTECTION');
        return $env === '1' || $env === 'true';
    }

    /**
     * Normalize a file path for comparison.
     */
    private function normalizePath(string $path): string
    {
        // Convert to absolute path if relative
        if (!$this->isAbsolutePath($path)) {
            $path = $this->projectRoot . '/' . $path;
        }

        // Normalize directory separators
        $path = str_replace('\\', '/', $path);

        // Remove . and .. segments
        $parts = explode('/', $path);
        $result = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.' && $part !== '') {
                $result[] = $part;
            }
        }

        return '/' . implode('/', $result);
    }

    /**
     * Check if a path is absolute.
     */
    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') 
            || (strlen($path) > 1 && $path[1] === ':'); // Windows drive letter
    }

    /**
     * Get relative path from project root.
     */
    private function getRelativePath(string $absolutePath): string
    {
        $root = rtrim($this->normalizePath($this->projectRoot), '/');
        if (str_starts_with($absolutePath, $root . '/')) {
            return substr($absolutePath, strlen($root) + 1);
        }
        return $absolutePath;
    }

    /**
     * Match a path against a wildcard pattern.
     * Supports * (any characters) and ? (single character).
     */
    private function matchPattern(string $path, string $pattern): bool
    {
        // Convert glob pattern to regex
        // Use # as delimiter to avoid issues with / in paths
        $quoted = preg_quote($pattern, '#');
        // Replace escaped wildcards with regex equivalents
        $regexPattern = str_replace(['\\*', '\\?'], ['.*', '.'], $quoted);
        // Replace directory separators (both forward and backslash)
        $regexPattern = str_replace(['/', '\\\\'], '[/\\\\]', $regexPattern);
        
        $regex = '#^' . $regexPattern . '$#';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Get the project root directory.
     */
    public function getProjectRoot(): string
    {
        return $this->projectRoot;
    }

    /**
     * Create a protection report for debugging.
     */
    public function getProtectionReport(): array
    {
        return [
            'project_root' => $this->projectRoot,
            'protected_paths' => $this->protectedPaths,
            'protected_patterns' => $this->protectedPatterns,
            'bypass_available' => self::canBypass(),
        ];
    }
}
