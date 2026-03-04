<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Enhanced Blast Radius Calculator.
 * 
 * Computes detailed blast radius statistics including:
 * - File count and line churn (added/deleted)
 * - Git numstat parsing for accurate line counts
 * - Constraint policy-based file filtering
 * - Directory distribution analysis
 * - Untracked file line counting
 * 
 * Ported from evolver/src/gep/solidify.js computeBlastRadius()
 */
final class BlastRadiusCalculator
{
    /** Default constraint policy */
    private const DEFAULT_POLICY = [
        'excludePrefixes' => ['logs/', 'memory/', 'assets/gep/', 'out/', 'temp/', 'node_modules/', 'vendor/', '.git/'],
        'excludeExact' => ['event.json', 'temp_gep_output.json', 'temp_evolution_output.json', 'evolution_error.log'],
        'excludeRegex' => ['capsule', 'events?\.jsonl$'],
        'includePrefixes' => ['src/', 'scripts/', 'config/', 'tests/', 'app/', 'lib/'],
        'includeExact' => ['index.js', 'package.json', 'composer.json', 'evolver.php'],
        'includeExtensions' => ['.js', '.cjs', '.mjs', '.ts', '.tsx', '.json', '.yaml', '.yml', '.toml', '.ini', '.sh', '.php', '.py', '.go', '.rs', '.java', '.cpp', '.c', '.h', '.md'],
    ];

    private string $repoRoot;
    private array $policy;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/\\');
        $this->policy = $this->readConstraintPolicy();
    }

    /**
     * Compute detailed blast radius statistics.
     * 
     * @param array<string> $baselineUntracked Files that were untracked at baseline
     * @return array{
     *   files: int,
     *   lines: int,
     *   linesAdded: int,
     *   linesDeleted: int,
     *   changedFiles: array<string>,
     *   ignoredFiles: array<string>,
     *   allChangedFiles: array<string>,
     *   directoryBreakdown: array<array{dir: string, files: int}>,
     *   topDirectories: array<array{dir: string, files: int}>,
     *   unstagedChurn: int,
     *   stagedChurn: int,
     *   untrackedLines: int,
     * }
     */
    public function compute(array $baselineUntracked = []): array
    {
        // Get list of changed files
        $changedFiles = $this->gitListChangedFiles();
        
        // Filter out baseline untracked files
        if (!empty($baselineUntracked)) {
            $baselineSet = array_flip(array_map([$this, 'normalizeRelPath'], $baselineUntracked));
            $changedFiles = array_filter($changedFiles, fn($f) => !isset($baselineSet[$this->normalizeRelPath($f)]));
        }

        // Apply constraint policy
        $countedFiles = array_filter($changedFiles, fn($f) => $this->isConstraintCountedPath($f));
        $ignoredFiles = array_filter($changedFiles, fn($f) => !$this->isConstraintCountedPath($f));

        // Get detailed line statistics from git numstat
        $numstat = $this->runGitNumstat();
        $stagedRows = $numstat['staged'] ?? [];
        $unstagedRows = $numstat['unstaged'] ?? [];

        // Calculate churn from staged and unstaged changes
        $stagedChurn = 0;
        $unstagedChurn = 0;
        $linesAdded = 0;
        $linesDeleted = 0;

        foreach (array_merge($unstagedRows, $stagedRows) as $row) {
            if (!$this->isConstraintCountedPath($row['file'])) {
                continue;
            }
            $churn = $row['added'] + $row['deleted'];
            $linesAdded += $row['added'];
            $linesDeleted += $row['deleted'];
            
            if (in_array($row, $stagedRows)) {
                $stagedChurn += $churn;
            } else {
                $unstagedChurn += $churn;
            }
        }

        // Count lines in untracked files
        $untrackedLines = $this->countUntrackedLines($baselineUntracked);

        // Calculate total churn
        $totalChurn = $stagedChurn + $unstagedChurn + $untrackedLines;

        // Analyze directory breakdown
        $directoryBreakdown = $this->analyzeDirectoryBreakdown($countedFiles, 10);
        $topDirectories = array_slice($directoryBreakdown, 0, 5);

        return [
            'files' => count($countedFiles),
            'lines' => $totalChurn,
            'linesAdded' => $linesAdded,
            'linesDeleted' => $linesDeleted,
            'changedFiles' => array_values($countedFiles),
            'ignoredFiles' => array_values($ignoredFiles),
            'allChangedFiles' => array_values($changedFiles),
            'directoryBreakdown' => $directoryBreakdown,
            'topDirectories' => $topDirectories,
            'unstagedChurn' => $unstagedChurn,
            'stagedChurn' => $stagedChurn,
            'untrackedLines' => $untrackedLines,
        ];
    }

    /**
     * Get list of changed files from git.
     * 
     * @return array<string>
     */
    private function gitListChangedFiles(): array
    {
        $files = [];

        // Get unstaged changes
        $unstaged = $this->runGitCommand('diff --name-only');
        if ($unstaged['ok']) {
            $files = array_merge($files, $this->parseFileList($unstaged['output']));
        }

        // Get staged changes
        $staged = $this->runGitCommand('diff --cached --name-only');
        if ($staged['ok']) {
            $files = array_merge($files, $this->parseFileList($staged['output']));
        }

        // Get untracked files
        $untracked = $this->runGitCommand('ls-files --others --exclude-standard');
        if ($untracked['ok']) {
            $files = array_merge($files, $this->parseFileList($untracked['output']));
        }

        // Normalize and deduplicate
        $normalized = array_map([$this, 'normalizeRelPath'], $files);
        return array_values(array_unique(array_filter($normalized)));
    }

    /**
     * Run git numstat to get detailed line statistics.
     * 
     * @return array{staged: array, unstaged: array}
     */
    private function runGitNumstat(): array
    {
        $result = ['staged' => [], 'unstaged' => []];

        // Unstaged changes
        $unstaged = $this->runGitCommand('diff --numstat');
        if ($unstaged['ok']) {
            $result['unstaged'] = $this->parseNumstatRows($unstaged['output']);
        }

        // Staged changes
        $staged = $this->runGitCommand('diff --cached --numstat');
        if ($staged['ok']) {
            $result['staged'] = $this->parseNumstatRows($staged['output']);
        }

        return $result;
    }

    /**
     * Parse git numstat output.
     * 
     * Format: "<added>\t<deleted>\t<file>"
     * 
     * @return array<array{file: string, added: int, deleted: int}>
     */
    private function parseNumstatRows(string $text): array
    {
        $rows = [];
        $lines = array_filter(array_map('trim', explode("\n", $text)));

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 3) {
                continue;
            }

            $added = is_numeric($parts[0]) ? (int)$parts[0] : 0;
            $deleted = is_numeric($parts[1]) ? (int)$parts[1] : 0;
            $file = $parts[2];

            // Handle rename detection: "{old => new}.ext" or "old => new.ext"
            if (str_contains($file, '=>')) {
                // Match content between => and } or end of string
                // Handle: "{old.php => new.php}" or "{old => new}.php" or "old.php => new.php"
                if (str_contains($file, '{')) {
                    // Format: "{old.php => new.php}" or "{old => new}.php"
                    // Extract after => and before } (including any extension after })
                    if (preg_match('/=>\s*([^}]+)/', $file, $matches)) {
                        $newName = trim($matches[1]);
                        // Check for extension after }
                        if (preg_match('/}\s*(\.\w+)/', $file, $extMatch)) {
                            $newName .= $extMatch[1];
                        }
                        $file = $newName;
                    }
                } else {
                    // Format: "old.php => new.php"
                    if (preg_match('/=>\s*(.+?)$/', $file, $matches)) {
                        $file = trim($matches[1]);
                    }
                }
            }

            $rows[] = [
                'file' => $this->normalizeRelPath($file),
                'added' => $added,
                'deleted' => $deleted,
            ];
        }

        return $rows;
    }

    /**
     * Count lines in untracked files.
     * 
     * @param array<string> $baselineUntracked
     */
    private function countUntrackedLines(array $baselineUntracked): int
    {
        $result = $this->runGitCommand('ls-files --others --exclude-standard');
        if (!$result['ok']) {
            return 0;
        }

        $files = $this->parseFileList($result['output']);
        $baselineSet = array_flip(array_map([$this, 'normalizeRelPath'], $baselineUntracked));
        
        $totalLines = 0;

        foreach ($files as $rel) {
            $norm = $this->normalizeRelPath($rel);
            
            // Skip baseline untracked
            if (isset($baselineSet[$norm])) {
                continue;
            }

            // Skip files not matching constraint policy
            if (!$this->isConstraintCountedPath($norm)) {
                continue;
            }

            // Count lines in file
            $abs = $this->repoRoot . DIRECTORY_SEPARATOR . $norm;
            $totalLines += $this->countFileLines($abs);
        }

        return $totalLines;
    }

    /**
     * Count lines in a file.
     */
    private function countFileLines(string $absPath): int
    {
        if (!file_exists($absPath) || !is_readable($absPath)) {
            return 0;
        }

        $content = @file_get_contents($absPath);
        if ($content === false || $content === '') {
            return 0;
        }

        // Count newlines
        $lines = substr_count($content, "\n");
        
        // If content doesn't end with newline, add 1 for the last line
        if (!str_ends_with($content, "\n")) {
            $lines++;
        }

        // Every non-empty file has at least 1 line
        return max(1, $lines);
    }

    /**
     * Analyze directory breakdown of changed files.
     * 
     * @param array<string> $changedFiles
     * @return array<array{dir: string, files: int}>
     */
    private function analyzeDirectoryBreakdown(array $changedFiles, int $topN = 10): array
    {
        $dirCount = [];

        foreach ($changedFiles as $f) {
            $rel = $this->normalizeRelPath($f);
            if ($rel === '') {
                continue;
            }

            // Use first path segment as the group key (top-level directory)
            $parts = explode('/', $rel);
            $key = $parts[0];
            if (count($parts) >= 2) {
                // For nested paths, use first two segments
                $key = $parts[0] . '/' . $parts[1];
            }
            $dirCount[$key] = ($dirCount[$key] ?? 0) + 1;
        }

        // Sort by count descending
        arsort($dirCount);

        // Format result
        $result = [];
        $count = 0;
        foreach ($dirCount as $dir => $files) {
            if ($count++ >= $topN) {
                break;
            }
            $result[] = ['dir' => $dir, 'files' => $files];
        }

        return $result;
    }

    /**
     * Read constraint policy from configuration file or use defaults.
     */
    private function readConstraintPolicy(): array
    {
        $defaults = self::DEFAULT_POLICY;

        // Try to read from .evolver.json
        $configPath = $this->repoRoot . DIRECTORY_SEPARATOR . '.evolver.json';
        if (!file_exists($configPath)) {
            // Also try openclaw.json for compatibility
            $configPath = dirname($this->repoRoot) . DIRECTORY_SEPARATOR . 'openclaw.json';
        }

        if (!file_exists($configPath)) {
            return $defaults;
        }

        try {
            $content = @file_get_contents($configPath);
            if ($content === false) {
                return $defaults;
            }

            $obj = json_decode($content, true);
            if (!is_array($obj)) {
                return $defaults;
            }

            // Check for evolver.constraints.countedFilePolicy
            $pol = $obj['evolver']['constraints']['countedFilePolicy'] ?? null;
            if (!is_array($pol)) {
                return $defaults;
            }

            return [
                'excludePrefixes' => is_array($pol['excludePrefixes'] ?? null) 
                    ? array_map('strval', $pol['excludePrefixes']) 
                    : $defaults['excludePrefixes'],
                'excludeExact' => is_array($pol['excludeExact'] ?? null) 
                    ? array_map('strval', $pol['excludeExact']) 
                    : $defaults['excludeExact'],
                'excludeRegex' => is_array($pol['excludeRegex'] ?? null) 
                    ? array_map('strval', $pol['excludeRegex']) 
                    : $defaults['excludeRegex'],
                'includePrefixes' => is_array($pol['includePrefixes'] ?? null) 
                    ? array_map('strval', $pol['includePrefixes']) 
                    : $defaults['includePrefixes'],
                'includeExact' => is_array($pol['includeExact'] ?? null) 
                    ? array_map('strval', $pol['includeExact']) 
                    : $defaults['includeExact'],
                'includeExtensions' => is_array($pol['includeExtensions'] ?? null) 
                    ? array_map('strval', $pol['includeExtensions']) 
                    : $defaults['includeExtensions'],
            ];
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * Check if a path should be counted according to constraint policy.
     */
    private function isConstraintCountedPath(string $relPath): bool
    {
        $rel = $this->normalizeRelPath($relPath);
        if ($rel === '') {
            return false;
        }

        // Check exclusions first
        if ($this->matchAnyExact($rel, $this->policy['excludeExact'])) {
            return false;
        }
        if ($this->matchAnyPrefix($rel, $this->policy['excludePrefixes'])) {
            return false;
        }
        if ($this->matchAnyRegex($rel, $this->policy['excludeRegex'])) {
            return false;
        }

        // Check inclusions
        if ($this->matchAnyExact($rel, $this->policy['includeExact'])) {
            return true;
        }
        if ($this->matchAnyPrefix($rel, $this->policy['includePrefixes'])) {
            return true;
        }

        // Check extensions
        $lower = strtolower($rel);
        foreach ($this->policy['includeExtensions'] as $ext) {
            if (str_ends_with($lower, strtolower($ext))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match path against prefix list.
     * 
     * @param array<string> $prefixes
     */
    private function matchAnyPrefix(string $rel, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            $n = rtrim($this->normalizeRelPath($p), '/');
            if ($n === '') {
                continue;
            }
            if ($rel === $n || str_starts_with($rel, $n . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match path against exact list.
     * 
     * @param array<string> $exacts
     */
    private function matchAnyExact(string $rel, array $exacts): bool
    {
        $set = array_flip(array_map([$this, 'normalizeRelPath'], $exacts));
        return isset($set[$rel]);
    }

    /**
     * Match path against regex list.
     * 
     * @param array<string> $regexList
     */
    private function matchAnyRegex(string $rel, array $regexList): bool
    {
        foreach ($regexList as $raw) {
            try {
                if (preg_match('/' . $raw . '/i', $rel)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Invalid regex, skip
                continue;
            }
        }
        return false;
    }

    /**
     * Run a git command in the repository.
     * 
     * @return array{ok: bool, output: string, error: string}
     */
    private function runGitCommand(string $args): array
    {
        $cmd = 'git ' . $args;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            return ['ok' => false, 'output' => '', 'error' => 'Failed to start git process'];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'output' => $stdout !== false ? $stdout : '',
            'error' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * Parse file list from git output.
     * 
     * @return array<string>
     */
    private function parseFileList(string $text): array
    {
        return array_filter(array_map('trim', explode("\n", $text)));
    }

    /**
     * Normalize relative path.
     */
    private function normalizeRelPath(string $relPath): string
    {
        return trim(str_replace(['\\', './'], ['/', ''], $relPath));
    }

    /**
     * Get the current constraint policy.
     */
    public function getPolicy(): array
    {
        return $this->policy;
    }

    /**
     * Set a custom constraint policy.
     */
    public function setPolicy(array $policy): void
    {
        $this->policy = array_merge($this->policy, $policy);
    }
}
