<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Lifecycle Manager - handles process startup, monitoring, and graceful shutdown.
 * 
 * PHP port of lifecycle.js from EvoMap/evolver.
 */
final class LifecycleManager
{
    private array $shutdownHandlers = [];
    private array $startupHandlers = [];
    private bool $isShuttingDown = false;
    private ?int $startTime = null;
    private array $metrics = [
        'cycles_completed' => 0,
        'cycles_failed' => 0,
        'assets_published' => 0,
        'assets_received' => 0,
    ];

    public function __construct()
    {
        $this->startTime = time();
        $this->registerSignalHandlers();
    }

    /**
     * Register a startup handler.
     */
    public function onStartup(callable $handler): void
    {
        $this->startupHandlers[] = $handler;
    }

    /**
     * Register a shutdown handler.
     */
    public function onShutdown(callable $handler): void
    {
        $this->shutdownHandlers[] = $handler;
    }

    /**
     * Execute startup sequence.
     */
    public function startup(): array
    {
        $results = [];
        foreach ($this->startupHandlers as $idx => $handler) {
            try {
                $result = $handler();
                $results[$idx] = ['ok' => true, 'result' => $result];
            } catch (\Throwable $e) {
                $results[$idx] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $results;
    }

    /**
     * Execute graceful shutdown.
     */
    public function shutdown(string $reason = 'unknown'): array
    {
        if ($this->isShuttingDown) {
            return ['ok' => false, 'error' => 'already_shutting_down'];
        }

        $this->isShuttingDown = true;
        error_log("[LifecycleManager] Initiating shutdown: {$reason}");

        $results = [];
        foreach ($this->shutdownHandlers as $idx => $handler) {
            try {
                $result = $handler($reason);
                $results[$idx] = ['ok' => true, 'result' => $result];
            } catch (\Throwable $e) {
                $results[$idx] = ['ok' => false, 'error' => $e->getMessage()];
                error_log("[LifecycleManager] Shutdown handler {$idx} failed: " . $e->getMessage());
            }
        }

        error_log("[LifecycleManager] Shutdown complete");
        return ['ok' => true, 'handlers' => $results];
    }

    /**
     * Check if shutdown is in progress.
     */
    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }

    /**
     * Get process uptime in seconds.
     */
    public function getUptime(): int
    {
        return $this->startTime ? time() - $this->startTime : 0;
    }

    /**
     * Record a completed cycle.
     */
    public function recordCycle(bool $success): void
    {
        if ($success) {
            $this->metrics['cycles_completed']++;
        } else {
            $this->metrics['cycles_failed']++;
        }
    }

    /**
     * Record asset publication.
     */
    public function recordAssetPublished(): void
    {
        $this->metrics['assets_published']++;
    }

    /**
     * Record asset reception.
     */
    public function recordAssetReceived(): void
    {
        $this->metrics['assets_received']++;
    }

    /**
     * Get current metrics.
     */
    public function getMetrics(): array
    {
        return array_merge($this->metrics, [
            'uptime_seconds' => $this->getUptime(),
            'is_shutting_down' => $this->isShuttingDown,
        ]);
    }

    /**
     * Get health status.
     */
    public function getHealth(): array
    {
        $uptime = $this->getUptime();
        $cycleTotal = $this->metrics['cycles_completed'] + $this->metrics['cycles_failed'];
        $successRate = $cycleTotal > 0 ? $this->metrics['cycles_completed'] / $cycleTotal : 1.0;

        $status = 'healthy';
        if ($this->isShuttingDown) {
            $status = 'shutting_down';
        } elseif ($successRate < 0.5 && $cycleTotal > 5) {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'uptime_seconds' => $uptime,
            'success_rate' => $successRate,
            'cycles_completed' => $this->metrics['cycles_completed'],
            'cycles_failed' => $this->metrics['cycles_failed'],
        ];
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
    }

    /**
     * Handle shutdown signals.
     */
    public function handleSignal(int $signal): void
    {
        $signalNames = [
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
        ];
        $name = $signalNames[$signal] ?? 'UNKNOWN';
        $this->shutdown($name);
        exit(0);
    }

    /**
     * Create a health check endpoint response.
     */
    public function createHealthResponse(): array
    {
        $health = $this->getHealth();
        
        return [
            'status' => $health['status'] === 'healthy' ? 'ok' : 'error',
            'health' => $health,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
