<?php
/**
 * evolver-gep-append-event - Append EvolutionEvent(s) to events.jsonl
 *
 * Usage:
 *   php scripts/evolver-gep-append-event.php [input_file]
 *   cat events.json | php scripts/evolver-gep-append-event.php
 *
 * Accepts:
 *   - Single JSON object
 *   - JSON array of objects
 *   - JSONL (one JSON per line)
 *
 * Ported from evolver/scripts/gep_append_event.js
 */

declare(strict_types=1);

use Evolver\Database;
use Evolver\GepAssetStore;
use Evolver\Paths;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Read input from stdin or file.
 */
function readInput(?string $path): string
{
    if ($path && file_exists($path)) {
        return file_get_contents($path);
    }

    // Read from stdin
    $stdin = fopen('php://stdin', 'r');
    if (!$stdin) {
        return '';
    }
    stream_set_blocking($stdin, false);
    $content = stream_get_contents($stdin);
    fclose($stdin);

    return $content ?: '';
}

/**
 * Parse input text into array of objects.
 */
function parseInput(string $text): array
{
    $raw = trim($text);
    if ($raw === '') {
        return [];
    }

    // Try JSON array or single object
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded) && isset($decoded[0])) {
            return $decoded; // JSON array
        }
        if (is_array($decoded)) {
            return [$decoded]; // Single object
        }
    }

    // Fallback: JSONL
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    $out = [];
    foreach ($lines as $line) {
        $obj = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($obj)) {
            $out[] = $obj;
        }
    }

    return $out;
}

/**
 * Validate EvolutionEvent structure (GEP 1.4+).
 */
function isValidEvolutionEvent(array $ev): bool
{
    if (($ev['type'] ?? '') !== 'EvolutionEvent') {
        return false;
    }

    if (!is_string($ev['id'] ?? null)) {
        return false;
    }

    // parent may be null or string
    $parent = $ev['parent'] ?? null;
    if (!($parent === null || is_string($parent))) {
        return false;
    }

    if (!is_string($ev['intent'] ?? null)) {
        return false;
    }

    if (!is_array($ev['signals'] ?? null)) {
        return false;
    }

    if (!is_array($ev['genes_used'] ?? null)) {
        return false;
    }

    // GEP v1.4: mutation + personality are mandatory
    if (!is_string($ev['mutation_id'] ?? null)) {
        return false;
    }

    $ps = $ev['personality_state'] ?? null;
    if (!is_array($ps) || ($ps['type'] ?? '') !== 'PersonalityState') {
        return false;
    }

    foreach (['rigor', 'creativity', 'verbosity', 'risk_tolerance', 'obedience'] as $k) {
        $v = $ps[$k] ?? null;
        if (!is_numeric($v) || $v < 0 || $v > 1) {
            return false;
        }
    }

    $br = $ev['blast_radius'] ?? null;
    if (!is_array($br) || !is_numeric($br['files'] ?? null) || !is_numeric($br['lines'] ?? null)) {
        return false;
    }

    $outcome = $ev['outcome'] ?? null;
    if (!is_array($outcome) || !is_string($outcome['status'] ?? null)) {
        return false;
    }

    $score = $outcome['score'] ?? null;
    if (!is_numeric($score) || $score < 0 || $score > 1) {
        return false;
    }

    // capsule_id is optional, but if present must be string or null
    if (array_key_exists('capsule_id', $ev)) {
        $capsuleId = $ev['capsule_id'];
        if (!($capsuleId === null || is_string($capsuleId))) {
            return false;
        }
    }

    return true;
}

/**
 * Append event to events.jsonl using GepAssetStore.
 */
function appendEventJsonl(array $event): void
{
    $assetsDir = Paths::getGepAssetsDir();
    $dbPath = $assetsDir . '/assets.db';

    // Ensure directory exists
    if (!is_dir($assetsDir)) {
        mkdir($assetsDir, 0755, true);
    }

    $db = new Database($dbPath);
    $store = new GepAssetStore($db);
    $store->appendEvent($event);
}

// Main
$args = array_values(array_filter($argv, fn($a) => !str_starts_with($a, '--')));
$inputPath = $args[1] ?? null;

$text = readInput($inputPath);
$items = parseInput($text);

$appended = 0;
foreach ($items as $item) {
    if (!isValidEvolutionEvent($item)) {
        continue;
    }
    try {
        appendEventJsonl($item);
        $appended++;
    } catch (Throwable $e) {
        fwrite(STDERR, "Error appending event: " . $e->getMessage() . "\n");
    }
}

echo "appended={$appended}\n";
