<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Solidify evolution results - validate and record evolution events.
 * Updated for GEP 1.6.0 protocol compliance.
 * PHP port of solidify.js from EvoMap/evolver.
 */
final class SolidifyEngine
{
    /** Validation command prefix whitelist (security) */
    private const ALLOWED_COMMAND_PREFIXES = ['php', 'composer', 'phpunit', 'phpcs', 'phpstan'];

    /** Forbidden shell operators */
    private const FORBIDDEN_SHELL_OPERATORS = [';', '&&', '||', '|', '>', '<', '`', '$(' ];

    /** Max validation timeout in seconds */
    private const VALIDATION_TIMEOUT = 60;

    /** Max files per evolution (hard limit) */
    private const MAX_FILES_HARD_LIMIT = 60;

    /** Max lines per evolution (hard limit) */
    private const MAX_LINES_HARD_LIMIT = 20000;

    /** Blast radius warning threshold (80% of limit) */
    private const BLAST_WARN_RATIO = 0.8;

    /** Blast radius critical threshold (200% of limit) */
    private const BLAST_CRITICAL_RATIO = 2.0;

    /** Critical skill directories that evolver must NEVER delete or overwrite */
    private const CRITICAL_PROTECTED_PREFIXES = [
        'skills/feishu-evolver-wrapper/',
        'skills/feishu-common/',
        'skills/feishu-post/',
        'skills/feishu-card/',
        'skills/feishu-doc/',
        'skills/skill-tools/',
        'skills/clawhub/',
        'skills/clawhub-batch-undelete/',
        'skills/git-sync/',
        'skills/evolver/',
    ];

    /** Files at workspace root that must never be deleted by evolver */
    private const CRITICAL_PROTECTED_FILES = [
        'MEMORY.md',
        'SOUL.md',
        'IDENTITY.md',
        'AGENTS.md',
        'USER.md',
        'HEARTBEAT.md',
        'RECENT_EVENTS.md',
        'TOOLS.md',
        'TROUBLESHOOTING.md',
        'openclaw.json',
        '.env',
        'composer.json',
        'package.json',
    ];

    public function __construct(
        private readonly GepAssetStore $store,
        private readonly SignalExtractor $signalExtractor,
        private readonly GeneSelector $selector,
        private readonly ?string $repoRoot = null,
    ) {}

    /**
     * Check if a path is a critical protected path.
     */
    public static function isCriticalProtectedPath(string $relPath): bool
    {
        $rel = self::normalizeRelPath($relPath);
        if ($rel === '') {
            return false;
        }

        // Check protected prefixes (skill directories)
        foreach (self::CRITICAL_PROTECTED_PREFIXES as $prefix) {
            $p = rtrim($prefix, '/');
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }

        // Check protected root files
        foreach (self::CRITICAL_PROTECTED_FILES as $file) {
            if ($rel === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a relative path.
     */
    private static function normalizeRelPath(string $relPath): string
    {
        $rel = str_replace('\\', '/', $relPath);
        $rel = preg_replace('/^\.\/+/', '', $rel);
        return trim($rel);
    }

    /**
     * Classify blast radius severity.
     * 
     * @return array{severity: string, message: string}
     */
    public static function classifyBlastSeverity(array $blast, int $maxFiles = 25): array
    {
        $files = (int)($blast['files'] ?? 0);
        $lines = (int)($blast['lines'] ?? 0);

        // Hard cap breach is always the highest severity
        if ($files > self::MAX_FILES_HARD_LIMIT || $lines > self::MAX_LINES_HARD_LIMIT) {
            return [
                'severity' => 'hard_cap_breach',
                'message' => "HARD CAP BREACH: {$files} files / {$lines} lines exceeds system limit (" . self::MAX_FILES_HARD_LIMIT . " files / " . self::MAX_LINES_HARD_LIMIT . " lines)",
            ];
        }

        if ($maxFiles <= 0) {
            return ['severity' => 'within_limit', 'message' => 'no max_files constraint defined'];
        }

        if ($files > $maxFiles * self::BLAST_CRITICAL_RATIO) {
            return [
                'severity' => 'critical_overrun',
                'message' => "CRITICAL OVERRUN: {$files} files > " . (int)($maxFiles * self::BLAST_CRITICAL_RATIO) . " (" . self::BLAST_CRITICAL_RATIO . "x limit of {$maxFiles}). Agent likely performed bulk/unintended operation.",
            ];
        }

        if ($files > $maxFiles) {
            return [
                'severity' => 'exceeded',
                'message' => "max_files exceeded: {$files} > {$maxFiles}",
            ];
        }

        if ($files > $maxFiles * self::BLAST_WARN_RATIO) {
            $pct = (int)(($files / $maxFiles) * 100);
            return [
                'severity' => 'approaching_limit',
                'message' => "approaching limit: {$files} / {$maxFiles} files ({$pct}%)",
            ];
        }

        return ['severity' => 'within_limit', 'message' => "{$files} / {$maxFiles} files"];
    }

    /**
     * Analyze which directory prefixes contribute the most changed files.
     * 
     * @param array<string> $changedFiles
     * @return array<array{dir: string, files: int}>
     */
    public static function analyzeBlastRadiusBreakdown(array $changedFiles, int $topN = 5): array
    {
        $dirCount = [];
        foreach ($changedFiles as $f) {
            $rel = self::normalizeRelPath($f);
            if ($rel === '') {
                continue;
            }
            // Use first two path segments as the group key
            $parts = explode('/', $rel);
            $key = count($parts) >= 2 ? $parts[0] . '/' . $parts[1] : $parts[0];
            $dirCount[$key] = ($dirCount[$key] ?? 0) + 1;
        }

        arsort($dirCount);
        $result = [];
        $count = 0;
        foreach ($dirCount as $dir => $files) {
            if ($count++ >= $topN) break;
            $result[] = ['dir' => $dir, 'files' => $files];
        }
        return $result;
    }

    /**
     * Detect destructive changes to critical files.
     * 
     * @param array<string> $changedFiles
     * @param array<string> $baselineUntracked
     * @return array<string> Violations
     */
    public function detectDestructiveChanges(string $repoRoot, array $changedFiles, array $baselineUntracked = []): array
    {
        $violations = [];
        $baselineSet = array_flip(array_map([self::class, 'normalizeRelPath'], $baselineUntracked));

        foreach ($changedFiles as $rel) {
            $norm = self::normalizeRelPath($rel);
            if ($norm === '' || !self::isCriticalProtectedPath($norm)) {
                continue;
            }

            $abs = rtrim($repoRoot, '/\\') . DIRECTORY_SEPARATOR . $norm;
            $normAbs = realpath($abs) ?: $abs;
            $normRepo = realpath($repoRoot) ?: $repoRoot;
            if (!str_starts_with($normAbs, $normRepo)) {
                continue;
            }

            // If a critical file existed before but is now missing/empty, that is destructive
            if (!isset($baselineSet[$norm])) {
                if (!file_exists($normAbs)) {
                    $violations[] = "CRITICAL_FILE_DELETED: {$norm}";
                } else {
                    $stat = @stat($normAbs);
                    if ($stat !== false && $stat['size'] === 0) {
                        $violations[] = "CRITICAL_FILE_EMPTIED: {$norm}";
                    }
                }
            }
        }
        return $violations;
    }

    /**
     * Run canary check to verify index.php loads before commit.
     */
    public function runCanaryCheck(string $repoRoot, int $timeoutMs = 30000): array
    {
        $canaryFile = rtrim($repoRoot, '/\\') . DIRECTORY_SEPARATOR . 'canary.php';
        if (!file_exists($canaryFile)) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'canary.php not found'];
        }

        $cmd = 'php -l ' . escapeshellarg($canaryFile) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return [
            'ok' => $exitCode === 0,
            'skipped' => false,
            'out' => implode("\n", array_slice($output, 0, 20)),
            'err' => $exitCode !== 0 ? implode("\n", $output) : '',
        ];
    }

    /**
     * Rollback tracked files to last commit.
     */
    public function rollbackTracked(string $repoRoot): void
    {
        $repoRoot = escapeshellarg($repoRoot);
        exec("git -C {$repoRoot} restore --staged --worktree . 2>&1");
        exec("git -C {$repoRoot} reset --hard 2>&1");
    }

    /**
     * Rollback new untracked files that were created during evolution.
     * 
     * @param array<string> $baselineUntracked
     * @return array{deleted: array<string>, skipped: array<string>, removedDirs: array<string>}
     */
    public function rollbackNewUntrackedFiles(string $repoRoot, array $baselineUntracked = []): array
    {
        $baseline = array_flip(array_map('strval', $baselineUntracked));
        $current = $this->gitListUntrackedFiles($repoRoot);
        $toDelete = array_filter($current, fn($f) => !isset($baseline[$f]));

        $deleted = [];
        $skipped = [];

        foreach ($toDelete as $rel) {
            $safeRel = self::normalizeRelPath($rel);
            if ($safeRel === '') {
                continue;
            }
            // CRITICAL: Never delete files inside protected skill directories
            if (self::isCriticalProtectedPath($safeRel)) {
                $skipped[] = $safeRel;
                continue;
            }

            $abs = rtrim($repoRoot, '/\\') . DIRECTORY_SEPARATOR . $safeRel;
            $normRepo = realpath($repoRoot) ?: $repoRoot;
            $normAbs = realpath($abs) ?: $abs;
            if (!str_starts_with($normAbs, $normRepo)) {
                continue;
            }

            if (is_file($normAbs)) {
                @unlink($normAbs);
                $deleted[] = $safeRel;
            }
        }

        if (!empty($skipped)) {
            error_log("[Rollback] Skipped " . count($skipped) . " critical protected file(s): " . implode(', ', array_slice($skipped, 0, 5)));
        }

        // Clean up empty directories
        $removedDirs = $this->cleanupEmptyDirectories($repoRoot, $deleted);
        if (!empty($removedDirs)) {
            error_log("[Rollback] Removed " . count($removedDirs) . " empty director" . (count($removedDirs) === 1 ? 'y' : 'ies') . ": " . implode(', ', array_slice($removedDirs, 0, 5)));
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'removedDirs' => $removedDirs];
    }

    /**
     * List untracked files in git.
     * @return array<string>
     */
    private function gitListUntrackedFiles(string $repoRoot): array
    {
        $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' ls-files --others --exclude-standard 2>&1';
        $output = [];
        exec($cmd, $output);
        return array_filter(array_map('trim', $output), fn($l) => $l !== '');
    }

    /**
     * Clean up empty directories after file deletion.
     * @param array<string> $deletedFiles
     * @return array<string> Removed directories
     */
    private function cleanupEmptyDirectories(string $repoRoot, array $deletedFiles): array
    {
        $dirsToCheck = [];
        foreach ($deletedFiles as $file) {
            $dir = dirname($file);
            while ($dir !== '.' && $dir !== '/') {
                $normalized = str_replace('\\', '/', $dir);
                if (!str_contains($normalized, '/')) {
                    break;
                }
                $dirsToCheck[$dir] = true;
                $dir = dirname($dir);
            }
        }

        // Sort deepest first
        $sortedDirs = array_keys($dirsToCheck);
        usort($sortedDirs, fn($a, $b) => strlen($b) - strlen($a));

        $removedDirs = [];
        foreach ($sortedDirs as $dir) {
            if (self::isCriticalProtectedPath($dir . '/')) {
                continue;
            }
            $dirAbs = rtrim($repoRoot, '/\\') . DIRECTORY_SEPARATOR . $dir;
            if (is_dir($dirAbs) && count(scandir($dirAbs)) === 2) { // Only . and ..
                @rmdir($dirAbs);
                $removedDirs[] = $dir;
            }
        }
        return $removedDirs;
    }

    /**
     * Build an epigenetic mark.
     */
    public static function buildEpigeneticMark(string $context, float $boost, string $reason): array
    {
        return [
            'context' => substr($context, 0, 100),
            'boost' => max(-0.5, min(0.5, $boost)),
            'reason' => substr($reason, 0, 200),
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Apply epigenetic marks to a gene based on outcome and environment.
     */
    public static function applyEpigeneticMarks(array $gene, array $envFingerprint, string $outcomeStatus): array
    {
        if (($gene['type'] ?? '') !== 'Gene') {
            return $gene;
        }

        if (!isset($gene['epigenetic_marks']) || !is_array($gene['epigenetic_marks'])) {
            $gene['epigenetic_marks'] = [];
        }

        $platform = $envFingerprint['platform'] ?? '';
        $arch = $envFingerprint['arch'] ?? '';
        $phpVersion = $envFingerprint['php_version'] ?? '';
        $envContext = implode('/', array_filter([$platform, $arch, $phpVersion])) ?: 'unknown';

        // Find existing mark for this context
        $existingIdx = null;
        foreach ($gene['epigenetic_marks'] as $i => $m) {
            if (($m['context'] ?? '') === $envContext) {
                $existingIdx = $i;
                break;
            }
        }

        if ($outcomeStatus === 'success') {
            if ($existingIdx !== null) {
                $gene['epigenetic_marks'][$existingIdx]['boost'] = min(0.5, ($gene['epigenetic_marks'][$existingIdx]['boost'] ?? 0) + 0.05);
                $gene['epigenetic_marks'][$existingIdx]['reason'] = 'reinforced_by_success';
                $gene['epigenetic_marks'][$existingIdx]['created_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            } else {
                $gene['epigenetic_marks'][] = self::buildEpigeneticMark($envContext, 0.1, 'success_in_environment');
            }
        } elseif ($outcomeStatus === 'failed') {
            if ($existingIdx !== null) {
                $gene['epigenetic_marks'][$existingIdx]['boost'] = max(-0.5, ($gene['epigenetic_marks'][$existingIdx]['boost'] ?? 0) - 0.1);
                $gene['epigenetic_marks'][$existingIdx]['reason'] = 'suppressed_by_failure';
                $gene['epigenetic_marks'][$existingIdx]['created_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            } else {
                $gene['epigenetic_marks'][] = self::buildEpigeneticMark($envContext, -0.1, 'failure_in_environment');
            }
        }

        // Decay old marks (keep max 10, remove marks older than 90 days)
        $cutoff = time() - 90 * 24 * 60 * 60;
        $gene['epigenetic_marks'] = array_values(array_filter(
            $gene['epigenetic_marks'],
            fn($m) => strtotime($m['created_at'] ?? 'now') > $cutoff
        ));
        $gene['epigenetic_marks'] = array_slice($gene['epigenetic_marks'], -10);

        return $gene;
    }

    /**
     * Get epigenetic boost for a gene in the current environment.
     */
    public static function getEpigeneticBoost(array $gene, array $envFingerprint): float
    {
        if (!isset($gene['epigenetic_marks']) || !is_array($gene['epigenetic_marks'])) {
            return 0.0;
        }

        $platform = $envFingerprint['platform'] ?? '';
        $arch = $envFingerprint['arch'] ?? '';
        $phpVersion = $envFingerprint['php_version'] ?? '';
        $envContext = implode('/', array_filter([$platform, $arch, $phpVersion])) ?: 'unknown';

        foreach ($gene['epigenetic_marks'] as $m) {
            if (($m['context'] ?? '') === $envContext) {
                return (float)($m['boost'] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * Build a success reason string.
     */
    public static function buildSuccessReason(array $params): string
    {
        $parts = [];
        $gene = $params['gene'] ?? null;
        $signals = $params['signals'] ?? [];
        $blast = $params['blast'] ?? null;
        $mutation = $params['mutation'] ?? null;
        $score = $params['score'] ?? null;

        if ($gene && isset($gene['id'])) {
            $category = $gene['category'] ?? 'unknown';
            $sigList = implode(', ', array_slice($signals, 0, 4));
            $parts[] = "Gene {$gene['id']} ({$category}) matched signals [{$sigList}].";
        }

        if ($mutation && isset($mutation['rationale'])) {
            $parts[] = 'Rationale: ' . substr($mutation['rationale'], 0, 200) . '.';
        }

        if ($blast) {
            $files = $blast['files'] ?? 0;
            $lines = $blast['lines'] ?? 0;
            $parts[] = "Scope: {$files} file(s), {$lines} line(s) changed.";
        }

        if (is_numeric($score)) {
            $parts[] = sprintf('Outcome score: %.2f.', (float)$score);
        }

        if ($gene && isset($gene['strategy']) && is_array($gene['strategy']) && !empty($gene['strategy'])) {
            $stratList = implode('; ', array_slice($gene['strategy'], 0, 3));
            $parts[] = 'Strategy applied: ' . substr($stratList, 0, 300) . '.';
        }

        $result = implode(' ', $parts);
        return substr($result, 0, 1000) ?: 'Evolution succeeded.';
    }

    /**
     * Check ethics violations in gene strategy.
     * @return array<string> Violations
     */
    public static function checkEthicsViolations(array $gene): array
    {
        $violations = [];
        $ethicsText = '';
        
        if (isset($gene['strategy'])) {
            $ethicsText .= (is_array($gene['strategy']) ? implode(' ', $gene['strategy']) : $gene['strategy']) . ' ';
        }
        if (isset($gene['description'])) {
            $ethicsText .= $gene['description'] . ' ';
        }
        if (isset($gene['summary'])) {
            $ethicsText .= $gene['summary'] . ' ';
        }

        if ($ethicsText === '') {
            return $violations;
        }

        $patterns = [
            ['re' => '/(?:bypass|disable|circumvent|remove)\s+(?:safety|guardrail|security|ethic|constraint|protection)/i', 'msg' => 'ethics: strategy attempts to bypass safety mechanisms'],
            ['re' => '/(?:keylogger|screen\s*capture|webcam\s*hijack|mic(?:rophone)?\s*record)/i', 'msg' => 'ethics: covert monitoring tool in strategy'],
            ['re' => '/(?:social\s+engineering|phishing)\s+(?:attack|template|script)/i', 'msg' => 'ethics: social engineering content in strategy'],
            ['re' => '/(?:exploit|hack)\s+(?:user|human|people|victim)/i', 'msg' => 'ethics: human exploitation in strategy'],
            ['re' => '/(?:hide|conceal|obfuscat)\w*\s+(?:action|behavior|intent|log)/i', 'msg' => 'ethics: strategy conceals actions from audit trail'],
        ];

        foreach ($patterns as $p) {
            if (preg_match($p['re'], $ethicsText)) {
                $violations[] = $p['msg'];
            }
        }

        return $violations;
    }

    /**
     * Solidify an evolution result.
     *
     * @param array{
     *   intent: string,
     *   summary: string,
     *   signals?: array,
     *   gene?: array,
     *   capsule?: array,
     *   event?: array,
     *   mutation?: array,
     *   personalityState?: array,
     *   blastRadius?: array,
     *   dryRun?: bool,
     *   context?: string,
     *   mutationsTried?: int,
     *   totalCycles?: int,
     * } $input
     */
    public function solidify(array $input): array
    {
        $intent = $input['intent'] ?? 'repair';
        $summary = $input['summary'] ?? '(no summary)';
        $signals = $input['signals'] ?? [];
        $gene = $input['gene'] ?? null;
        $capsule = $input['capsule'] ?? null;
        $event = $input['event'] ?? null;
        $mutation = $input['mutation'] ?? null;
        $personalityState = $input['personalityState'] ?? null;
        $blastRadius = $input['blastRadius'] ?? ['files' => 0, 'lines' => 0];
        $dryRun = (bool)($input['dryRun'] ?? false);
        $context = $input['context'] ?? '';
        $mutationsTried = (int)($input['mutationsTried'] ?? 1);
        $totalCycles = (int)($input['totalCycles'] ?? 1);

        $nowIso = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $timestamp = time();
        $randomSuffix = bin2hex(random_bytes(4));

        $violations = [];
        $warnings = [];

        // 检查blast radius hard limits
        $filesCount = (int)($blastRadius['files'] ?? 0);
        $linesCount = (int)($blastRadius['lines'] ?? 0);
        $changedFiles = $blastRadius['changed_files'] ?? $blastRadius['all_changed_files'] ?? [];

        // Classify blast severity
        $maxFiles = 25;
        if ($gene !== null) {
            $constraints = $gene['constraints'] ?? [];
            $maxFiles = (int)($constraints['max_files'] ?? 25);
        }
        $blastSeverity = self::classifyBlastSeverity($blastRadius, $maxFiles);

        // Handle blast severity
        if ($blastSeverity['severity'] === 'hard_cap_breach') {
            $violations[] = $blastSeverity['message'];
            error_log("[Solidify] " . $blastSeverity['message']);
        } elseif ($blastSeverity['severity'] === 'critical_overrun') {
            $violations[] = $blastSeverity['message'];
            $breakdown = self::analyzeBlastRadiusBreakdown($changedFiles);
            error_log("[Solidify] " . $blastSeverity['message']);
            error_log("[Solidify] Top contributing directories: " . implode(', ', array_map(fn($d) => "{$d['dir']} ({$d['files']})", $breakdown)));
        } elseif ($blastSeverity['severity'] === 'exceeded') {
            $violations[] = "max_files exceeded: {$filesCount} > {$maxFiles}";
        } elseif ($blastSeverity['severity'] === 'approaching_limit') {
            $warnings[] = $blastSeverity['message'];
        }

        // Check critical path protection
        $allowSelfModify = strtolower(getenv('EVOLVE_ALLOW_SELF_MODIFY') ?: '') === 'true';
        foreach ($changedFiles as $f) {
            if (self::isCriticalProtectedPath($f)) {
                $norm = self::normalizeRelPath($f);
                if ($allowSelfModify && str_starts_with($norm, 'skills/evolver/') && ($gene['category'] ?? '') === 'repair') {
                    $warnings[] = "self_modify_evolver_repair: {$norm} (EVOLVE_ALLOW_SELF_MODIFY=true)";
                } else {
                    $violations[] = "critical_path_modified: {$norm}";
                }
            }
        }

        // Check forbidden paths
        $forbidden = $gene['constraints']['forbidden_paths'] ?? [];
        foreach ($changedFiles as $f) {
            foreach ($forbidden as $fp) {
                $normFp = self::normalizeRelPath($fp);
                if ($normFp !== '' && (self::normalizeRelPath($f) === $normFp || str_starts_with(self::normalizeRelPath($f), $normFp . '/'))) {
                    $violations[] = "forbidden_path touched: {$f}";
                }
            }
        }

        // Check ethics violations
        if ($gene !== null) {
            $ethicsViolations = self::checkEthicsViolations($gene);
            foreach ($ethicsViolations as $v) {
                $violations[] = $v;
                error_log("[Solidify] Ethics violation: {$v}");
            }
        }

        // Detect destructive changes
        if ($this->repoRoot && !empty($changedFiles)) {
            $baselineUntracked = $input['baselineUntracked'] ?? [];
            $destructiveViolations = $this->detectDestructiveChanges($this->repoRoot, $changedFiles, $baselineUntracked);
            foreach ($destructiveViolations as $v) {
                $violations[] = $v;
            }
            if (!empty($destructiveViolations)) {
                error_log("[Solidify] CRITICAL: Destructive changes detected: " . implode('; ', $destructiveViolations));
            }
        }

        if (!empty($violations)) {
            return [
                'ok' => false,
                'violations' => $violations,
                'warnings' => $warnings,
                'blastSeverity' => $blastSeverity,
                'dryRun' => $dryRun,
            ];
        }

        // 验证 gene validation commands
        $validationResults = [];
        $validationOk = true;
        if ($gene !== null && !empty($gene['validation']) && !$dryRun) {
            foreach ($gene['validation'] as $cmd) {
                $validationResult = $this->runValidationCommand((string)$cmd);
                $validationResults[] = $validationResult;
                if (!$validationResult['ok']) {
                    $validationOk = false;
                    $warnings[] = "Validation failed: {$cmd} - " . $validationResult['err'];
                }
            }
        }

        // Run canary check
        $canaryResult = ['ok' => true, 'skipped' => true];
        if ($this->repoRoot && !$dryRun) {
            $canaryResult = $this->runCanaryCheck($this->repoRoot);
            if (!$canaryResult['ok'] && !$canaryResult['skipped']) {
                $violations[] = "canary_failed: index.php cannot load: " . ($canaryResult['err'] ?? '');
                error_log("[Solidify] CANARY FAILED: " . ($canaryResult['err'] ?? ''));
            }
        }

        // Determine overall success
        $success = empty($violations) && $validationOk && ($canaryResult['ok'] || $canaryResult['skipped']);

        // 构建event ID
        $parentEventId = $this->store->getLastEventId();
        $eventId = "evt_{$timestamp}_{$randomSuffix}";
        $mutationId = $mutation['id'] ?? "mut_{$timestamp}_{$randomSuffix}";
        $geneId = $gene['id'] ?? null;
        $capsuleId = $capsule['id'] ?? "capsule_{$timestamp}_{$randomSuffix}";

        // Capture environment fingerprint for the event record
        $envFingerprint = EnvFingerprint::capture();

        // Determine outcome status and score
        $outcomeStatus = $success ? 'success' : 'failed';
        $outcomeScore = $success ? 0.85 : 0.2;

        // Apply epigenetic marks to gene
        if (!$dryRun && $gene !== null && ($gene['type'] ?? '') === 'Gene') {
            $gene = self::applyEpigeneticMarks($gene, $envFingerprint, $outcomeStatus);
        }

        // 计算 success streak if we have a gene
        $successStreak = 0;
        if ($geneId !== null) {
            $successStreak = $this->store->computeSuccessStreak($geneId, $signals);
        }

        // 构建evolution event with GEP 1.6.0 fields
        $evolutionEvent = array_merge($event ?? [], [
            'type' => 'EvolutionEvent',
            'schema_version' => ContentHash::SCHEMA_VERSION,
            'id' => $eventId,
            'asset_id' => null, // Will be computed below
            'parent' => $parentEventId,
            'intent' => $intent,
            'signals' => $signals,
            'genes_used' => $geneId ? [$geneId] : [],
            'mutation_id' => $mutationId,
            'personality_state' => $personalityState ?? [
                'rigor' => 0.8, 
                'creativity' => 0.3, 
                'verbosity' => 0.5, 
                'risk_tolerance' => 0.2, 
                'obedience' => 0.9
            ],
            'blast_radius' => $blastRadius,
            'outcome' => [
                'status' => $outcomeStatus,
                'score' => $outcomeScore,
            ],
            'env_fingerprint' => $envFingerprint,
            'mutations_tried' => $mutationsTried,
            'total_cycles' => $totalCycles,
            'created_at' => $nowIso,
            'summary' => $summary,
        ]);

        // 计算一个sset_id for the event
        $evolutionEvent['asset_id'] = ContentHash::computeAssetId($evolutionEvent);

        // 构建gene update with GEP 1.6.0 fields
        $geneToStore = null;
        if ($gene !== null) {
            $geneToStore = array_merge($gene, [
                'type' => 'Gene',
                'schema_version' => ContentHash::SCHEMA_VERSION,
                'asset_id' => ContentHash::computeAssetId($gene),
                'updated_at' => $nowIso,
            ]);
        }

        // 构建capsule (on success) with GEP 1.6.0 fields
        $capsuleToStore = null;
        if ($capsule !== null || (empty($warnings) && $intent !== 'repair')) {
            $capsuleData = $capsule ?? [];
            $capsuleOutcome = $capsuleData['outcome'] ?? [
                'status' => empty($warnings) ? 'success' : 'partial',
                'score' => empty($warnings) ? 0.8 : 0.5,
            ];
            
            $capsuleToStore = array_merge($capsuleData, [
                'type' => 'Capsule',
                'schema_version' => ContentHash::SCHEMA_VERSION,
                'id' => $capsuleId,
                'asset_id' => null, // Will be computed below
                'trigger' => $signals,
                'gene' => $geneId,
                'summary' => $summary,
                'confidence' => empty($warnings) ? 0.8 : 0.5,
                'blast_radius' => $blastRadius,
                'outcome' => $capsuleOutcome,
                'env_fingerprint' => $envFingerprint,
                'success_streak' => $successStreak,
                'created_at' => $nowIso,
            ]);

            // 添加content if provided or extract from context
            if (!empty($context)) {
                $capsuleToStore['content'] = $context;
            }

            // 计算一个sset_id for the capsule
            $capsuleToStore['asset_id'] = ContentHash::computeAssetId($capsuleToStore);
        }

        if (!$dryRun) {
            // Store event
            $this->store->appendEvent($evolutionEvent);

            // 更新gene
            if ($geneToStore !== null) {
                $this->store->upsertGene($geneToStore);
            }

            // Store capsule
            if ($capsuleToStore !== null) {
                $this->store->appendCapsule($capsuleToStore);
                
                // Mark for network sync
                $this->store->updateSyncStatus(
                    'capsule',
                    $capsuleToStore['id'],
                    $capsuleToStore['asset_id'],
                    'pending'
                );
            }

            // Mark gene for network sync
            if ($geneToStore !== null) {
                $this->store->updateSyncStatus(
                    'gene',
                    $geneToStore['id'],
                    $geneToStore['asset_id'],
                    'pending'
                );
            }

            // Rollback on failure
            if (!$success && ($input['rollbackOnFailure'] ?? true) && $this->repoRoot) {
                $this->rollbackTracked($this->repoRoot);
                $baselineUntracked = $input['baselineUntracked'] ?? [];
                if (!empty($baselineUntracked)) {
                    $this->rollbackNewUntrackedFiles($this->repoRoot, $baselineUntracked);
                }
            }
        }

        return [
            'ok' => $success,
            'eventId' => $eventId,
            'geneId' => $geneId,
            'capsuleId' => $capsuleToStore ? $capsuleToStore['id'] : null,
            'event' => $evolutionEvent,
            'gene' => $geneToStore,
            'capsule' => $capsuleToStore,
            'violations' => $violations,
            'warnings' => $warnings,
            'blastSeverity' => $blastSeverity,
            'canary' => $canaryResult,
            'validationResults' => $validationResults,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * Record a failed evolution attempt.
     */
    public function recordFailure(array $input): void
    {
        $gene = $input['gene'] ?? null;
        $signals = $input['signals'] ?? [];
        $failureReason = $input['failureReason'] ?? 'unknown';
        $diffSnapshot = $input['diffSnapshot'] ?? null;

        $timestamp = time();
        $randomSuffix = bin2hex(random_bytes(4));

        $failedCapsule = [
            'id' => "failed_{$timestamp}_{$randomSuffix}",
            'gene' => $gene['id'] ?? null,
            'trigger' => $signals,
            'failure_reason' => $failureReason,
            'diff_snapshot' => $diffSnapshot,
        ];

        $this->store->appendFailedCapsule($failedCapsule);
    }

    /**
     * 检查 a validation command is safe to run.
     */
    public function isValidationCommandAllowed(string $cmd): bool
    {
        $cmd = trim($cmd);

        // 检查prefix whitelist
        $allowed = false;
        foreach (self::ALLOWED_COMMAND_PREFIXES as $prefix) {
            if (str_starts_with($cmd, $prefix . ' ') || $cmd === $prefix) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return false;
        }

        // No backtick substitution
        if (str_contains($cmd, '`')) {
            return false;
        }

        // No $() substitution
        if (str_contains($cmd, '$(')) {
            return false;
        }

        // Strip quoted content and check for shell operators
        $stripped = preg_replace('/"[^"]*"|\'[^\']*\'/', '', $cmd);
        foreach (self::FORBIDDEN_SHELL_OPERATORS as $op) {
            if (str_contains($stripped, $op)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 运行a validation command safely.
     */
    private function runValidationCommand(string $cmd): array
    {
        if (!$this->isValidationCommandAllowed($cmd)) {
            return ['ok' => false, 'out' => '', 'err' => 'Command not allowed by safety policy'];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, getcwd() ?: '/tmp');
        if (!is_resource($process)) {
            return ['ok' => false, 'out' => '', 'err' => 'Failed to start process'];
        }

        fclose($pipes[0]);

        // 设置non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = time() + self::VALIDATION_TIMEOUT;

        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false) $stdout .= $chunk;
            $chunk = fread($pipes[2], 4096);
            if ($chunk !== false) $stderr .= $chunk;
            usleep(100000); // 100ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok' => $exitCode === 0,
            'out' => $stdout,
            'err' => $stderr,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Parse GEP objects from LLM output text.
     * Extracts JSON objects from raw text output.
     */
    public function parseGepObjects(string $text): array
    {
        $objects = [];
        $depth = 0;
        $start = null;

        for ($i = 0; $i < strlen($text); $i++) {
            $char = $text[$i];
            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $fragment = substr($text, $start, $i - $start + 1);
                    $parsed = json_decode($fragment, true);
                    if (is_array($parsed) && isset($parsed['type'])) {
                        $objects[] = $parsed;
                    }
                    $start = null;
                }
            }
        }

        return $objects;
    }

    /**
     * 计算 GDI (Genome Distribution Index) score for a capsule.
     * Higher score = better quality asset.
     */
    public function computeGdiScore(array $capsule): float
    {
        $score = 0.0;
        
        // Base score from outcome
        $outcome评分= (float)($capsule['outcome']['score'] ?? 0.5);
        $score += $outcome评分* 0.4;
        
        // Confidence factor
        $confidence = (float)($capsule['confidence'] ?? 0.5);
        $score += $confidence * 0.3;
        
        // Success streak bonus
        $streak = (int)($capsule['success_streak'] ?? 0);
        $score += min($streak * 0.05, 0.15); // Max 0.15 from streak
        
        // Small blast radius bonus (precision)
        $files = (int)($capsule['blast_radius']['files'] ?? 1);
        $lines = (int)($capsule['blast_radius']['lines'] ?? 1);
        if ($files <= 5 && $lines <= 100) {
            $score += 0.1; // Precision bonus
        }
        
        // Has content bonus
        if (!empty($capsule['content'])) {
            $score += 0.05;
        }
        
        return min($score, 1.0);
    }
}
