<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Path resolution with session scope isolation.
 * When EVOLVER_SESSION_SCOPE is set (e.g., to a Discord channel ID or project name),
 * evolution state, memory graph, and assets are isolated to a per-scope subdirectory.
 * This prevents cross-channel/cross-project memory contamination.
 *
 * Ported from evolver/src/gep/paths.js
 */
final class Paths
{
    private static ?string $repoRoot = null;
    private static ?string $workspaceRoot = null;

    /**
     * Get repository root (evolver-php directory).
     */
    public static function getRepoRoot(): string
    {
        if (self::$repoRoot !== null) {
            return self::$repoRoot;
        }
        // src/Paths.php -> repo root
        self::$repoRoot = dirname(__DIR__);
        return self::$repoRoot;
    }

    /**
     * Get workspace root (parent of skills/evolver).
     */
    public static function getWorkspaceRoot(): string
    {
        if (self::$workspaceRoot !== null) {
            return self::$workspaceRoot;
        }
        // skills/evolver -> workspace root
        self::$workspaceRoot = dirname(dirname(self::getRepoRoot()));
        return self::$workspaceRoot;
    }

    /**
     * Get logs directory.
     */
    public static function getLogsDir(): string
    {
        return $_ENV['EVOLVER_LOGS_DIR'] ?? self::getWorkspaceRoot() . '/logs';
    }

    /**
     * Get memory directory.
     */
    public static function getMemoryDir(): string
    {
        return $_ENV['MEMORY_DIR'] ?? self::getWorkspaceRoot() . '/memory';
    }

    /**
     * Get session scope from environment.
     * Delegates to SessionScope class for centralized logic.
     * @deprecated Use SessionScope::get() instead
     */
    public static function getSessionScope(): ?string
    {
        return SessionScope::get();
    }

    /**
     * Get evolution directory with session scope isolation.
     */
    public static function getEvolutionDir(): string
    {
        $baseDir = $_ENV['EVOLUTION_DIR'] ?? self::getMemoryDir() . '/evolution';
        $scope = self::getSessionScope();

        if ($scope !== null) {
            return $baseDir . '/scopes/' . $scope;
        }

        return $baseDir;
    }

    /**
     * Get GEP assets directory with session scope isolation.
     */
    public static function getGepAssetsDir(): string
    {
        $baseDir = $_ENV['GEP_ASSETS_DIR'] ?? self::getRepoRoot() . '/assets/gep';
        $scope = self::getSessionScope();

        if ($scope !== null) {
            return $baseDir . '/scopes/' . $scope;
        }

        return $baseDir;
    }

    /**
     * Get skills directory.
     */
    public static function getSkillsDir(): string
    {
        return $_ENV['SKILLS_DIR'] ?? self::getWorkspaceRoot() . '/skills';
    }

    /**
     * Get GEP genes directory.
     */
    public static function getGenesDir(): string
    {
        return self::getGepAssetsDir() . '/genes';
    }

    /**
     * Get GEP capsules directory.
     */
    public static function getCapsulesDir(): string
    {
        return self::getGepAssetsDir() . '/capsules';
    }

    /**
     * Get GEP events directory.
     */
    public static function getEventsDir(): string
    {
        return self::getGepAssetsDir() . '/events';
    }

    /**
     * Get memory graph path.
     */
    public static function getMemoryGraphPath(): string
    {
        return self::getMemoryDir() . '/memory_graph.jsonl';
    }

    /**
     * Get evolution state path.
     */
    public static function getEvolutionStatePath(): string
    {
        return self::getEvolutionDir() . '/state.json';
    }

    /**
     * Get validation report path.
     */
    public static function getValidationReportPath(): string
    {
        return self::getEvolutionDir() . '/validation_report.json';
    }

    /**
     * Get narrative path.
     */
    public static function getNarrativePath(): string
    {
        return self::getEvolutionDir() . '/evolution_narrative.md';
    }

    /**
     * Get reflection log path.
     */
    public static function getReflectionLogPath(): string
    {
        return self::getEvolutionDir() . '/reflection_log.jsonl';
    }

    /**
     * Ensure a directory exists.
     */
    public static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        try {
            return mkdir($dir, 0755, true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reset cached paths (useful for testing).
     */
    public static function resetCache(): void
    {
        self::$repoRoot = null;
        self::$workspaceRoot = null;
    }
}
