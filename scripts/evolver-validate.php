#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * evolver-validate - Validate Evolver.php installation and database health
 *
 * Usage:
 *   php scripts/evolver-validate.php
 *   php scripts/evolver-validate.php --fix
 *   php scripts/evolver-validate.php --verbose
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
use Evolver\EnvFingerprint;

$fix = in_array('--fix', $argv);
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

echo "🧬 Evolver.php Validation Tool\n";
echo "==============================\n\n";

$errors = [];
$warnings = [];
$checks = [];

// Check 1: PHP Version
$phpVersion = PHP_VERSION;
if (version_compare($phpVersion, '8.3.0', '>=')) {
    $checks[] = "✅ PHP version: {$phpVersion}";
} else {
    $errors[] = "❌ PHP version {$phpVersion} (requires 8.3+)";
}

// Check 2: SQLite3 Extension
if (extension_loaded('sqlite3')) {
    $checks[] = "✅ SQLite3 extension loaded";
} else {
    $errors[] = "❌ SQLite3 extension not loaded";
}

// Check 3: JSON Extension
if (extension_loaded('json')) {
    $checks[] = "✅ JSON extension loaded";
} else {
    $errors[] = "❌ JSON extension not loaded";
}

// Check 4: Database
$dbPath = getenv('EVOLVER_DB_PATH') ?: ($_SERVER['HOME'] ?? '/tmp') . '/.evolver/evolver.db';
$checks[] = "📍 Database path: {$dbPath}";

try {
    $db = new Database($dbPath);
    $checks[] = "✅ Database connection successful";

    // Check database health
    $health = $db->getHealthStatus();
    $integrityOk = ($health['integrity_check'] ?? '') === 'ok';
    if ($integrityOk) {
        $checks[] = "✅ Database health: OK";
        if ($verbose) {
            $checks[] = "   Schema version: " . ($health['schema_version'] ?? 'unknown');
            $checks[] = "   Size: " . round(($health['size_bytes'] ?? 0) / 1024 / 1024, 2) . " MB";
        }
    } else {
        $warnings[] = "⚠️  Database integrity: " . ($health['integrity_check'] ?? 'unknown');
    }

    // Check 5: Asset Store
    $store = new GepAssetStore($db);
    $genes = $store->loadGenes();
    $capsules = $store->loadCapsules(1000);

    $checks[] = "✅ Genes loaded: " . count($genes);
    $checks[] = "✅ Capsules loaded: " . count($capsules);

    if (count($genes) < 3) {
        $warnings[] = "⚠️  Few genes loaded (expected 3+)";
    }

    // Check 6: Default genes exist
    $defaultGeneIds = ['gene_gep_repair_from_errors', 'gene_gep_optimize_performance', 'gene_gep_innovate_features'];
    foreach ($defaultGeneIds as $geneId) {
        $gene = $store->getGene($geneId);
        if ($gene) {
            if ($verbose) {
                $checks[] = "✅ Default gene exists: {$geneId}";
            }
        } else {
            $warnings[] = "⚠️  Missing default gene: {$geneId}";
        }
    }

} catch (Exception $e) {
    $errors[] = "❌ Database error: " . $e->getMessage();
}

// Check 7: Environment
$envFingerprint = EnvFingerprint::getDeviceId();
if ($verbose) {
    $checks[] = "📍 Device ID: " . substr($envFingerprint, 0, 16) . "...";
}

// Check 8: Workspace directory
$workspace = getenv('WORKSPACE_DIR') ?: getcwd();
$evoDir = $workspace . '/.evolution';
if (is_dir($evoDir)) {
    $checks[] = "✅ Evolution directory exists";
} else {
    $warnings[] = "⚠️  Evolution directory not found: {$evoDir}";
    if ($fix) {
        if (mkdir($evoDir, 0755, true)) {
            $checks[] = "✅ Created evolution directory";
        }
    }
}

// Check 9: Write permissions
$dbDir = dirname($dbPath);
if (is_writable($dbDir)) {
    $checks[] = "✅ Database directory writable";
} else {
    $errors[] = "❌ Database directory not writable: {$dbDir}";
}

// Print results
foreach ($checks as $check) {
    echo $check . "\n";
}

if (!empty($warnings)) {
    echo "\n⚠️  Warnings:\n";
    foreach ($warnings as $warning) {
        echo "   {$warning}\n";
    }
}

if (!empty($errors)) {
    echo "\n❌ Errors:\n";
    foreach ($errors as $error) {
        echo "   {$error}\n";
    }
    echo "\n❌ Validation FAILED\n";
    exit(1);
}

echo "\n✅ Validation PASSED\n";
exit(0);
