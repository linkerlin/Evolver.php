<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Disk Cleaner - manages storage by cleaning old logs, events, and temporary files.
 * 
 * PHP port of cleanup.js from EvoMap/evolver.
 */
final class DiskCleaner
{
    private string $dataDir;
    private array $config;

    public function __construct(?string $dataDir = null, array $config = [])
    {
        $this->dataDir = $dataDir ?: (dirname(__DIR__, 2) . '/data');
        $this->config = array_merge([
            'max_log_age_days' => 7,
            'max_event_age_days' => 30,
            'max_capsule_age_days' => 90,
            'max_temp_age_hours' => 24,
            'min_free_space_mb' => 100,
            'compress_logs_older_than_days' => 3,
        ], $config);
    }

    /**
     * Run cleanup operation.
     */
    public function cleanup(): array
    {
        $results = [
            'logs_cleaned' => 0,
            'events_archived' => 0,
            'capsules_archived' => 0,
            'temp_files_removed' => 0,
            'space_freed_bytes' => 0,
        ];

        // Clean old logs
        $logResult = $this->cleanLogs();
        $results['logs_cleaned'] = $logResult['count'];
        $results['space_freed_bytes'] += $logResult['bytes_freed'];

        // Archive old events
        $eventResult = $this->archiveOldEvents();
        $results['events_archived'] = $eventResult['count'];

        // Clean temp files
        $tempResult = $this->cleanTempFiles();
        $results['temp_files_removed'] = $tempResult['count'];
        $results['space_freed_bytes'] += $tempResult['bytes_freed'];

        // Check disk space
        $spaceCheck = $this->checkDiskSpace();
        $results['disk_space_ok'] = $spaceCheck['ok'];
        $results['free_space_mb'] = $spaceCheck['free_mb'];

        return $results;
    }

    /**
     * Clean old log files.
     */
    public function cleanLogs(): array
    {
        $logDir = $this->dataDir . '/logs';
        if (!is_dir($logDir)) {
            return ['count' => 0, 'bytes_freed' => 0];
        }

        $count = 0;
        $bytesFreed = 0;
        $now = time();
        $maxAge = $this->config['max_log_age_days'] * 86400;
        $compressAge = $this->config['compress_logs_older_than_days'] * 86400;

        foreach (glob($logDir . '/*.jsonl') as $file) {
            $age = $now - filemtime($file);
            
            if ($age > $maxAge) {
                $bytesFreed += filesize($file);
                unlink($file);
                $count++;
            } elseif ($age > $compressAge && !str_ends_with($file, '.gz')) {
                // Compress old logs
                $this->compressFile($file);
            }
        }

        // Clean old compressed logs too
        foreach (glob($logDir . '/*.jsonl.gz') as $file) {
            $age = $now - filemtime($file);
            if ($age > $maxAge) {
                $bytesFreed += filesize($file);
                unlink($file);
                $count++;
            }
        }

        return ['count' => $count, 'bytes_freed' => $bytesFreed];
    }

    /**
     * Archive old evolution events.
     */
    public function archiveOldEvents(): array
    {
        $archiveDir = $this->dataDir . '/archive';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }

        // This would typically archive from the database
        // For now, just return a placeholder
        return ['count' => 0, 'archived_to' => $archiveDir];
    }

    /**
     * Clean temporary files.
     */
    public function cleanTempFiles(): array
    {
        $tempDir = $this->dataDir . '/temp';
        if (!is_dir($tempDir)) {
            return ['count' => 0, 'bytes_freed' => 0];
        }

        $count = 0;
        $bytesFreed = 0;
        $now = time();
        $maxAge = $this->config['max_temp_age_hours'] * 3600;

        foreach (glob($tempDir . '/*') as $file) {
            if (is_file($file)) {
                $age = $now - filemtime($file);
                if ($age > $maxAge) {
                    $bytesFreed += filesize($file);
                    unlink($file);
                    $count++;
                }
            }
        }

        return ['count' => $count, 'bytes_freed' => $bytesFreed];
    }

    /**
     * Check available disk space.
     */
    public function checkDiskSpace(): array
    {
        $freeBytes = disk_free_space($this->dataDir);
        $totalBytes = disk_total_space($this->dataDir);
        
        if ($freeBytes === false) {
            return ['ok' => false, 'free_mb' => 0, 'total_mb' => 0];
        }

        $freeMb = (int) ($freeBytes / 1024 / 1024);
        $totalMb = (int) ($totalBytes / 1024 / 1024);
        $minFreeMb = $this->config['min_free_space_mb'];

        return [
            'ok' => $freeMb >= $minFreeMb,
            'free_mb' => $freeMb,
            'total_mb' => $totalMb,
            'min_required_mb' => $minFreeMb,
            'percent_free' => round(($freeBytes / $totalBytes) * 100, 2),
        ];
    }

    /**
     * Get storage statistics.
     */
    public function getStats(): array
    {
        $stats = [
            'data_dir' => $this->dataDir,
            'disk_space' => $this->checkDiskSpace(),
        ];

        // Calculate sizes of various directories
        $stats['logs_size_bytes'] = $this->getDirectorySize($this->dataDir . '/logs');
        $stats['temp_size_bytes'] = $this->getDirectorySize($this->dataDir . '/temp');
        $stats['archive_size_bytes'] = $this->getDirectorySize($this->dataDir . '/archive');

        return $stats;
    }

    /**
     * Compress a file using gzip.
     */
    private function compressFile(string $file): bool
    {
        if (!extension_loaded('zlib')) {
            return false;
        }

        $compressed = $file . '.gz';
        $data = file_get_contents($file);
        $gzipped = gzencode($data, 9);
        
        if ($gzipped === false) {
            return false;
        }

        if (file_put_contents($compressed, $gzipped) !== false) {
            unlink($file);
            return true;
        }

        return false;
    }

    /**
     * Get total size of a directory.
     */
    private function getDirectorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }
}
