#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * evolver-a2a-export - Export genes and capsules for A2A Hub sharing
 *
 * Usage:
 *   php scripts/evolver-a2a-export.php
 *   php scripts/evolver-a2a-export.php --genes
 *   php scripts/evolver-a2a-export.php --capsules
 *   php scripts/evolver-a2a-export.php --output export.json
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
use Evolver\DeviceId;

// Parse arguments
$exportGenes = false;
$exportCapsules = false;
$outputPath = null;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--genes':
            $exportGenes = true;
            break;
        case '--capsules':
            $exportCapsules = true;
            break;
        case '--output':
        case '-o':
            if (isset($argv[$i + 1])) {
                $outputPath = $argv[++$i];
            }
            break;
    }
}

// Default: export both
if (!$exportGenes && !$exportCapsules) {
    $exportGenes = true;
    $exportCapsules = true;
}

// Initialize
$dbPath = getenv('EVOLVER_DB_PATH') ?: ($_SERVER['HOME'] ?? '/tmp') . '/.evolver/evolver.db';
$db = new Database($dbPath);
$store = new GepAssetStore($db);
$protocol = new GepA2AProtocol();

$deviceId = DeviceId::getDeviceId();
$export = [
    'export_version' => '1.0',
    'exported_at' => date('c'),
    'device_id' => $deviceId,
    'assets' => [],
];

// Export genes
if ($exportGenes) {
    $genes = $store->loadGenes();
    foreach ($genes as $gene) {
        $envelope = $protocol->buildMessage('publish', [
            'asset_type' => 'Gene',
            'asset' => $gene,
        ], $deviceId);
        $export['assets'][] = $envelope;
    }
    echo "Exported " . count($genes) . " genes\n";
}

// Export capsules
if ($exportCapsules) {
    $capsules = $store->loadCapsules(1000);
    foreach ($capsules as $capsule) {
        $envelope = $protocol->buildMessage('publish', [
            'asset_type' => 'Capsule',
            'asset' => $capsule,
        ], $deviceId);
        $export['assets'][] = $envelope;
    }
    echo "Exported " . count($capsules) . " capsules\n";
}

// Output
$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($outputPath) {
    file_put_contents($outputPath, $json);
    echo "Export saved to: {$outputPath}\n";
} else {
    echo $json;
}

echo "\nTotal assets exported: " . count($export['assets']) . "\n";
exit(0);
