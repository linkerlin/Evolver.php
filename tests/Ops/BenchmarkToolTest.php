<?php

declare(strict_types=1);

namespace Evolver\Tests\Ops;

use Evolver\Ops\BenchmarkTool;
use Evolver\Database;
use PHPUnit\Framework\TestCase;

/**
 * BenchmarkTool tests.
 */
final class BenchmarkToolTest extends TestCase
{
    private BenchmarkTool $benchmark;

    protected function setUp(): void
    {
        $this->benchmark = new BenchmarkTool(10);
    }

    public function testRunAll(): void
    {
        $results = $this->benchmark->runAll();

        $this->assertIsArray($results);
    }

    public function testRunAllWithDatabase(): void
    {
        $db = new Database(':memory:');
        $results = $this->benchmark->runAll($db);

        $this->assertIsArray($results);
    }

    public function testBenchmarkJsonSerialization(): void
    {
        $result = $this->benchmark->benchmarkJsonSerialization();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('iterations', $result);
        $this->assertArrayHasKey('total_ms', $result);
    }

    public function testBenchmarkSignalExtraction(): void
    {
        $result = $this->benchmark->benchmarkSignalExtraction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('iterations', $result);
    }

    public function testBenchmarkContentHash(): void
    {
        $result = $this->benchmark->benchmarkContentHash();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('iterations', $result);
    }

    public function testGetResults(): void
    {
        $this->benchmark->runAll();
        $results = $this->benchmark->getResults();

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('benchmarks', $results);
    }
}
