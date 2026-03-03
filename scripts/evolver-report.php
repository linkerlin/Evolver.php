#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * evolver-report - Generate human-readable evolution reports
 *
 * Usage:
 *   php scripts/evolver-report.php
 *   php scripts/evolver-report.php --events 20
 *   php scripts/evolver-report.php --genes
 *   php scripts/evolver-report.php --capsules
 *   php scripts/evolver-report.php --stats
 *   php scripts/evolver-report.php --json
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

// Parse arguments
$showEvents = false;
$showGenes = false;
$showCapsules = false;
$showStats = false;
$outputJson = false;
$eventLimit = 10;

for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--events':
            $showEvents = true;
            if (isset($argv[$i + 1]) && is_numeric($argv[$i + 1])) {
                $eventLimit = (int)$argv[++$i];
            }
            break;
        case '--genes':
            $showGenes = true;
            break;
        case '--capsules':
            $showCapsules = true;
            break;
        case '--stats':
            $showStats = true;
            break;
        case '--json':
            $outputJson = true;
            break;
        case '--all':
            $showEvents = true;
            $showGenes = true;
            $showCapsules = true;
            $showStats = true;
            break;
    }
}

// Default: show summary if no specific flag
if (!$showEvents && !$showGenes && !$showCapsules && !$showStats) {
    $showStats = true;
    $showEvents = true;
}

// Initialize
$dbPath = getenv('EVOLVER_DB_PATH') ?: ($_SERVER['HOME'] ?? '/tmp') . '/.evolver/evolver.db';
$db = new Database($dbPath);
$store = new GepAssetStore($db);

$report = [
    'generated_at' => date('c'),
    'database_path' => $dbPath,
];

// Statistics
if ($showStats) {
    $genes = $store->loadGenes();
    $capsules = $store->loadCapsules(1000);
    $events = $store->loadRecentEvents(1000);

    // Count by category
    $genesByCategory = [];
    foreach ($genes as $gene) {
        $cat = $gene['category'] ?? 'unknown';
        $genesByCategory[$cat] = ($genesByCategory[$cat] ?? 0) + 1;
    }

    // Count by intent
    $eventsByIntent = [];
    foreach ($events as $event) {
        $intent = $event['intent'] ?? 'unknown';
        $eventsByIntent[$intent] = ($eventsByIntent[$intent] ?? 0) + 1;
    }

    $report['stats'] = [
        'total_genes' => count($genes),
        'total_capsules' => count($capsules),
        'total_events' => count($events),
        'genes_by_category' => $genesByCategory,
        'events_by_intent' => $eventsByIntent,
    ];
}

// Recent events
if ($showEvents) {
    $events = $store->loadRecentEvents($eventLimit);
    $report['recent_events'] = array_map(function ($event) {
        return [
            'id' => $event['id'] ?? null,
            'intent' => $event['intent'] ?? 'unknown',
            'summary' => $event['summary'] ?? '',
            'gene_id' => $event['gene_id'] ?? null,
            'capsule_id' => $event['capsule_id'] ?? null,
            'created_at' => $event['created_at'] ?? '',
        ];
    }, $events);
}

// Genes
if ($showGenes) {
    $genes = $store->loadGenes();
    $report['genes'] = array_map(function ($gene) {
        return [
            'id' => $gene['id'] ?? null,
            'category' => $gene['category'] ?? 'unknown',
            'signals_match' => $gene['signals_match'] ?? [],
        ];
    }, $genes);
}

// Capsules
if ($showCapsules) {
    $capsules = $store->loadCapsules(1000);
    $report['capsules'] = array_map(function ($capsule) {
        return [
            'id' => $capsule['id'] ?? null,
            'gene_id' => $capsule['gene_id'] ?? null,
            'confidence' => $capsule['confidence'] ?? 0.5,
            'outcome_status' => $capsule['outcome_status'] ?? 'unknown',
            'created_at' => $capsule['created_at'] ?? '',
        ];
    }, $capsules);
}

// Output
if ($outputJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "🧬 Evolver.php Report\n";
    echo "=====================\n";
    echo "Generated: {$report['generated_at']}\n\n";

    if (isset($report['stats'])) {
        $stats = $report['stats'];
        echo "📊 Statistics\n";
        echo "-------------\n";
        echo "Total Genes: {$stats['total_genes']}\n";
        echo "Total Capsules: {$stats['total_capsules']}\n";
        echo "Total Events: {$stats['total_events']}\n";

        if (!empty($stats['genes_by_category'])) {
            echo "\nGenes by Category:\n";
            foreach ($stats['genes_by_category'] as $cat => $count) {
                echo "  - {$cat}: {$count}\n";
            }
        }

        if (!empty($stats['events_by_intent'])) {
            echo "\nEvents by Intent:\n";
            foreach ($stats['events_by_intent'] as $intent => $count) {
                echo "  - {$intent}: {$count}\n";
            }
        }
        echo "\n";
    }

    if (isset($report['recent_events']) && !empty($report['recent_events'])) {
        echo "📜 Recent Events (last {$eventLimit})\n";
        echo "-----------------------------------\n";
        foreach ($report['recent_events'] as $event) {
            $time = $event['created_at'] ?? 'unknown';
            $intent = $event['intent'] ?? 'unknown';
            $summary = $event['summary'] ?? '';
            echo "[{$time}] {$intent}: {$summary}\n";
        }
        echo "\n";
    }

    if (isset($report['genes']) && !empty($report['genes'])) {
        echo "🧬 Genes\n";
        echo "--------\n";
        foreach ($report['genes'] as $gene) {
            $id = $gene['id'] ?? 'unknown';
            $cat = $gene['category'] ?? 'unknown';
            $signals = implode(', ', $gene['signals_match'] ?? []);
            echo "- {$id} ({$cat})\n";
            if ($signals) {
                echo "  Signals: {$signals}\n";
            }
        }
        echo "\n";
    }

    if (isset($report['capsules']) && !empty($report['capsules'])) {
        echo "💊 Capsules\n";
        echo "-----------\n";
        foreach ($report['capsules'] as $capsule) {
            $id = $capsule['id'] ?? 'unknown';
            $geneId = $capsule['gene_id'] ?? 'unknown';
            $confidence = $capsule['confidence'] ?? 0;
            $status = $capsule['outcome_status'] ?? 'unknown';
            echo "- {$id}\n";
            echo "  Gene: {$geneId}, Confidence: {$confidence}, Status: {$status}\n";
        }
        echo "\n";
    }
}

exit(0);
