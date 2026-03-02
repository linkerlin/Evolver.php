<?php

declare(strict_types=1);

namespace Evolver\Ops;

use Evolver\Paths;

/**
 * Skills Monitor - Checks installed skills for issues, auto-heals simple problems.
 *
 * Ported from evolver/src/ops/skills_monitor.js
 */
final class SkillsMonitor
{
    private static array $defaultIgnoreList = [
        'common',
        'clawhub',
        'input-validator',
        'proactive-agent',
        'security-audit',
    ];

    private static ?array $ignoreList = null;

    /**
     * Get the ignore list (default + user-defined).
     */
    private static function getIgnoreList(): array
    {
        if (self::$ignoreList !== null) {
            return self::$ignoreList;
        }

        $ignoreList = self::$defaultIgnoreList;

        // Load user-defined ignore list
        try {
            $ignoreFile = Paths::getWorkspaceRoot() . '/.skill_monitor_ignore';
            if (file_exists($ignoreFile)) {
                $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $t = trim($line);
                    if (!empty($t) && !str_starts_with($t, '#')) {
                        $ignoreList[] = $t;
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore errors
        }

        self::$ignoreList = array_unique($ignoreList);
        return self::$ignoreList;
    }

    /**
     * Check a single skill for issues.
     */
    public static function checkSkill(string $skillName): ?array
    {
        $skillsDir = Paths::getSkillsDir();
        if (in_array($skillName, self::getIgnoreList(), true)) {
            return null;
        }

        $skillPath = $skillsDir . '/' . $skillName;
        $issues = [];

        if (!is_dir($skillPath)) {
            return null;
        }

        $mainFile = 'index.php';
        $pkgPath = $skillPath . '/composer.json';
        $hasPkg = false;

        if (file_exists($pkgPath)) {
            $hasPkg = true;
            try {
                $pkg = json_decode(file_get_contents($pkgPath), true, 512, JSON_THROW_ON_ERROR);
                if (isset($pkg['main'])) {
                    $mainFile = $pkg['main'];
                }
                if (!empty($pkg['require']) && count($pkg['require']) > 0) {
                    if (!is_dir($skillPath . '/vendor')) {
                        $issues[] = 'Missing vendor directory (needs composer install)';
                    } else {
                        try {
                            $vendorContents = scandir($skillPath . '/vendor');
                            if ($vendorContents === false || count($vendorContents) <= 2) {
                                $issues[] = 'Empty vendor directory (needs composer install)';
                            }
                        } catch (\Throwable) {
                            $issues[] = 'Invalid vendor directory';
                        }
                    }
                }
            } catch (\JsonException) {
                $issues[] = 'Invalid composer.json';
            }
        }

        if ($hasPkg && !file_exists($skillPath . '/SKILL.md')) {
            $issues[] = 'Missing SKILL.md';
        }

        // Check main file exists
        $entryPoint = $skillPath . '/' . $mainFile;
        if (!file_exists($entryPoint)) {
            $issues[] = "Missing entry point: {$mainFile}";
        }

        return count($issues) > 0 ? ['name' => $skillName, 'issues' => $issues] : null;
    }

    /**
     * Auto-heal a skill's issues.
     */
    public static function autoHeal(string $skillName, array $issues): array
    {
        $skillsDir = Paths::getSkillsDir();
        $skillPath = $skillsDir . '/' . $skillName;
        $healed = [];

        foreach ($issues as $issue) {
            if (str_contains($issue, 'Missing vendor directory') || str_contains($issue, 'Empty vendor directory')) {
                try {
                    // Remove composer.lock if it exists
                    $lockFile = $skillPath . '/composer.lock';
                    if (file_exists($lockFile)) {
                        unlink($lockFile);
                    }

                    // Run composer install
                    $cwd = getcwd();
                    chdir($skillPath);
                    exec('composer install --no-dev --no-interaction 2>&1', $output, $returnCode);
                    chdir($cwd);

                    if ($returnCode === 0) {
                        $healed[] = $issue;
                        echo "[SkillsMonitor] Auto-healed {$skillName}: composer install\n";
                    } else {
                        echo "[SkillsMonitor] Failed to heal {$skillName}: composer install failed\n";
                    }
                } catch (\Throwable $e) {
                    echo "[SkillsMonitor] Failed to heal {$skillName}: {$e->getMessage()}\n";
                }
            } elseif ($issue === 'Missing SKILL.md') {
                try {
                    $name = str_replace('-', ' ', $skillName);
                    $content = "# {$skillName}\n\n{$name} skill.\n";
                    file_put_contents($skillPath . '/SKILL.md', $content);
                    $healed[] = $issue;
                    echo "[SkillsMonitor] Auto-healed {$skillName}: created SKILL.md stub\n";
                } catch (\Throwable) {
                    // Ignore
                }
            }
        }

        return $healed;
    }

    /**
     * Run skills monitor and return report.
     */
    public static function run(array $options = []): array
    {
        $heal = $options['autoHeal'] ?? true;
        $skillsDir = Paths::getSkillsDir();
        $report = [];

        try {
            $skills = scandir($skillsDir);
            if ($skills === false) {
                return [];
            }

            foreach ($skills as $skill) {
                if (str_starts_with($skill, '.')) {
                    continue;
                }

                $result = self::checkSkill($skill);
                if ($result !== null) {
                    if ($heal) {
                        $healed = self::autoHeal($result['name'], $result['issues']);
                        $result['issues'] = array_filter(
                            $result['issues'],
                            fn($issue) => !in_array($issue, $healed, true)
                        );
                        if (count($result['issues']) === 0) {
                            continue;
                        }
                    }
                    $report[] = $result;
                }
            }
        } catch (\Throwable) {
            // Return empty report on error
        }

        return $report;
    }
}
