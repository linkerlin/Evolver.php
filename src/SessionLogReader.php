<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Session Log Reader - Reads and analyzes real session logs.
 * PHP port of session log reading functionality from evolver/src/evolve.js
 */
final class SessionLogReader
{
    /** Maximum number of active sessions to analyze */
    private const MAX_ACTIVE_SESSIONS = 6;

    /** 24-hour active window in seconds */
    private const ACTIVE_WINDOW_SECONDS = 24 * 60 * 60;

    /** Session scope environment variable name */
    private const SESSION_SCOPE_ENV = 'EVOLVER_SESSION_SCOPE';

    /** Patterns to identify evolver's own sessions */
    private const EVOLVER_SELF_PATTERNS = [
        'evolver',
        'evolution',
        'mutation',
        'gene_selection',
        'solidify',
    ];

    /**
     * Get the sessions directory path.
     * Checks multiple environment variables and common locations.
     */
    public static function getSessionsDir(): ?string
    {
        // Check environment variables
        $envVars = [
            'SESSIONS_DIR',
            'KIMI_SESSIONS_DIR',
            'CLAUDE_SESSIONS_DIR',
            'EVOLVER_SESSIONS_DIR',
        ];
        
        foreach ($envVars as $var) {
            $dir = getenv($var);
            if ($dir && is_dir($dir)) {
                return $dir;
            }
        }

        // Check common locations
        $commonPaths = [
            $_SERVER['HOME'] . '/.kimi/sessions' ?? null,
            $_SERVER['HOME'] . '/.claude/sessions' ?? null,
            $_SERVER['HOME'] . '/.evolver/sessions' ?? null,
            getcwd() . '/.sessions',
            getcwd() . '/logs',
            sys_get_temp_dir() . '/evolver-sessions',
        ];

        foreach ($commonPaths as $path) {
            if ($path && is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find session log files (JSONL format).
     *
     * @return array<int, array{path: string, mtime: int}>
     */
    public function findSessionFiles(?string $scope = null): array
    {
        $dir = self::getSessionsDir();
        if ($dir === null) {
            return [];
        }

        $files = [];
        $iterator = new \DirectoryIterator($dir);
        
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isDot() || !$fileinfo->isFile()) {
                continue;
            }

            $filename = $fileinfo->getFilename();
            
            // Check for JSONL files
            if (!str_ends_with($filename, '.jsonl') && !str_ends_with($filename, '.log')) {
                continue;
            }

            // Skip evolver's own sessions to avoid circular evolution
            if ($this->isEvolverSession($filename, $fileinfo->getPathname())) {
                continue;
            }

            // Filter by scope if specified
            if ($scope !== null && !$this->matchesScope($filename, $scope)) {
                continue;
            }

            $mtime = $fileinfo->getMTime();
            
            // Check 24-hour active window
            if (time() - $mtime > self::ACTIVE_WINDOW_SECONDS) {
                continue;
            }

            $files[] = [
                'path' => $fileinfo->getPathname(),
                'mtime' => $mtime,
            ];
        }

        // Sort by modification time (most recent first)
        usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return $files;
    }

    /**
     * Check if a session file belongs to evolver itself.
     */
    private function isEvolverSession(string $filename, string $filepath): bool
    {
        $lowerName = strtolower($filename);
        
        foreach (self::EVOLVER_SELF_PATTERNS as $pattern) {
            if (str_contains($lowerName, $pattern)) {
                return true;
            }
        }

        // Check file content for evolver markers
        try {
            $handle = fopen($filepath, 'r');
            if ($handle) {
                $firstLine = fgets($handle);
                fclose($handle);
                
                if ($firstLine !== false) {
                    $lowerLine = strtolower($firstLine);
                    foreach (['evolution', 'mutation', 'gene_id', 'capsule'] as $marker) {
                        if (str_contains($lowerLine, $marker)) {
                            return true;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore errors, assume not evolver session
        }

        return false;
    }

    /**
     * Check if filename matches the given scope.
     */
    private function matchesScope(string $filename, string $scope): bool
    {
        return str_contains(strtolower($filename), strtolower($scope));
    }

    /**
     * Read and parse a single session log file.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readSessionFile(string $filepath): array
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [];
        }

        $entries = [];
        $handle = fopen($filepath, 'r');
        
        if (!$handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $entry = $this->parseSessionEntry($line);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        fclose($handle);
        return $entries;
    }

    /**
     * Parse a single session log entry (JSON format).
     *
     * @return array<string, mixed>|null
     */
    public function parseSessionEntry(string $json): ?array
    {
        try {
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return null;
            }

            // Extract message/tool_result patterns
            $entry = [
                'timestamp' => $data['timestamp'] ?? $data['ts'] ?? null,
                'type' => $data['type'] ?? 'unknown',
                'role' => $data['role'] ?? null,
                'content' => null,
                'tool_calls' => null,
                'tool_results' => null,
                'error' => null,
            ];

            // Handle different entry types
            if (isset($data['message'])) {
                $message = $data['message'];
                if (is_array($message)) {
                    $entry['content'] = $message['content'] ?? null;
                    $entry['role'] = $message['role'] ?? $entry['role'];
                    if (isset($message['tool_calls'])) {
                        $entry['tool_calls'] = $message['tool_calls'];
                    }
                } else {
                    $entry['content'] = (string)$message;
                }
            }

            if (isset($data['content'])) {
                $entry['content'] = is_string($data['content']) ? $data['content'] : json_encode($data['content']);
            }

            // Extract tool results
            if (isset($data['tool_result'])) {
                $entry['tool_results'] = [$data['tool_result']];
            }

            if (isset($data['tool_results']) && is_array($data['tool_results'])) {
                $entry['tool_results'] = $data['tool_results'];
            }

            // Extract tool calls
            if (isset($data['tool_call'])) {
                $entry['tool_calls'] = [$data['tool_call']];
            }

            // Extract error information
            if (isset($data['error'])) {
                $entry['error'] = is_array($data['error']) ? $data['error'] : ['message' => (string)$data['error']];
            }

            if (isset($data['errorMessage'])) {
                $entry['error'] = ['message' => (string)$data['errorMessage']];
            }

            return $entry;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the most active recent session log.
     */
    public function readRealSessionLog(): string
    {
        $files = $this->findSessionFiles();
        
        if (empty($files)) {
            return '';
        }

        // Read the most recent file
        $mostRecent = $files[0];
        $entries = $this->readSessionFile($mostRecent['path']);

        if (empty($entries)) {
            return '';
        }

        return $this->formatEntriesAsText($entries);
    }

    /**
     * Read multiple active sessions (up to MAX_ACTIVE_SESSIONS).
     *
     * @return array<int, array{path: string, mtime: int, entries: array, summary: string}>
     */
    public function readMultipleSessions(int $maxSessions = self::MAX_ACTIVE_SESSIONS): array
    {
        $files = $this->findSessionFiles();
        $sessions = [];

        foreach (array_slice($files, 0, $maxSessions) as $file) {
            $entries = $this->readSessionFile($file['path']);
            
            if (empty($entries)) {
                continue;
            }

            // Deduplicate lines
            $deduplicated = $this->deduplicateLines($entries);

            $sessions[] = [
                'path' => $file['path'],
                'mtime' => $file['mtime'],
                'entries' => $deduplicated,
                'summary' => $this->generateSessionSummary($deduplicated),
            ];
        }

        return $sessions;
    }

    /**
     * Filter sessions by scope.
     *
     * @param array<int, array{path: string, mtime: int, entries: array, summary: string}> $sessions
     * @return array<int, array{path: string, mtime: int, entries: array, summary: string}>
     */
    public function filterByScope(string $scope, array $sessions = []): array
    {
        if (empty($sessions)) {
            $sessions = $this->readMultipleSessions();
        }

        return array_filter($sessions, fn($s) => $this->matchesScope(basename($s['path']), $scope));
    }

    /**
     * Deduplicate entries by folding repeated lines.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function deduplicateLines(array $entries): array
    {
        if (empty($entries)) {
            return [];
        }

        $result = [];
        $prevHash = null;
        $repeatCount = 0;

        foreach ($entries as $entry) {
            $content = $entry['content'] ?? '';
            $hash = md5(substr($content, 0, 200)); // Hash first 200 chars

            if ($hash === $prevHash) {
                $repeatCount++;
                continue;
            }

            // Flush repeated entry marker if needed
            if ($repeatCount > 0) {
                $result[] = [
                    'type' => 'folded',
                    'count' => $repeatCount + 1,
                    'note' => "[{$repeatCount} similar lines folded]",
                ];
                $repeatCount = 0;
            }

            $result[] = $entry;
            $prevHash = $hash;
        }

        // Handle trailing repeats
        if ($repeatCount > 0) {
            $result[] = [
                'type' => 'folded',
                'count' => $repeatCount + 1,
                'note' => "[{$repeatCount} similar lines folded]",
            ];
        }

        return $result;
    }

    /**
     * Generate a summary of session content.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function generateSessionSummary(array $entries): string
    {
        $totalEntries = count($entries);
        $errorCount = 0;
        $toolCallCount = 0;
        $messageCount = 0;

        foreach ($entries as $entry) {
            if ($entry['error'] ?? null) {
                $errorCount++;
            }
            if ($entry['tool_calls'] ?? null) {
                $toolCallCount++;
            }
            if ($entry['type'] === 'message' || ($entry['content'] ?? null)) {
                $messageCount++;
            }
        }

        return sprintf(
            "Total: %d entries, Messages: %d, Tool calls: %d, Errors: %d",
            $totalEntries,
            $messageCount,
            $toolCallCount,
            $errorCount
        );
    }

    /**
     * Format entries as readable text.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    private function formatEntriesAsText(array $entries): string
    {
        $lines = [];

        foreach ($entries as $entry) {
            if ($entry['type'] === 'folded') {
                $lines[] = $entry['note'];
                continue;
            }

            $parts = [];

            if ($entry['timestamp']) {
                $parts[] = '[' . $entry['timestamp'] . ']';
            }

            if ($entry['role']) {
                $parts[] = $entry['role'] . ':';
            }

            if ($entry['content']) {
                $parts[] = substr($entry['content'], 0, 500);
            }

            if ($entry['tool_calls']) {
                foreach ($entry['tool_calls'] as $tool) {
                    $toolName = is_array($tool) ? ($tool['name'] ?? $tool['function']['name'] ?? 'unknown') : (string)$tool;
                    $parts[] = "[TOOL: {$toolName}]";
                }
            }

            if ($entry['error']) {
                $errorMsg = is_array($entry['error']) ? ($entry['error']['message'] ?? json_encode($entry['error'])) : (string)$entry['error'];
                $parts[] = "[ERROR: {$errorMsg}]";
            }

            $line = implode(' ', $parts);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get the current session scope from environment.
     */
    public static function getSessionScope(): ?string
    {
        $scope = getenv(self::SESSION_SCOPE_ENV);
        return $scope !== false && $scope !== '' ? $scope : null;
    }

    /**
     * Extract transcript from session entries (message/tool_result patterns).
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public function extractTranscript(array $entries): string
    {
        $lines = [];

        foreach ($entries as $entry) {
            // Skip folded entries for transcript
            if (($entry['type'] ?? '') === 'folded') {
                continue;
            }

            // Extract message content
            if (!empty($entry['content'])) {
                $role = $entry['role'] ?? 'unknown';
                $lines[] = "[{$role}] {$entry['content']}";
            }

            // Extract tool results
            if (!empty($entry['tool_results'])) {
                foreach ($entry['tool_results'] as $result) {
                    $resultStr = is_array($result) ? json_encode($result) : (string)$result;
                    $lines[] = "[tool_result] {$resultStr}";
                }
            }

            // Extract errors
            if (!empty($entry['error'])) {
                $errorMsg = is_array($entry['error']) ? ($entry['error']['message'] ?? json_encode($entry['error'])) : (string)$entry['error'];
                $lines[] = "[error] {$errorMsg}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Check if there are active sessions within the 24-hour window.
     */
    public function hasActiveSessions(): bool
    {
        $files = $this->findSessionFiles();
        return !empty($files);
    }

    /**
     * Get session statistics.
     *
     * @return array{total_sessions: int, total_entries: int, error_count: int, recent_mtime: int|null}
     */
    public function getSessionStats(): array
    {
        $sessions = $this->readMultipleSessions(self::MAX_ACTIVE_SESSIONS);
        
        $totalEntries = 0;
        $errorCount = 0;
        $recentMtime = null;

        foreach ($sessions as $session) {
            $totalEntries += count($session['entries']);
            $recentMtime = max($recentMtime ?? 0, $session['mtime']);

            foreach ($session['entries'] as $entry) {
                if ($entry['error'] ?? null) {
                    $errorCount++;
                }
            }
        }

        return [
            'total_sessions' => count($sessions),
            'total_entries' => $totalEntries,
            'error_count' => $errorCount,
            'recent_mtime' => $recentMtime,
        ];
    }
}
