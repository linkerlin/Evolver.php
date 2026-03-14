<?php
/**
 * evolver-personality-report - Analyze personality state statistics
 *
 * Usage:
 *   php scripts/evolver-personality-report.php
 *
 * Ported from evolver/scripts/gep_personality_report.js
 */

declare(strict_types=1);

use Evolver\Paths;
use Evolver\Personality;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Read JSON file if exists, return fallback.
 */
function readJsonIfExists(string $path, $fallback)
{
    if (!file_exists($path)) {
        return $fallback;
    }
    $raw = file_get_contents($path);
    if (empty(trim($raw))) {
        return $fallback;
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $fallback;
    }
    return $decoded;
}

/**
 * Read JSONL file, optionally limited to last N lines.
 */
function readJsonlIfExists(string $path, int $limitLines = 5000): array
{
    if (!file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $recent = array_slice($lines, max(0, count($lines) - $limitLines));
    $result = [];

    foreach ($recent as $line) {
        $obj = json_decode(trim($line), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($obj)) {
            $result[] = $obj;
        }
    }

    return $result;
}

/**
 * Clamp value to 0-1 range.
 */
function clamp01($x): float
{
    $n = (float)$x;
    if (!is_finite($n)) {
        return 0.0;
    }
    return max(0.0, min(1.0, $n));
}

/**
 * Format as percentage.
 */
function pct($x): string
{
    $n = (float)$x;
    if (!is_finite($n)) {
        return '0.0%';
    }
    return sprintf('%.1f%%', $n * 100);
}

/**
 * Pad string to fixed width.
 */
function pad($s, int $n): string
{
    $str = (string)($s ?? '');
    if (strlen($str) >= $n) {
        return substr($str, 0, $n);
    }
    return $str . str_repeat(' ', $n - strlen($str));
}

/**
 * Compute score from success/fail counts and average score.
 */
function scoreFromCounts(int $success, int $fail, ?float $avgScore): float
{
    $total = $success + $fail;
    $p = ($success + 1) / ($total + 2); // Laplace smoothing
    $sampleWeight = min(1.0, $total / 8.0);
    $q = $avgScore === null ? 0.5 : clamp01($avgScore);
    return $p * 0.75 + $q * 0.25 * $sampleWeight;
}

/**
 * Aggregate statistics from events.
 */
function aggregateFromEvents(array $events): array
{
    $map = [];

    foreach ($events as $ev) {
        if (!is_array($ev) || ($ev['type'] ?? '') !== 'EvolutionEvent') {
            continue;
        }

        $ps = $ev['personality_state'] ?? null;
        if (!is_array($ps)) {
            continue;
        }

        $normalized = Personality::normalizePersonalityState($ps);
        $key = Personality::personalityKey($normalized);

        if (!isset($map[$key])) {
            $map[$key] = [
                'key' => $key,
                'success' => 0,
                'fail' => 0,
                'n' => 0,
                'avg_score' => 0.5,
                'last_event_id' => null,
                'last_at' => null,
                'mutation' => ['repair' => 0, 'optimize' => 0, 'innovate' => 0],
                'mutation_success' => ['repair' => 0, 'optimize' => 0, 'innovate' => 0],
            ];
        }

        $cur = &$map[$key];

        $status = $ev['outcome']['status'] ?? 'unknown';
        if ($status === 'success') {
            $cur['success']++;
        } elseif ($status === 'failed') {
            $cur['fail']++;
        }

        $score = $ev['outcome']['score'] ?? null;
        if (is_numeric($score)) {
            $sc = clamp01((float)$score);
            $cur['n']++;
            $cur['avg_score'] = $cur['avg_score'] + ($sc - $cur['avg_score']) / $cur['n'];
        }

        $cat = $ev['intent'] ?? null;
        if (is_string($cat) && isset($cur['mutation'][$cat])) {
            $cur['mutation'][$cat]++;
            if ($status === 'success') {
                $cur['mutation_success'][$cat]++;
            }
        }

        $cur['last_event_id'] = $ev['id'] ?? $cur['last_event_id'];
        $cur['last_at'] = $ev['meta']['at'] ?? $cur['last_at'];
    }

    return array_values($map);
}

// Main
$repoRoot = Paths::getRepoRoot();
$memoryDir = Paths::getMemoryDir();
$assetsDir = Paths::getGepAssetsDir();

$personalityPath = $memoryDir . '/personality_state.json';
$model = readJsonIfExists($personalityPath, null);
$current = ($model && isset($model['current']))
    ? Personality::normalizePersonalityState($model['current'])
    : Personality::defaultPersonalityState();
$currentKey = Personality::personalityKey($current);

$eventsPath = $assetsDir . '/events.jsonl';
$events = readJsonlIfExists($eventsPath, 10000);
$evs = array_filter($events, fn($e) => ($e['type'] ?? '') === 'EvolutionEvent');
$agg = aggregateFromEvents($evs);

// Prefer model.stats if present
$stats = ($model && isset($model['stats']) && is_array($model['stats'])) ? $model['stats'] : [];
$statRows = [];

foreach ($stats as $key => $e) {
    $entry = is_array($e) ? $e : [];
    $success = (int)($entry['success'] ?? 0);
    $fail = (int)($entry['fail'] ?? 0);
    $total = $success + $fail;
    $avg = isset($entry['avg_score']) && is_numeric($entry['avg_score'])
        ? clamp01((float)$entry['avg_score'])
        : null;
    $score = scoreFromCounts($success, $fail, $avg);

    $statRows[] = [
        'key' => $key,
        'success' => $success,
        'fail' => $fail,
        'total' => $total,
        'avg_score' => $avg,
        'score' => $score,
        'updated_at' => $entry['updated_at'] ?? null,
        'source' => 'model',
    ];
}

$evRows = [];
foreach ($agg as $e) {
    $success = (int)$e['success'];
    $fail = (int)$e['fail'];
    $total = $success + $fail;
    $avg = is_numeric($e['avg_score'] ?? null) ? clamp01((float)$e['avg_score']) : null;
    $score = scoreFromCounts($success, $fail, $avg);

    $evRows[] = [
        'key' => $e['key'],
        'success' => $success,
        'fail' => $fail,
        'total' => $total,
        'avg_score' => $avg,
        'score' => $score,
        'updated_at' => $e['last_at'] ?? null,
        'source' => 'events',
        '_ev' => $e,
    ];
}

// Merge rows by key (events take precedence)
$byKey = [];
foreach (array_merge($statRows, $evRows) as $r) {
    $key = $r['key'];
    if (!isset($byKey[$key])) {
        $byKey[$key] = $r;
        continue;
    }
    // Prefer events for counts
    if ($r['source'] === 'events') {
        $byKey[$key] = array_merge($byKey[$key], $r);
    } else {
        $byKey[$key] = array_merge($r, $byKey[$key]);
    }
}

// Sort by score descending
usort($byKey, fn($a, $b) => $b['score'] <=> $a['score']);

// Output
echo "Repo: {$repoRoot}\n";
echo "MemoryDir: {$memoryDir}\n";
echo "AssetsDir: {$assetsDir}\n\n";

echo "[Current Personality]\n";
echo "{$currentKey}\n";
echo json_encode($current, JSON_PRETTY_PRINT) . "\n\n";

echo "[Personality Stats] (ranked by score)\n";

if (empty($byKey)) {
    echo "(no stats yet; run a few cycles and solidify)\n";
    exit(0);
}

$header = pad('rank', 5) . pad('total', 8) . pad('succ', 8) . pad('fail', 8) .
          pad('succ_rate', 11) . pad('avg', 7) . pad('score', 8) . 'key';
echo $header . "\n";
echo str_repeat('-', min(140, strlen($header) + 40)) . "\n";

$topN = min(25, count($byKey));
for ($i = 0; $i < $topN; $i++) {
    $r = $byKey[$i];
    $succ = (int)$r['success'];
    $fail = (int)$r['fail'];
    $total = (int)$r['total'];
    $succRate = $total > 0 ? $succ / $total : 0;
    $avg = $r['avg_score'] === null ? '-' : sprintf('%.2f', $r['avg_score']);

    $line = pad((string)($i + 1), 5) .
            pad((string)$total, 8) .
            pad((string)$succ, 8) .
            pad((string)$fail, 8) .
            pad(pct($succRate), 11) .
            pad($avg, 7) .
            pad(sprintf('%.3f', $r['score']), 8) .
            $r['key'];

    echo $line . "\n";

    if (isset($r['_ev'])) {
        $ev = $r['_ev'];
        $ms = $ev['mutation'] ?? [];
        $mSucc = $ev['mutation_success'] ?? [];
        $parts = [];

        foreach (['repair', 'optimize', 'innovate'] as $cat) {
            $n = (int)($ms[$cat] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $s = (int)($mSucc[$cat] ?? 0);
            $parts[] = "{$cat}:{$s}/{$n}";
        }

        if (!empty($parts)) {
            echo "       mutation_success: " . implode(' | ', $parts) . "\n";
        }
    }
}

echo "\n";
echo "[Notes]\n";
echo "- score is a smoothed composite of success_rate + avg_score (sample-weighted)\n";
echo "- current_key appears in the ranking once enough data accumulates\n";
