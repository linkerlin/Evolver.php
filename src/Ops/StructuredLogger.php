<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Structured Logger - provides JSON Lines logging with rotation.
 *
 * Features:
 * - Structured JSON logging
 * - Log rotation by size and time
 * - Multiple log levels
 * - Separate evolution event logging
 */
final class StructuredLogger
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    private string $logDir;
    private string $logFile;
    private string $evolutionLogFile;
    private int $maxFileSize;
    private int $maxFiles;
    private string $minLevel;
    private bool $initialized = false;

    public function __construct(
        ?string $logDir = null,
        int $maxFileSize = 10 * 1024 * 1024,
        int $maxFiles = 5,
        string $minLevel = self::LEVEL_INFO
    ) {
        $this->logDir = $logDir ?? dirname(__DIR__, 2) . '/data/logs';
        $this->maxFileSize = $maxFileSize;
        $this->maxFiles = $maxFiles;
        $this->minLevel = $minLevel;
        $this->logFile = $this->logDir . '/evolver.log';
        $this->evolutionLogFile = $this->logDir . '/evolution.log';

        $this->initialize();
    }

    /**
     * Initialize log directory and files.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $this->initialized = true;
    }

    /**
     * Check if a log level should be logged.
     */
    private function shouldLog(string $level): bool
    {
        $levels = [
            self::LEVEL_DEBUG => 0,
            self::LEVEL_INFO => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_CRITICAL => 4,
        ];

        $currentLevel = $levels[$this->minLevel] ?? 1;
        $msgLevel = $levels[$level] ?? 1;

        return $msgLevel >= $currentLevel;
    }

    /**
     * Log a message.
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $this->checkRotation();

        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'pid' => getmypid(),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->logFile, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Log debug message.
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log critical message.
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Log evolution event (separate file).
     */
    public function logEvolution(array $event): void
    {
        $this->checkEvolutionRotation();

        $entry = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'event' => $event,
        ];

        $json = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->evolutionLogFile, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Check and rotate main log file.
     */
    private function checkRotation(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        if (filesize($this->logFile) >= $this->maxFileSize) {
            $this->rotate($this->logFile);
        }
    }

    /**
     * Check and rotate evolution log file.
     */
    private function checkEvolutionRotation(): void
    {
        if (!file_exists($this->evolutionLogFile)) {
            return;
        }

        if (filesize($this->evolutionLogFile) >= $this->maxFileSize) {
            $this->rotate($this->evolutionLogFile);
        }
    }

    /**
     * Rotate a log file.
     */
    private function rotate(string $logFile): void
    {
        // Remove oldest file if we have too many
        $pattern = $logFile . '.*';
        $files = glob($pattern);
        if (count($files) >= $this->maxFiles) {
            usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
            for ($i = $this->maxFiles - 1; $i < count($files); $i++) {
                @unlink($files[$i]);
            }
        }

        // Rename current file with timestamp
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d-His');
        $rotatedFile = $logFile . '.' . $timestamp;
        rename($logFile, $rotatedFile);

        // Also compress old files
        $this->compressOldFiles($logFile);
    }

    /**
     * Compress old log files.
     */
    private function compressOldFiles(string $logFile): void
    {
        $pattern = $logFile . '.*';
        $files = glob($pattern);

        foreach ($files as $file) {
            if (!str_ends_with($file, '.gz') && !str_ends_with($file, '.rotating')) {
                $gzFile = $file . '.gz';
                if (!file_exists($gzFile) && extension_loaded('zlib')) {
                    $content = file_get_contents($file);
                    $compressed = gzencode($content, 6);
                    if ($compressed !== false) {
                        file_put_contents($gzFile, $compressed);
                        unlink($file);
                    }
                }
            }
        }
    }

    /**
     * Get log file paths.
     */
    public function getLogFiles(): array
    {
        return [
            'main' => $this->logFile,
            'evolution' => $this->evolutionLogFile,
        ];
    }

    /**
     * Clean old log files.
     */
    public function cleanOldLogs(int $maxAgeDays = 30): int
    {
        $count = 0;
        $cutoff = time() - ($maxAgeDays * 86400);
        $patterns = [
            $this->logDir . '/evolver.log*',
            $this->logDir . '/evolution.log*',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $file) {
                if (filemtime($file) < $cutoff) {
                    unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get current log size.
     */
    public function getLogSize(): array
    {
        return [
            'main' => file_exists($this->logFile) ? filesize($this->logFile) : 0,
            'evolution' => file_exists($this->evolutionLogFile) ? filesize($this->evolutionLogFile) : 0,
        ];
    }
}
