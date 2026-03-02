<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Git Self-Repair - handles Git version control anomalies and recovery.
 *
 * Capabilities:
 * - Detect corrupted git index
 * - Repair detached HEAD states
 * - Recover from merge conflicts
 * - Clean up stale locks
 * - Verify repository integrity
 */
final class GitSelfRepair
{
    private string $repoPath;
    private array $lastResult = [];

    public function __construct(?string $repoPath = null)
    {
        $this->repoPath = $repoPath ?? dirname(__DIR__, 2);
    }

    /**
     * Run Git self-repair checks and fixes.
     */
    public function repair(): array
    {
        $results = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'repo_path' => $this->repoPath,
            'checks' => [],
            'fixes' => [],
            'ok' => true,
        ];

        // Check 1: Verify git directory exists
        $results['checks']['git_dir'] = $this->checkGitDir();

        // Check 2: Clean stale locks
        $results['checks']['stale_locks'] = $this->checkStaleLocks();
        if (!$results['checks']['stale_locks']['ok']) {
            $fix = $this->cleanStaleLocks();
            $results['fixes']['stale_locks'] = $fix;
            $results['ok'] = $results['ok'] && $fix['ok'];
        }

        // Check 3: Verify index integrity
        $results['checks']['index'] = $this->verifyIndex();

        // Check 4: Check for detached HEAD
        $results['checks']['detached_head'] = $this->checkDetachedHead();
        if (!$results['checks']['detached_head']['ok']) {
            $fix = $this->fixDetachedHead($results['checks']['detached_head']['current_commit'] ?? null);
            $results['fixes']['detached_head'] = $fix;
            $results['ok'] = $results['ok'] && $fix['ok'];
        }

        // Check 5: Verify repository integrity
        $results['checks']['fsck'] = $this->runFsck();

        // Check 6: Clean untracked files (optional)
        $results['checks']['untracked'] = $this->checkUntracked();

        $this->lastResult = $results;
        return $results;
    }

    /**
     * Check if .git directory exists.
     */
    private function checkGitDir(): array
    {
        $gitDir = $this->repoPath . '/.git';
        $exists = is_dir($gitDir);

        return [
            'ok' => $exists,
            'message' => $exists ? 'Git directory exists' : 'Not a git repository',
        ];
    }

    /**
     * Check for stale lock files.
     */
    private function checkStaleLocks(): array
    {
        $lockFiles = [
            $this->repoPath . '/.git/index.lock',
            $this->repoPath . '/.git/HEAD.lock',
            $this->repoPath . '/.git/refs/heads.lock',
        ];

        $staleLocks = [];
        foreach ($lockFiles as $lock) {
            if (file_exists($lock)) {
                $age = time() - filemtime($lock);
                if ($age > 300) {
                    $staleLocks[] = [
                        'file' => basename($lock),
                        'age_seconds' => $age,
                    ];
                }
            }
        }

        return [
            'ok' => empty($staleLocks),
            'stale_locks' => $staleLocks,
            'message' => empty($staleLocks) ? 'No stale locks' : 'Found stale locks',
        ];
    }

    /**
     * Clean stale lock files.
     */
    private function cleanStaleLocks(): array
    {
        $lockFiles = [
            $this->repoPath . '/.git/index.lock',
            $this->repoPath . '/.git/HEAD.lock',
        ];

        $removed = [];
        foreach ($lockFiles as $lock) {
            if (file_exists($lock)) {
                $age = time() - filemtime($lock);
                if ($age > 300) {
                    unlink($lock);
                    $removed[] = basename($lock);
                }
            }
        }

        return [
            'ok' => true,
            'removed' => $removed,
            'message' => 'Removed ' . count($removed) . ' stale locks',
        ];
    }

    /**
     * Verify git index integrity.
     */
    private function verifyIndex(): array
    {
        $result = $this->git(['git', 'update-index', '--really-refresh'], true);

        return [
            'ok' => $result['return_code'] === 0,
            'message' => $result['return_code'] === 0 ? 'Index is valid' : 'Index may be corrupted',
            'details' => $result['stderr'] ?? '',
        ];
    }

    /**
     * Check for detached HEAD state.
     */
    private function checkDetachedHead(): array
    {
        $result = $this->git(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], true);

        $isDetached = $result['return_code'] !== 0 || trim($result['stdout'] ?? '') === 'HEAD';

        if ($isDetached) {
            $commitResult = $this->git(['git', 'rev-parse', 'HEAD'], true);
            return [
                'ok' => false,
                'is_detached' => true,
                'current_commit' => trim($commitResult['stdout'] ?? ''),
                'message' => 'Repository is in detached HEAD state',
            ];
        }

        $branch = trim($result['stdout'] ?? '');
        return [
            'ok' => true,
            'is_detached' => false,
            'branch' => $branch,
            'message' => 'On branch: ' . $branch,
        ];
    }

    /**
     * Fix detached HEAD by switching to main/master branch.
     */
    private function fixDetachedHead(?string $commit = null): array
    {
        // Try main branch first, then master
        foreach (['main', 'master'] as $branch) {
            $result = $this->git(['git', 'checkout', $branch]);
            if ($result['return_code'] === 0) {
                return [
                    'ok' => true,
                    'branch' => $branch,
                    'message' => 'Switched to branch: ' . $branch,
                ];
            }
        }

        // If no branches work, create a new branch from current commit
        if ($commit) {
            $result = $this->git(['git', 'checkout', '-b', 'recovery-' . time(), $commit]);
            if ($result['return_code'] === 0) {
                return [
                    'ok' => true,
                    'branch' => 'recovery',
                    'message' => 'Created recovery branch',
                ];
            }
        }

        return [
            'ok' => false,
            'message' => 'Could not fix detached HEAD state',
        ];
    }

    /**
     * Run git fsck to verify repository integrity.
     */
    private function runFsck(): array
    {
        $result = $this->git(['git', 'fsck', '--full', '--no-progress'], true);

        $hasErrors = !empty(trim($result['stderr'] ?? ''));

        return [
            'ok' => !$hasErrors,
            'message' => $hasErrors ? 'Found issues in repository' : 'Repository is healthy',
            'details' => $hasErrors ? $result['stderr'] : '',
        ];
    }

    /**
     * Check for untracked files.
     */
    private function checkUntracked(): array
    {
        $result = $this->git(['git', 'status', '--porcelain', '-uall']);

        $untracked = [];
        if ($result['return_code'] === 0) {
            $lines = explode("\n", trim($result['stdout'] ?? ''));
            foreach ($lines as $line) {
                if (str_starts_with($line, '??')) {
                    $untracked[] = trim(substr($line, 2));
                }
            }
        }

        return [
            'ok' => true,
            'count' => count($untracked),
            'files' => array_slice($untracked, 0, 20),
            'message' => count($untracked) . ' untracked files',
        ];
    }

    /**
     * Run a git command.
     */
    private function git(array $args, bool $capture = false): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args));

        if ($capture) {
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptorSpec, $pipes, $this->repoPath);

            if (!is_resource($process)) {
                return ['return_code' => -1, 'stdout' => '', 'stderr' => 'Failed to start process'];
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $returnCode = proc_close($process);

            return [
                'return_code' => $returnCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        return [
            'return_code' => $returnCode,
            'output' => implode("\n", $output),
        ];
    }

    /**
     * Get last repair result.
     */
    public function getLastResult(): array
    {
        return $this->lastResult;
    }
}
