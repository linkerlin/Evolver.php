<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Centralized configuration management for Evolver.
 * 
 * Provides a single source of truth for:
 * - Schema versions
 * - Security limits and thresholds
 * - Detection thresholds (repair loop, stagnation, etc.)
 * - Cache settings
 * - Feature flags
 * 
 * Configuration priority (highest to lowest):
 * 1. Environment variables
 * 2. .evolver.json config file
 * 3. Default values
 */
final class EvolverConfig
{
    /** Schema version for GEP protocol compliance */
    public const SCHEMA_VERSION = '1.6.0';
    
    /** Server version */
    public const SERVER_VERSION = '1.1.0';
    
    /** MCP protocol version */
    public const MCP_VERSION = '2024-11-05';

    // -------------------------------------------------------------------------
    // Security Limits
    // -------------------------------------------------------------------------
    
    /** Maximum files per evolution (hard limit) */
    public const MAX_FILES_HARD_LIMIT = 60;
    
    /** Maximum lines per evolution (hard limit) */
    public const MAX_LINES_HARD_LIMIT = 20000;
    
    /** Default max files per gene constraint */
    public const DEFAULT_MAX_FILES = 25;
    
    /** Blast radius warning threshold (80% of limit) */
    public const BLAST_WARN_RATIO = 0.8;
    
    /** Blast radius critical threshold (200% of limit) */
    public const BLAST_CRITICAL_RATIO = 2.0;
    
    /** Validation command whitelist */
    public const ALLOWED_COMMAND_PREFIXES = ['php', 'composer', 'phpunit', 'phpcs', 'phpstan'];
    
    /** Forbidden shell operators */
    public const FORBIDDEN_SHELL_OPERATORS = [';', '&&', '||', '|', '>', '<', '`', '$('];

    // -------------------------------------------------------------------------
    // Detection Thresholds (SignalExtractor)
    // -------------------------------------------------------------------------
    
    /** Repair loop detection threshold */
    public const REPAIR_LOOP_THRESHOLD = 3;
    
    /** Force innovation after this many repair cycles */
    public const FORCE_INNOVATION_THRESHOLD = 5;
    
    /** Stagnation detection threshold (empty cycles) */
    public const STAGNATION_THRESHOLD = 3;
    
    /** High failure ratio threshold (60%) */
    public const FAILURE_RATIO_THRESHOLD = 0.6;
    
    /** Signal oscillation threshold */
    public const OSCILLATION_THRESHOLD = 2;
    
    /** Signal suppression threshold (appeared N times in last 8 events) */
    public const SIGNAL_SUPPRESSION_THRESHOLD = 3;
    
    /** Consecutive failures before triggering failure loop detection */
    public const CONSECUTIVE_FAILURE_THRESHOLD = 3;
    
    /** High failure ratio threshold (75%) for forcing innovation */
    public const HIGH_FAILURE_RATIO_THRESHOLD = 0.75;
    
    /** Tool usage threshold for high usage warning */
    public const HIGH_TOOL_USAGE_THRESHOLD = 10;
    
    /** Repeated exec tool usage threshold */
    public const REPEATED_EXEC_THRESHOLD = 5;

    // -------------------------------------------------------------------------
    // Validation Settings
    // -------------------------------------------------------------------------
    
    /** Max validation timeout in seconds */
    public const VALIDATION_TIMEOUT = 60;
    
    /** Max validation command output length */
    public const MAX_VALIDATION_OUTPUT = 10000;

    // -------------------------------------------------------------------------
    // Prompt Settings
    // -------------------------------------------------------------------------
    
    /** Maximum context length before truncation */
    public const MAX_CONTEXT_LENGTH = 20000;
    
    /** Maximum signal string length */
    public const MAX_SIGNAL_LENGTH = 200;
    
    /** Maximum signals to display in prompt */
    public const MAX_SIGNALS_DISPLAY = 50;
    
    /** Maximum genes to preview in prompt */
    public const MAX_GENES_PREVIEW = 5;
    
    /** Maximum capsules to preview in prompt */
    public const MAX_CAPSULES_PREVIEW = 3;

    // -------------------------------------------------------------------------
    // Cache Settings
    // -------------------------------------------------------------------------
    
    /** Default cache TTL in seconds */
    public const DEFAULT_CACHE_TTL = 300;
    
    /** Maximum cache entries */
    public const MAX_CACHE_ENTRIES = 1000;

    // -------------------------------------------------------------------------
    // Storage Limits
    // -------------------------------------------------------------------------
    
    /** Maximum capsule content length */
    public const MAX_CAPSULE_CONTENT_LENGTH = 8000;
    
    /** Maximum summary length */
    public const MAX_SUMMARY_LENGTH = 500;
    
    /** Default limit for list queries */
    public const DEFAULT_LIST_LIMIT = 20;
    
    /** Maximum limit for list queries */
    public const MAX_LIST_LIMIT = 100;

    // -------------------------------------------------------------------------
    // Epigenetic Settings
    // -------------------------------------------------------------------------
    
    /** Epigenetic mark boost increment on success */
    public const EPIGENETIC_SUCCESS_BOOST = 0.05;
    
    /** Epigenetic mark boost decrement on failure */
    public const EPIGENETIC_FAILURE_BOOST = 0.1;
    
    /** Maximum epigenetic boost */
    public const MAX_EPIGENETIC_BOOST = 0.5;
    
    /** Minimum epigenetic boost */
    public const MIN_EPIGENETIC_BOOST = -0.5;
    
    /** Epigenetic mark TTL in days */
    public const EPIGENETIC_MARK_TTL_DAYS = 90;
    
    /** Maximum epigenetic marks per gene */
    public const MAX_EPIGENETIC_MARKS = 10;

    // -------------------------------------------------------------------------
    // Sync Settings
    // -------------------------------------------------------------------------
    
    /** Default batch size for hub sync */
    public const SYNC_BATCH_SIZE = 10;
    
    /** Sync retry delay in seconds */
    public const SYNC_RETRY_DELAY = 60;

    // -------------------------------------------------------------------------
    // Feature Flags
    // -------------------------------------------------------------------------
    
    /**
     * Check if a feature is enabled.
     */
    public static function isFeatureEnabled(string $feature): bool
    {
        return match ($feature) {
            'cache' => self::getEnvBool('EVOLVER_ENABLE_CACHE', true),
            'sync_to_hub' => self::getEnvBool('EVOLVER_ENABLE_SYNC', true),
            'epigenetic_marks' => self::getEnvBool('EVOLVER_ENABLE_EPIGENETIC', true),
            'signal_deduplication' => self::getEnvBool('EVOLVER_ENABLE_DEDUP', true),
            default => false,
        };
    }

    // -------------------------------------------------------------------------
    // Environment Variable Helpers
    // -------------------------------------------------------------------------
    
    /**
     * Get string value from environment variable.
     */
    public static function getEnvString(string $name, string $default): string
    {
        $value = getenv($name);
        return $value !== false && $value !== '' ? $value : $default;
    }

    /**
     * Get integer value from environment variable.
     */
    public static function getEnvInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    /**
     * Get boolean value from environment variable.
     */
    public static function getEnvBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $lower = strtolower(trim($value));
        return in_array($lower, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Get float value from environment variable.
     */
    public static function getEnvFloat(string $name, float $default): float
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return (float)$value;
    }

    // -------------------------------------------------------------------------
    // Path Helpers
    // -------------------------------------------------------------------------
    
    /**
     * Get default database path.
     */
    public static function getDefaultDatabasePath(): string
    {
        $envPath = getenv('EVOLVER_DB_PATH');
        if ($envPath !== false && $envPath !== '') {
            return $envPath;
        }
        
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home !== false && $home !== '') {
            return $home . '/.evolver/evolver.db';
        }
        
        return __DIR__ . '/../data/evolver.db';
    }

    /**
     * Get safety mode from environment.
     */
    public static function getSafetyMode(): string
    {
        $mode = strtolower(getenv('EVOLVE_ALLOW_SELF_MODIFY') ?: 'always');
        return in_array($mode, ['never', 'review', 'always'], true) ? $mode : 'always';
    }

    // -------------------------------------------------------------------------
    // Config File Support
    // -------------------------------------------------------------------------
    
    /** Cached config file contents */
    private static ?array $configFileCache = null;

    /**
     * Load configuration from .evolver.json file.
     */
    public static function loadConfigFile(?string $path = null): array
    {
        if (self::$configFileCache !== null) {
            return self::$configFileCache;
        }
        
        $configPath = $path ?? self::findConfigFile();
        if ($configPath === null || !file_exists($configPath)) {
            return [];
        }
        
        $content = @file_get_contents($configPath);
        if ($content === false) {
            return [];
        }
        
        $config = json_decode($content, true);
        if (!is_array($config)) {
            return [];
        }
        
        self::$configFileCache = $config;
        return $config;
    }

    /**
     * Find config file in standard locations.
     */
    private static function findConfigFile(): ?string
    {
        $locations = [
            getcwd() . '/.evolver.json',
            getenv('HOME') . '/.evolver/config.json',
            __DIR__ . '/../.evolver.json',
        ];
        
        foreach ($locations as $location) {
            if (file_exists($location)) {
                return $location;
            }
        }
        
        return null;
    }

    /**
     * Get a config value (from env, file, or default).
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Priority 1: Environment variable
        $envValue = getenv('EVOLVER_' . strtoupper($key));
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }
        
        // Priority 2: Config file
        $config = self::loadConfigFile();
        if (isset($config[$key])) {
            return $config[$key];
        }
        
        // Priority 3: Default
        return $default;
    }

    /**
     * Clear config file cache (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$configFileCache = null;
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------
    
    /**
     * Get all configuration as array (for debugging).
     */
    public static function getAllConfig(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'server_version' => self::SERVER_VERSION,
            'mcp_version' => self::MCP_VERSION,
            'safety' => [
                'max_files_hard_limit' => self::MAX_FILES_HARD_LIMIT,
                'max_lines_hard_limit' => self::MAX_LINES_HARD_LIMIT,
                'default_max_files' => self::DEFAULT_MAX_FILES,
                'blast_warn_ratio' => self::BLAST_WARN_RATIO,
                'blast_critical_ratio' => self::BLAST_CRITICAL_RATIO,
                'allowed_commands' => self::ALLOWED_COMMAND_PREFIXES,
                'forbidden_operators' => self::FORBIDDEN_SHELL_OPERATORS,
            ],
            'detection_thresholds' => [
                'repair_loop' => self::REPAIR_LOOP_THRESHOLD,
                'force_innovation' => self::FORCE_INNOVATION_THRESHOLD,
                'stagnation' => self::STAGNATION_THRESHOLD,
                'failure_ratio' => self::FAILURE_RATIO_THRESHOLD,
                'oscillation' => self::OSCILLATION_THRESHOLD,
            ],
            'features' => [
                'cache' => self::isFeatureEnabled('cache'),
                'sync_to_hub' => self::isFeatureEnabled('sync_to_hub'),
                'epigenetic_marks' => self::isFeatureEnabled('epigenetic_marks'),
                'signal_deduplication' => self::isFeatureEnabled('signal_deduplication'),
            ],
            'environment' => [
                'safety_mode' => self::getSafetyMode(),
                'database_path' => self::getDefaultDatabasePath(),
            ],
        ];
    }
}
