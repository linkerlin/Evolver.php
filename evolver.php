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
 *   php evolver.php --ops <command>    # Run ops commands
 *   php evolver.php --review           # Start in review mode (require human approval)
 *   php evolver.php --loop [interval]  # Start in continuous loop mode (seconds between cycles)
 *   php evolver.php --validate-gep     # Validate GEP protocol output
 *
 * Ops commands:
 *   cleanup    - Clean old logs, temp files
 *   health     - Check system health
 *   stats      - Display statistics
 *   gc         - Garbage collect old data
 *   dedupe     - Show signal deduplication stats
 *   help       - Show ops help
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
use Evolver\EvolutionLoop;
use Evolver\Ops\OpsManager;

// Parse CLI arguments
$args = array_slice($argv ?? [], 1);
$dbPath = null;
$validate = false;
$opsCommand = null;
$opsOpts = [];
$reviewMode = false;
$loopMode = false;
$loopInterval = 60;
$validateGep = false;

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--db' && isset($args[$i + 1])) {
        $dbPath = $args[++$i];
    } elseif ($args[$i] === '--validate') {
        $validate = true;
    } elseif ($args[$i] === '--validate-gep') {
        $validateGep = true;
    } elseif ($args[$i] === '--review') {
        $reviewMode = true;
    } elseif ($args[$i] === '--loop') {
        $loopMode = true;
        // Check for optional interval parameter
        if (isset($args[$i + 1]) && is_numeric($args[$i + 1])) {
            $loopInterval = (int)$args[++$i];
        }
    } elseif ($args[$i] === '--ops' && isset($args[$i + 1])) {
        $opsCommand = $args[++$i];
        // Collect remaining args as options
        while ($i + 1 < count($args) && !str_starts_with($args[$i + 1], '--')) {
            $opt = $args[++$i];
            if (str_contains($opt, '=')) {
                [$key, $value] = explode('=', $opt, 2);
                $opsOpts[$key] = $value;
            } else {
                $opsOpts[$opt] = true;
            }
        }
        // Handle --key=value format
        while ($i + 1 < count($args) && str_starts_with($args[$i + 1], '--')) {
            $opt = ltrim($args[++$i], '-');
            if (str_contains($opt, '=')) {
                [$key, $value] = explode('=', $opt, 2);
                $opsOpts[$key] = $value;
            } else {
                $opsOpts[$opt] = true;
            }
        }
    }
}

// Determine database path
// Priority: CLI arg > env var > default (~/.evolver/evolver.db)
if ($dbPath === null) {
    $envPath = getenv('EVOLVER_DB_PATH');
    if ($envPath !== false && $envPath !== '') {
        $dbPath = $envPath;
    } else {
        // Default: ~/.evolver/evolver.db
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home !== false && $home !== '') {
            $dbPath = $home . '/.evolver/evolver.db';
        } else {
            // Fallback to current directory if HOME not available
            $dbPath = __DIR__ . '/data/evolver.db';
        }
    }
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
        
        // Get database health status
        $health = $db->getHealthStatus();

        echo "✅ Evolver.php installation valid\n";
        echo "   PHP version: " . PHP_VERSION . "\n";
        echo "   Database: {$dbPath}\n";
        echo "   Database size: " . number_format($health['size_bytes'] / 1024, 2) . " KB\n";
        echo "   Schema version: {$health['schema_version']}\n";
        echo "   Integrity check: {$health['integrity_check']}\n";
        echo "   Genes loaded: " . count($genes) . "\n";
        echo "   Stats: " . json_encode($stats) . "\n";
        
        // Show migrations if any
        if (!empty($health['migrations'])) {
            echo "   Migrations:\n";
            foreach ($health['migrations'] as $log) {
                echo "      - {$log}\n";
            }
        }
        
        exit(0);
    } catch (\Throwable $e) {
        echo "❌ Validation failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($validateGep) {
    // GEP validation mode: read from stdin and validate
    echo "Enter GEP output (5 JSON objects, Ctrl+D to finish):\n";
    $input = '';
    while (!feof(STDIN)) {
        $input .= fgets(STDIN);
    }

    if (empty(trim($input))) {
        echo "❌ No input provided\n";
        exit(1);
    }

    try {
        $validator = new \Evolver\GepValidator();
        $result = $validator->validateGepOutput($input);
        echo "\n" . $validator->getSummary($result) . "\n";
        exit($result['valid'] ? 0 : 1);
    } catch (\Throwable $e) {
        echo "❌ Validation error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($opsCommand !== null) {
    // Ops mode: run运维 commands
    try {
        $opsManager = new OpsManager(dirname($dbPath), null);
        $result = $opsManager->run($opsCommand, $opsOpts);
        echo OpsManager::formatOutput($result) . "\n";
        exit($result['ok'] ? 0 : 1);
    } catch (\Throwable $e) {
        echo "❌ Ops failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Start MCP server or loop mode
try {
    $db = new Database($dbPath);

    if ($loopMode) {
        // Start evolution loop mode
        $loop = new EvolutionLoop($db, $loopInterval);
        echo "Starting evolution loop (interval: {$loopInterval}s)...\n";
        echo "Press Ctrl+C to stop.\n";
        $loop->run();
    } else {
        // Start standard MCP server
        $server = new McpServer($db, $reviewMode);
        $server->run();
    }
} catch (\Throwable $e) {
    error_log('[Evolver.php] Fatal error: ' . $e->getMessage());
    exit(1);
}
