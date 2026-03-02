<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Strategy Configuration - fine-grained strategy parameter tuning.
 *
 * Supports:
 * - Mutation weight coefficients
 * - Quality gate thresholds
 * - Cool-down periods
 * - Blast radius limits
 */
final class StrategyConfig
{
    private array $config = [];
    private string $configFile;

    public const DEFAULT_STRATEGY = 'balanced';

    private const DEFAULT_CONFIG = [
        'strategy' => 'balanced',
        'mutation_weights' => [
            'refactor' => 0.3,
            'optimize' => 0.25,
            'innovate' => 0.2,
            'repair' => 0.25,
        ],
        'quality_gates' => [
            'min_confidence' => 0.6,
            'min_gdi' => 0.5,
            'min_success_rate' => 0.7,
        ],
        'cooldown' => [
            'min_interval_seconds' => 60,
            'repair_only_after_failures' => 3,
        ],
        'blast_radius' => [
            'max_files' => 60,
            'max_lines' => 20000,
            'warn_files' => 20,
            'warn_lines' => 5000,
        ],
        'gene_selection' => [
            'enable_drift' => true,
            'drift_threshold' => 0.15,
            'prefer_high_gdi' => true,
            'min_gdi_for_selection' => 0.3,
        ],
        'safety' => [
            'mode' => 'always',
            'require_approval_for_high_risk' => true,
            'high_risk_threshold' => 0.8,
        ],
    ];

    private const STRATEGY_PRESETS = [
        'balanced' => [
            'mutation_weights' => [
                'refactor' => 0.3,
                'optimize' => 0.25,
                'innovate' => 0.2,
                'repair' => 0.25,
            ],
            'quality_gates' => [
                'min_confidence' => 0.6,
                'min_gdi' => 0.5,
            ],
        ],
        'innovate' => [
            'mutation_weights' => [
                'refactor' => 0.15,
                'optimize' => 0.15,
                'innovate' => 0.5,
                'repair' => 0.2,
            ],
            'quality_gates' => [
                'min_confidence' => 0.5,
                'min_gdi' => 0.4,
            ],
        ],
        'harden' => [
            'mutation_weights' => [
                'refactor' => 0.35,
                'optimize' => 0.25,
                'innovate' => 0.1,
                'repair' => 0.3,
            ],
            'quality_gates' => [
                'min_confidence' => 0.8,
                'min_gdi' => 0.7,
            ],
        ],
        'repair-only' => [
            'mutation_weights' => [
                'refactor' => 0.1,
                'optimize' => 0.1,
                'innovate' => 0.0,
                'repair' => 0.8,
            ],
            'quality_gates' => [
                'min_confidence' => 0.5,
                'min_gdi' => 0.3,
            ],
        ],
    ];

    public function __construct(?string $configFile = null)
    {
        $this->configFile = $configFile ?? $this->getDefaultConfigPath();
        $this->load();
    }

    /**
     * Get default config file path.
     */
    private function getDefaultConfigPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home !== false && $home !== '') {
            return $home . '/.evolver.json';
        }
        return dirname(__DIR__, 2) . '/.evolver.json';
    }

    /**
     * Load configuration from file.
     */
    public function load(): void
    {
        $this->config = self::DEFAULT_CONFIG;

        if (file_exists($this->configFile)) {
            $loaded = json_decode(file_get_contents($this->configFile), true);
            if (is_array($loaded)) {
                $this->config = array_replace_recursive($this->config, $loaded);
            }
        }

        $strategy = $this->config['strategy'] ?? self::DEFAULT_STRATEGY;
        if (isset(self::STRATEGY_PRESETS[$strategy])) {
            $this->config = array_replace_recursive($this->config, self::STRATEGY_PRESETS[$strategy]);
        }
    }

    /**
     * Save configuration to file.
     */
    public function save(): bool
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents(
            $this->configFile,
            json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        ) !== false;
    }

    /**
     * Get current strategy name.
     */
    public function getStrategy(): string
    {
        return $this->config['strategy'] ?? self::DEFAULT_STRATEGY;
    }

    /**
     * Set strategy by name.
     */
    public function setStrategy(string $strategy): void
    {
        if (!isset(self::STRATEGY_PRESETS[$strategy])) {
            throw new \InvalidArgumentException("Unknown strategy: {$strategy}");
        }

        $this->config['strategy'] = $strategy;
        $this->config = array_replace_recursive($this->config, self::STRATEGY_PRESETS[$strategy]);
    }

    /**
     * Get mutation weight.
     */
    public function getMutationWeight(string $type): float
    {
        return $this->config['mutation_weights'][$type] ?? 0.25;
    }

    /**
     * Get all mutation weights.
     */
    public function getMutationWeights(): array
    {
        return $this->config['mutation_weights'] ?? [];
    }

    /**
     * Set mutation weight.
     */
    public function setMutationWeight(string $type, float $weight): void
    {
        if ($weight < 0 || $weight > 1) {
            throw new \InvalidArgumentException('Weight must be between 0 and 1');
        }

        $this->config['mutation_weights'][$type] = $weight;
    }

    /**
     * Get quality gate threshold.
     */
    public function getQualityGate(string $gate): float
    {
        return $this->config['quality_gates'][$gate] ?? 0.5;
    }

    /**
     * Get all quality gates.
     */
    public function getQualityGates(): array
    {
        return $this->config['quality_gates'] ?? [];
    }

    /**
     * Set quality gate threshold.
     */
    public function setQualityGate(string $gate, float $value): void
    {
        if ($value < 0 || $value > 1) {
            throw new \InvalidArgumentException('Value must be between 0 and 1');
        }

        $this->config['quality_gates'][$gate] = $value;
    }

    /**
     * Get cooldown configuration.
     */
    public function getCooldown(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config['cooldown'] ?? [];
        }
        return $this->config['cooldown'][$key] ?? null;
    }

    /**
     * Get blast radius configuration.
     */
    public function getBlastRadius(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config['blast_radius'] ?? [];
        }
        return $this->config['blast_radius'][$key] ?? null;
    }

    /**
     * Get gene selection configuration.
     */
    public function getGeneSelection(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config['gene_selection'] ?? [];
        }
        return $this->config['gene_selection'][$key] ?? null;
    }

    /**
     * Get safety configuration.
     */
    public function getSafety(string $key = null): mixed
    {
        if ($key === null) {
            return $this->config['safety'] ?? [];
        }
        return $this->config['safety'][$key] ?? null;
    }

    /**
     * Check if mutation passes quality gates.
     */
    public function passesQualityGates(array $mutation): array
    {
        $violations = [];

        $confidence = $mutation['confidence'] ?? 0.5;
        if ($confidence < $this->getQualityGate('min_confidence')) {
            $violations[] = "Confidence {$confidence} below threshold {$this->getQualityGate('min_confidence')}";
        }

        $gdi = $mutation['gdi'] ?? $mutation['_gdi'] ?? 0.5;
        if (isset($this->config['quality_gates']['min_gdi']) && $gdi < $this->getQualityGate('min_gdi')) {
            $violations[] = "GDI {$gdi} below threshold {$this->getQualityGate('min_gdi')}";
        }

        return [
            'passed' => empty($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Get available strategy presets.
     */
    public static function getAvailableStrategies(): array
    {
        return array_keys(self::STRATEGY_PRESETS);
    }

    /**
     * Get full configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set configuration value.
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$this->config;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    /**
     * Get configuration value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $current = $this->config;

        foreach ($keys as $k) {
            if (!isset($current[$k])) {
                return $default;
            }
            $current = $current[$k];
        }

        return $current;
    }

    /**
     * Create config from environment variables.
     */
    public static function fromEnvironment(): self
    {
        $config = new self();

        $strategy = getenv('EVOLVER_STRATEGY');
        if ($strategy !== false && $strategy !== '') {
            try {
                $config->setStrategy($strategy);
            } catch (\InvalidArgumentException $e) {
                error_log('[StrategyConfig] Invalid strategy from env: ' . $strategy);
            }
        }

        return $config;
    }
}
