<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Innovation Catalyst - Analyzes system state to propose concrete innovation ideas.
 * PHP port of innovation.js from EvoMap/evolver.
 */
final class Innovation
{
    /**
     * Get the skills directory path.
     */
    private static function getSkillsDir(): string
    {
        $workspace = getenv('WORKSPACE_DIR') ?: getcwd();
        return $workspace . '/skills';
    }

    /**
     * List existing skills in the skills directory.
     *
     * @return string[]
     */
    private static function listSkills(): array
    {
        try {
            $dir = self::getSkillsDir();
            if (!is_dir($dir)) {
                return [];
            }
            $entries = scandir($dir);
            if ($entries === false) {
                return [];
            }
            return array_values(array_filter($entries, fn($f) => !str_starts_with($f, '.')));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generate innovation ideas based on current skill landscape.
     * Analyzes under-represented categories and suggests concrete new skills.
     *
     * @return string[] Array of innovation idea strings
     */
    public static function generateInnovationIdeas(): array
    {
        $skills = self::listSkills();

        // Categorize existing skills
        $categories = [
            'feishu' => 0,
            'dev' => 0,
            'media' => 0,
            'security' => 0,
            'automation' => 0,
            'data' => 0,
        ];

        foreach ($skills as $skill) {
            if (str_starts_with($skill, 'feishu-')) {
                $categories['feishu']++;
            }
            if (str_starts_with($skill, 'git-') || str_starts_with($skill, 'code-')
                || str_contains($skill, 'lint') || str_contains($skill, 'test')) {
                $categories['dev']++;
            }
            if (str_contains($skill, 'image') || str_contains($skill, 'video')
                || str_contains($skill, 'music') || str_contains($skill, 'voice')) {
                $categories['media']++;
            }
            if (str_contains($skill, 'security') || str_contains($skill, 'audit')
                || str_contains($skill, 'guard')) {
                $categories['security']++;
            }
            if (str_contains($skill, 'auto-') || str_contains($skill, 'scheduler')
                || str_contains($skill, 'cron')) {
                $categories['automation']++;
            }
            if (str_contains($skill, 'db') || str_contains($skill, 'store')
                || str_contains($skill, 'cache') || str_contains($skill, 'index')) {
                $categories['data']++;
            }
        }

        // Find under-represented categories
        asort($categories);
        $weakAreas = array_slice(array_keys($categories), 0, 2);

        $ideas = [];

        // Idea 1: Fill the gap
        if (in_array('security', $weakAreas, true)) {
            $ideas[] = "- Security: Implement a 'dependency-scanner' skill to check for vulnerable packages.";
            $ideas[] = "- Security: Create a 'permission-auditor' to review tool usage patterns.";
        }
        if (in_array('media', $weakAreas, true)) {
            $ideas[] = "- Media: Add a 'meme-generator' skill for social engagement.";
            $ideas[] = "- Media: Create a 'video-summarizer' using ffmpeg keyframes.";
        }
        if (in_array('dev', $weakAreas, true)) {
            $ideas[] = "- Dev: Build a 'code-stats' skill to visualize repo complexity.";
            $ideas[] = "- Dev: Implement a 'todo-manager' that syncs code TODOs to tasks.";
        }
        if (in_array('automation', $weakAreas, true)) {
            $ideas[] = "- Automation: Create a 'meeting-prep' skill that auto-summarizes calendar context.";
            $ideas[] = "- Automation: Build a 'broken-link-checker' for documentation.";
        }
        if (in_array('data', $weakAreas, true)) {
            $ideas[] = "- Data: Implement a 'local-vector-store' for semantic search.";
            $ideas[] = "- Data: Create a 'log-analyzer' to visualize system health trends.";
        }

        // Idea 2: Optimization
        if (count($skills) > 50) {
            $ideas[] = "- Optimization: Identify and deprecate unused skills (e.g., redundant search tools).";
            $ideas[] = "- Optimization: Merge similar skills (e.g., 'git-sync' and 'git-doctor').";
        }

        // Idea 3: Meta
        $ideas[] = "- Meta: Enhance the Evolver's self-reflection by adding a 'performance-metric' dashboard.";

        // Return top 3 ideas
        return array_slice($ideas, 0, 3);
    }
}
