<?php
/**
 * evolver-human-report - Generate human-readable evolution summary
 *
 * Usage:
 *   php scripts/evolver-human-report.php [input_file] [output_file]
 *
 * Defaults:
 *   input_file: memory/evolution_history_full.md
 *   output_file: memory/evolution_human_summary.md
 *
 * Ported from evolver/scripts/human_report.js
 */

declare(strict_types=1);

use Evolver\Paths;

require_once __DIR__ . '/../vendor/autoload.php';

function generateHumanReport(string $inFile, string $outFile): void
{
    if (!file_exists($inFile)) {
        fwrite(STDERR, "No input file: {$inFile}\n");
        exit(1);
    }

    $content = file_get_contents($inFile);
    $entries = array_filter(array_map('trim', explode('---', $content)));

    $categories = [
        'Security & Stability' => [],
        'Performance & Optimization' => [],
        'Tooling & Features' => [],
        'Documentation & Process' => [],
    ];

    $componentMap = [];

    foreach ($entries as $entry) {
        $lines = explode("\n", $entry);
        $header = $lines[0] ?? '';
        $body = implode("\n", array_slice($lines, 1));

        // Extract date/time from header like "### Title (2024-01-15 14:30:00)"
        $dateStr = '';
        if (preg_match('/\((.*?)\)/', $header, $m)) {
            $dateStr = $m[1];
        }
        $time = '';
        if ($dateStr) {
            $parts = explode(' ', $dateStr);
            $time = $parts[1] ?? '';
        }

        // Detect component
        $lowerBody = strtolower($body);
        $component = 'System';

        if (str_contains($lowerBody, 'feishu-card')) $component = 'feishu-card';
        elseif (str_contains($lowerBody, 'feishu-sticker')) $component = 'feishu-sticker';
        elseif (str_contains($lowerBody, 'git-sync')) $component = 'git-sync';
        elseif (str_contains($lowerBody, 'capability-evolver') || str_contains($lowerBody, 'evolve.js')) $component = 'capability-evolver';
        elseif (str_contains($lowerBody, 'interaction-logger')) $component = 'interaction-logger';
        elseif (str_contains($lowerBody, 'chat-to-image')) $component = 'chat-to-image';
        elseif (str_contains($lowerBody, 'safe_publish')) $component = 'capability-evolver';

        // Detect category
        $category = 'Tooling & Features';

        if (str_contains($lowerBody, 'security') || str_contains($lowerBody, 'permission') ||
            str_contains($lowerBody, 'auth') || str_contains($lowerBody, 'harden')) {
            $category = 'Security & Stability';
        } elseif (str_contains($lowerBody, 'optimiz') || str_contains($lowerBody, 'performance') ||
                   str_contains($lowerBody, 'memory') || str_contains($lowerBody, 'fast')) {
            $category = 'Performance & Optimization';
        } elseif (str_contains($lowerBody, 'doc') || str_contains($lowerBody, 'readme')) {
            $category = 'Documentation & Process';
        }

        // Extract human summary - first meaningful line
        $summaryLines = array_filter($lines, fn($l) =>
            !str_starts_with($l, '###') &&
            !str_starts_with($l, 'Status:') &&
            !str_starts_with($l, 'Action:') &&
            strlen(trim($l)) > 10
        );

        if (empty($summaryLines)) {
            continue;
        }

        $summaryLine = reset($summaryLines);
        $summary = $summaryLine;
        $summary = preg_replace('/^-\s*/', '', $summary); // Remove bullets
        $summary = preg_replace('/\*\*/', '', $summary);  // Remove bold
        $summary = preg_replace('/`/', '', $summary);      // Remove backticks
        $summary = trim($summary);

        // Deduplicate
        $key = "{$component}:" . substr($summary, 0, 20);
        $exists = !empty(array_filter($categories[$category], fn($i) => $i['key'] === $key));

        if ($exists && !str_contains($summary, "Stability Scan OK") && !str_contains($summary, "Workspace Sync")) {
            $categories[$category][] = [
                'time' => $time,
                'component' => $component,
                'summary' => $summary,
                'key' => $key,
            ];

            if (!isset($componentMap[$component])) {
                $componentMap[$component] = [];
            }
            $componentMap[$component][] = $summary;
        }
    }

    // Generate Markdown
    $today = date('Y-m-d');
    $md = "# Evolution Summary: The Day in Review ({$today})\n\n";
    $md .= "> Overview: Grouped summary of changes extracted from evolution history.\n\n";

    // Section 1: By Theme
    $md .= "## 1. Evolution Direction\n";

    foreach ($categories as $cat => $items) {
        if (empty($items)) {
            continue;
        }
        $md .= "### {$cat}\n";

        // Group by component within theme
        $compGroup = [];
        foreach ($items as $i) {
            if (!isset($compGroup[$i['component']])) {
                $compGroup[$i['component']] = [];
            }
            $compGroup[$i['component']][] = $i['summary'];
        }

        foreach ($compGroup as $comp => $sums) {
            $uniqueSums = array_unique($sums);
            foreach ($uniqueSums as $s) {
                $md .= "- **{$comp}**: {$s}\n";
            }
        }
        $md .= "\n";
    }

    // Section 2: Timeline of Critical Events
    $md .= "## 2. Timeline of Critical Events\n";

    $allItems = [];
    foreach ($categories as $items) {
        foreach ($items as $i) {
            $allItems[] = $i;
        }
    }
    usort($allItems, fn($a, $b) => strcmp($a['time'], $b['time']));

    // Filter for "Critical" keywords
    $criticalItems = array_filter($allItems, fn($i) =>
        str_contains(strtolower($i['summary']), 'fix') ||
        str_contains(strtolower($i['summary']), 'patch') ||
        str_contains(strtolower($i['summary']), 'create') ||
        str_contains(strtolower($i['summary']), 'optimiz')
    );

    foreach ($criticalItems as $i) {
        $md .= "- `{$i['time']}` ({$i['component']}): {$i['summary']}\n";
    }

    // Section 3: Package Adjustments
    $md .= "\n## 3. Package & Documentation Adjustments\n";
    $comps = array_keys($componentMap);
    sort($comps);

    foreach ($comps as $comp) {
        $count = count(array_unique($componentMap[$comp]));
        $md .= "- **{$comp}**: Received {$count} significant updates.\n";
    }

    // Ensure directory exists
    $dir = dirname($outFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($outFile, $md);
    echo "Human report generated: {$outFile}\n";
}

// Main
$memoryDir = Paths::getMemoryDir();
$inFile = $argv[1] ?? "{$memoryDir}/evolution_history_full.md";
$outFile = $argv[2] ?? "{$memoryDir}/evolution_human_summary.md";

generateHumanReport($inFile, $outFile);
