#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * evolver-a2a-ingest - Ingest genes and capsules from A2A export files
 *
 * Usage:
 *   php scripts/evolver-a2a-ingest.php --file export.json
 *   php scripts/evolver-a2a-ingest.php --stdin
 *   php scripts/evolver-a2a-ingest.php --file export.json --dry-run
 */

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use Evolver\Database;
use Evolver\GepAssetStore;
use Evolver\GepA2AProtocol;

// Parse arguments
$inputFile = null;
$useStdin = false;
$dryRun = false;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--file':
        case '-f':
            if (isset($argv[$i + 1])) {
                $inputFile = $argv[++$i];
            }
            break;
        case '--stdin':
            $useStdin = true;
            break;
        case '--dry-run':
            $dryRun = true;
            break;
    }
}

// Get input data
if ($useStdin) {
    $json = file_get_contents('php://stdin');
} elseif ($inputFile && file_exists($inputFile)) {
    $json = file_get_contents($inputFile);
} else {
    echo "Error: No input specified. Use --file <path> or --stdin\n";
    exit(1);
}

$data = json_decode($json, true);
if (!$data) {
    echo "Error: Invalid JSON input\n";
    exit(1);
}

// Initialize
$dbPath = getenv('EVOLVER_DB_PATH') ?: ($_SERVER['HOME'] ?? '/tmp') . '/.evolver/evolver.db';
$db = new Database($dbPath);
$store = new GepAssetStore($db);
$protocol = new GepA2AProtocol();

$stats = [
    'genes_ingested' => 0,
    'capsules_ingested' => 0,
    'genes_skipped' => 0,
    'capsules_skipped' => 0,
    'errors' => [],
];

// Process assets
$assets = $data['assets'] ?? [];

foreach ($assets as $envelope) {
    try {
        // Validate envelope structure
        if (!is_array($envelope) || !isset($envelope['payload'])) {
            $stats['errors'][] = "Invalid envelope structure";
            continue;
        }

        $payload = $envelope['payload'] ?? [];
        $assetType = $payload['asset_type'] ?? 'unknown';
        $asset = $payload['asset'] ?? [];

        if (empty($asset) || !isset($asset['id'])) {
            $stats['errors'][] = "Missing asset or asset ID";
            continue;
        }

        if ($dryRun) {
            echo "[DRY-RUN] Would ingest {$assetType}: {$asset['id']}\n";
            continue;
        }

        switch ($assetType) {
            case 'Gene':
                // Check if gene already exists
                $existing = $store->getGene($asset['id']);
                if ($existing) {
                    $stats['genes_skipped']++;
                    echo "Skipped existing gene: {$asset['id']}\n";
                } else {
                    $store->upsertGene($asset);
                    $stats['genes_ingested']++;
                    echo "Ingested gene: {$asset['id']}\n";
                }
                break;

            case 'Capsule':
                // Check if capsule already exists
                $existing = $store->getCapsule($asset['id']);
                if ($existing) {
                    $stats['capsules_skipped']++;
                    echo "Skipped existing capsule: {$asset['id']}\n";
                } else {
                    $store->upsertCapsule($asset);
                    $stats['capsules_ingested']++;
                    echo "Ingested capsule: {$asset['id']}\n";
                }
                break;

            default:
                $stats['errors'][] = "Unknown asset type: {$assetType}";
        }
    } catch (Exception $e) {
        $stats['errors'][] = $e->getMessage();
    }
}

// Summary
echo "\n=== Ingest Summary ===\n";
echo "Genes ingested: {$stats['genes_ingested']}\n";
echo "Capsules ingested: {$stats['capsules_ingested']}\n";
echo "Genes skipped: {$stats['genes_skipped']}\n";
echo "Capsules skipped: {$stats['capsules_skipped']}\n";

if (!empty($stats['errors'])) {
    echo "\nErrors:\n";
    foreach ($stats['errors'] as $error) {
        echo "  - {$error}\n";
    }
}

if ($dryRun) {
    echo "\n[DRY-RUN] No changes were made\n";
}

exit(empty($stats['errors']) ? 0 : 1);
