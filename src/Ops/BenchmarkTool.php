<?php

declare(strict_types=1);

namespace Evolver\Ops;

/**
 * Benchmark Tool - performance testing for Evolver operations.
 *
 * Benchmarks:
 * - Database operations (CRUD)
 * - Signal extraction
 * - Gene selection
 * - GDI calculation
 * - JSON serialization
 */
final class BenchmarkTool
{
    private int $iterations = 100;
    private array $results = [];

    public function __construct(int $iterations = 100)
    {
        $this->iterations = $iterations;
    }

    /**
     * 运行all benchmarks.
     */
    public function runAll(?\Evolver\Database $db = null): array
    {
        $results = [
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'iterations' => $this->iterations,
            'benchmarks' => [],
        ];

        $results['benchmarks']['json_serialization'] = $this->benchmarkJsonSerialization();
        $results['benchmarks']['signal_extraction'] = $this->benchmarkSignalExtraction();
        $results['benchmarks']['content_hash'] = $this->benchmarkContentHash();
        $results['benchmarks']['gdi_calculation'] = $this->benchmarkGdiCalculation();

        if ($db !== null) {
            $results['benchmarks']['database_capsule_query'] = $this->benchmarkDatabaseCapsuleQuery($db);
            $results['benchmarks']['database_gene_query'] = $this->benchmarkDatabaseGeneQuery($db);
        }

        $results['summary'] = $this->generateSummary($results['benchmarks']);

        $this->results = $results;
        return $results;
    }

    /**
     * Benchmark JSON serialization.
     */
    public function benchmarkJsonSerialization(): array
    {
        $data = $this->generateTestData();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'JSON Serialization',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * Benchmark signal extraction.
     */
    public function benchmarkSignalExtraction(): array
    {
        $extractor = new \Evolver\SignalExtractor();
        $context = $this->generateTestContext();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $extractor->extract($context);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'Signal Extraction',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * Benchmark content hash.
     */
    public function benchmarkContentHash(): array
    {
        $data = $this->generateTestData();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            \Evolver\ContentHash::computeAssetId($data);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'Content Hash (SHA-256)',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * Benchmark GDI calculation.
     */
    public function benchmarkGdiCalculation(): array
    {
        $calculator = new \Evolver\GdiCalculator();
        $capsule = $this->generateTestCapsule();

        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $calculator->computeCapsuleGdi($capsule);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'GDI Calculation',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * Benchmark database capsule query.
     */
    public function benchmarkDatabaseCapsuleQuery(\Evolver\Database $db): array
    {
        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $db->fetchAll('SELECT * FROM capsules ORDER BY created_at DESC LIMIT 10', []);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'Database Capsule Query',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * Benchmark database gene query.
     */
    public function benchmarkDatabaseGeneQuery(\Evolver\Database $db): array
    {
        $start = hrtime(true);
        for ($i = 0; $i < $this->iterations; $i++) {
            $db->fetchAll('SELECT * FROM genes ORDER BY updated_at DESC', []);
        }
        $end = hrtime(true);

        $durationMs = ($end - $start) / 1_000_000;

        return [
            'name' => 'Database Gene Query',
            'iterations' => $this->iterations,
            'total_ms' => round($durationMs, 2),
            'avg_ms' => round($durationMs / $this->iterations, 4),
            'ops_per_sec' => round($this->iterations / ($durationMs / 1000)),
        ];
    }

    /**
     * 获取last results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * G生成 summary.
     */
    private function generateSummary(array $benchmarks): array
    {
        $fastest = null;
        $slowest = null;
        $totalTime = 0;

        foreach ($benchmarks as $name => $result) {
            $avgMs = $result['avg_ms'] ?? 0;
            $totalTime += $result['total_ms'] ?? 0;

            if ($fastest === null || $avgMs < $fastest['avg_ms']) {
                $fastest = ['name' => $name, 'avg_ms' => $avgMs];
            }
            if ($slowest === null || $avgMs > $slowest['avg_ms']) {
                $slowest = ['name' => $name, 'avg_ms' => $avgMs];
            }
        }

        return [
            'total_time_ms' => round($totalTime, 2),
            'fastest' => $fastest,
            'slowest' => $slowest,
        ];
    }

    /**
     * G生成 test data.
     */
    private function generateTestData(): array
    {
        return [
            'type' => 'Gene',
            'id' => 'gene_test_' . time(),
            'category' => 'repair',
            'signals_match' => ['error', 'bug', 'fix'],
            'prompt_template' => 'Fix the following error: {context}',
            'constraints' => [
                'max_files' => 10,
                'forbidden_paths' => ['/src/core/'],
            ],
            'schema_version' => '1.6.0',
        ];
    }

    /**
     * G生成 test context.
     */
    private function generateTestContext(): string
    {
        return <<<'CONTEXT'
[ERROR] TypeError: Cannot read property 'undefined' of null
at Module._compile (internal/modules/cjs/loader.js:1180:45)
at Object.<anonymous> (/app/index.js:45:12)
[INFO] Application started on port 3000
[WARN] Memory usage above 80%
CONTEXT;
    }

    /**
     * G生成 test capsule.
     */
    private function generateTestCapsule(): array
    {
        return [
            'type' => 'Capsule',
            'id' => 'capsule_test_' . time(),
            'trigger' => ['error', 'null_pointer'],
            'gene' => 'gene_repair_v1',
            'summary' => 'Fixed null pointer by adding null check',
            'confidence' => 0.85,
            'blast_radius' => ['files' => 1, 'lines' => 5],
            'outcome' => ['status' => 'success', 'score' => 0.9],
            'success_streak' => 3,
            'content' => 'if (obj === null) return;',
        ];
    }
}
