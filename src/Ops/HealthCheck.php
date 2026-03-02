<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * System health monitoring.
 * Checks disk space, memory, secrets, and process count.
 *
 * Ported from evolver/src/ops/health_check.js
 */
final class HealthCheck
{
    /**
     * Get disk usage for a mount point.
     */
    private static function getDiskUsage(string $mount = '/'): array
    {
        try {
            // Try using disk_free_space and disk_total_space
            $free = disk_free_space($mount);
            $total = disk_total_space($mount);

            if ($free !== false && $total !== false && $total > 0) {
                $used = $total - $free;
                return [
                    'pct' => (int) round(($used / $total) * 100),
                    'freeMb' => (int) round($free / 1024 / 1024),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'pct' => 0,
                'freeMb' => 999999,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'pct' => 0,
            'freeMb' => 999999,
            'error' => 'Unable to get disk usage',
        ];
    }

    /**
     * Run health check and return status.
     */
    public static function run(): array
    {
        $checks = [];
        $criticalErrors = 0;
        $warnings = 0;

        // 1. Secret Check (Critical for external services)
        $criticalSecrets = ['FEISHU_APP_ID', 'FEISHU_APP_SECRET'];
        foreach ($criticalSecrets as $key) {
            $value = $_ENV[$key] ?? '';
            if (empty($value) || trim($value) === '') {
                $checks[] = [
                    'name' => "env:{$key}",
                    'ok' => false,
                    'status' => 'missing',
                    'severity' => 'warning', // Downgraded to warning to prevent restart loops
                ];
                $warnings++;
            } else {
                $checks[] = [
                    'name' => "env:{$key}",
                    'ok' => true,
                    'status' => 'present',
                ];
            }
        }

        $optionalSecrets = ['CLAWHUB_TOKEN', 'OPENAI_API_KEY'];
        foreach ($optionalSecrets as $key) {
            $value = $_ENV[$key] ?? '';
            if (empty($value) || trim($value) === '') {
                $checks[] = [
                    'name' => "env:{$key}",
                    'ok' => false,
                    'status' => 'missing',
                    'severity' => 'info',
                ];
            } else {
                $checks[] = [
                    'name' => "env:{$key}",
                    'ok' => true,
                    'status' => 'present',
                ];
            }
        }

        // 2. Disk Space Check
        $mount = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';
        $disk = self::getDiskUsage($mount);
        if ($disk['pct'] > 90) {
            $checks[] = [
                'name' => 'disk_space',
                'ok' => false,
                'status' => "{$disk['pct']}% used",
                'severity' => 'critical',
            ];
            $criticalErrors++;
        } elseif ($disk['pct'] > 80) {
            $checks[] = [
                'name' => 'disk_space',
                'ok' => false,
                'status' => "{$disk['pct']}% used",
                'severity' => 'warning',
            ];
            $warnings++;
        } else {
            $checks[] = [
                'name' => 'disk_space',
                'ok' => true,
                'status' => "{$disk['pct']}% used",
            ];
        }

        // 3. Memory Check
        $memFree = function_exists('sys_getloadavg') ? memory_get_available() : false;
        $memTotal = memory_get_usage(true);
        if ($memFree !== false && $memTotal > 0) {
            $memUsed = $memTotal - $memFree;
            $memPct = (int) round(($memUsed / $memTotal) * 100);
        } else {
            // Fallback: use current usage vs memory_limit
            $memLimit = ini_get('memory_limit');
            $memLimitBytes = self::parseMemoryLimit($memLimit);
            $memUsed = memory_get_usage(true);
            $memPct = $memLimitBytes > 0 ? (int) round(($memUsed / $memLimitBytes) * 100) : 0;
        }

        if ($memPct > 95) {
            $checks[] = [
                'name' => 'memory',
                'ok' => false,
                'status' => "{$memPct}% used",
                'severity' => 'critical',
            ];
            $criticalErrors++;
        } else {
            $checks[] = [
                'name' => 'memory',
                'ok' => true,
                'status' => "{$memPct}% used",
            ];
        }

        // 4. Process Count (Linux only)
        if (PHP_OS_FAMILY === 'Linux') {
            try {
                $pids = glob('/proc/[0-9]*', GLOB_ONLYDIR);
                $procCount = $pids !== false ? count($pids) : 0;
                if ($procCount > 2000) {
                    $checks[] = [
                        'name' => 'process_count',
                        'ok' => false,
                        'status' => "{$procCount} procs",
                        'severity' => 'warning',
                    ];
                    $warnings++;
                } else {
                    $checks[] = [
                        'name' => 'process_count',
                        'ok' => true,
                        'status' => "{$procCount} procs",
                    ];
                }
            } catch (\Throwable) {
                // Skip process count check if /proc not accessible
            }
        }

        // Determine Overall Status
        $status = 'ok';
        if ($criticalErrors > 0) {
            $status = 'error';
        } elseif ($warnings > 0) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => $checks,
        ];
    }

    /**
     * Parse PHP memory limit string to bytes.
     */
    private static function parseMemoryLimit(?string $limit): int
    {
        if (empty($limit)) {
            return 0;
        }
        $limit = trim($limit);
        if ($limit === '-1') {
            return 0; // Unlimited
        }

        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }
}

/**
 * Helper function to get available memory.
 * Note: PHP doesn't have a native way to get system free memory.
 * This is a best-effort approximation.
 */
function memory_get_available(): int|false
{
    if (PHP_OS_FAMILY === 'Linux') {
        try {
            $meminfo = file_get_contents('/proc/meminfo');
            if ($meminfo !== false && preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) {
                return (int) $m[1] * 1024; // Convert from kB to bytes
            }
        } catch (\Throwable) {
        }
    }

    return false;
}
