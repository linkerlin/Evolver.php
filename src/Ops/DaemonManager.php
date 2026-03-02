<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Daemon Manager - handles daemon mode for Evolver.
 *
 * Features:
 * - Background process management
 * - PID file management
 * - Graceful shutdown
 * - Status reporting
 */
final class DaemonManager
{
    private string $pidFile;
    private string $logFile;
    private int $pollInterval = 5;

    public function __construct(?string $dataDir = null)
    {
        $dataDir = $dataDir ?? dirname(__DIR__, 2) . '/data';
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->pidFile = $dataDir . '/evolver.pid';
        $this->logFile = $dataDir . '/daemon.log';
    }

    /**
     * 启动daemon in background.
     */
    public function start(array $options = []): array
    {
        if ($this->isRunning()) {
            return [
                'ok' => false,
                'error' => 'Daemon is already running',
                'pid' => $this->getPid(),
            ];
        }

        $interval = $options['interval'] ?? 60;
        $reviewMode = $options['review'] ?? false;
        $strategy = $options['strategy'] ?? 'balanced';

        $cmd = $this->buildStartCommand($interval, $reviewMode, $strategy);

        $logHandle = fopen($this->logFile, 'a');
        if ($logHandle === false) {
            return ['ok' => false, 'error' => 'Cannot open log file'];
        }

        $process = proc_open(
            $cmd,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => $logHandle,
                2 => $logHandle,
            ],
            $pipes
        );

        if (!is_resource($process)) {
            fclose($logHandle);
            return ['ok' => false, 'error' => 'Failed to start daemon'];
        }

        proc_close($process);
        fclose($logHandle);

        // Wait a moment for process to start
        usleep(500000);

        if (!$this->isRunning()) {
            return ['ok' => false, 'error' => 'Daemon failed to start'];
        }

        return [
            'ok' => true,
            'pid' => $this->getPid(),
            'message' => 'Daemon started successfully',
        ];
    }

    /**
     * 构建the start command.
     */
    private function buildStartCommand(int $interval, bool $reviewMode, string $strategy): string
    {
        $php = PHP_BINARY;
        $script = dirname(__DIR__, 2) . '/evolver.php';

        $cmd = sprintf(
            '%s %s --loop %d',
            escapeshellcmd($php),
            escapeshellarg($script),
            $interval
        );

        if ($reviewMode) {
            $cmd .= ' --review';
        }

        if ($strategy !== 'balanced') {
            $cmd .= ' --strategy ' . escapeshellarg($strategy);
        }

        return $cmd . ' > /dev/null 2>&1 & echo $!';
    }

    /**
     * 停止daemon gracefully.
     */
    public function stop(): array
    {
        if (!$this->isRunning()) {
            return ['ok' => false, 'error' => 'Daemon is not running'];
        }

        $pid = $this->getPid();
        if ($pid === null) {
            return ['ok' => false, 'error' => 'PID not found'];
        }

        // Send SIGTERM for graceful shutdown
        posix_kill($pid, SIGTERM);

        // Wait for process to exit
        $timeout = 30;
        while ($timeout > 0 && $this->isRunning()) {
            sleep(1);
            $timeout--;
        }

        // Force kill if still running
        if ($this->isRunning()) {
            posix_kill($pid, SIGKILL);
            @unlink($this->pidFile);
        }

        return [
            'ok' => true,
            'message' => 'Daemon stopped',
        ];
    }

    /**
     * Restart daemon.
     */
    public function restart(array $options = []): array
    {
        $this->stop();
        return $this->start($options);
    }

    /**
     * 检查 daemon is running.
     */
    public function isRunning(): bool
    {
        $pid = $this->getPid();
        if ($pid === null) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * 获取daemon PID.
     */
    public function getPid(): ?int
    {
        if (!file_exists($this->pidFile)) {
            return null;
        }

        $pid = (int)file_get_contents($this->pidFile);
        return $pid > 0 ? $pid : null;
    }

    /**
     * 获取daemon status.
     */
    public function getStatus(): array
    {
        $running = $this->isRunning();
        $pid = $this->getPid();

        $status = [
            'running' => $running,
            'pid' => $pid,
            'pid_file' => $this->pidFile,
            'log_file' => $this->logFile,
        ];

        if ($running && $pid !== null) {
            $status['uptime_seconds'] = $this->getUptime($pid);
            $status['memory_mb'] = $this->getMemoryUsage($pid);
        }

        return $status;
    }

    /**
     * 获取process uptime.
     */
    private function getUptime(int $pid): int
    {
        $stat = @proc_get_status($pid);
        if (!$stat || !isset($stat['starttime'])) {
            return 0;
        }

        return time() - ($stat['starttime'] ?? time());
    }

    /**
     * 获取memory usage in MB.
     */
    private function getMemoryUsage(int $pid): float
    {
        $statusFile = "/proc/{$pid}/status";
        if (!file_exists($statusFile)) {
            return 0.0;
        }

        $content = file_get_contents($statusFile);
        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $content, $matches)) {
            return round((int)$matches[1] / 1024, 2);
        }

        return 0.0;
    }

    /**
     * 获取log contents.
     */
    public function getLog(int $lines = 50): array
    {
        if (!file_exists($this->logFile)) {
            return ['lines' => [], 'total' => 0];
        }

        $content = file($this->logFile);
        $total = count($content);

        return [
            'lines' => array_slice($content, -$lines),
            'total' => $total,
        ];
    }

    /**
     * Write PID file.
     */
    public function writePidFile(int $pid): bool
    {
        return file_put_contents($this->pidFile, (string)$pid) !== false;
    }

    /**
     * 移除PID file.
     */
    public function removePidFile(): bool
    {
        if (file_exists($this->pidFile)) {
            return unlink($this->pidFile);
        }
        return true;
    }
}
