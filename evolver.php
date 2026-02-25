#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 🧬 Evolver.php - Capability Evolver MCP stdio server
 *
 * A PHP 8.3+ implementation of the Evolver capability evolution engine,
 * exposed as an MCP (Model Context Protocol) stdio server.
 *
 * Usage:
 *   php evolver.php                    # Start MCP stdio server
 *   php evolver.php --validate         # Validate installation
 *   php evolver.php --db /path/to.db   # Use custom database path
 *
 * MCP clients connect via stdio (JSON-RPC 2.0).
 */

// Ensure errors don't corrupt JSON-RPC output
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Only log, never output to stdout
    error_log("[Evolver.php] PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    return true;
});

// Autoload
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
];
$autoloaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    // Manual class loading fallback
    spl_autoload_register(function (string $class): void {
        $prefix = 'Evolver\\';
        $baseDir = __DIR__ . '/src/';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use Evolver\Database;
use Evolver\McpServer;

// Parse CLI arguments
$args = array_slice($argv ?? [], 1);
$dbPath = null;
$validate = false;

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--db' && isset($args[$i + 1])) {
        $dbPath = $args[++$i];
    } elseif ($args[$i] === '--validate') {
        $validate = true;
    }
}

// Determine database path
if ($dbPath === null) {
    $dbPath = getenv('EVOLVER_DB_PATH') ?: (__DIR__ . '/data/evolver.db');
}

// Ensure data directory exists
$dbDir = dirname($dbPath);
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

if ($validate) {
    // Validation mode: test that everything works
    try {
        $db = new Database($dbPath);
        $store = new \Evolver\GepAssetStore($db);
        $genes = $store->loadGenes();
        $stats = $store->getStats();

        echo "✅ Evolver.php installation valid\n";
        echo "   PHP version: " . PHP_VERSION . "\n";
        echo "   Database: {$dbPath}\n";
        echo "   Genes loaded: " . count($genes) . "\n";
        echo "   Stats: " . json_encode($stats) . "\n";
        exit(0);
    } catch (\Throwable $e) {
        echo "❌ Validation failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Start MCP server
try {
    $db = new Database($dbPath);
    $server = new McpServer($db);
    $server->run();
} catch (\Throwable $e) {
    error_log('[Evolver.php] Fatal error: ' . $e->getMessage());
    exit(1);
}
