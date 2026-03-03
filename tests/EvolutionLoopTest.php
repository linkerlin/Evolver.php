<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\EvolutionLoop;
use Evolver\Database;
use PHPUnit\Framework\TestCase;

final class EvolutionLoopTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
    }

    public function testConstructorSetsInterval(): void
    {
        $loop = new EvolutionLoop($this->db, 30);

        $stats = $loop->getStats();

        $this->assertEquals(30, $stats['interval_seconds']);
    }

    public function testDefaultInterval(): void
    {
        $loop = new EvolutionLoop($this->db);

        $stats = $loop->getStats();

        $this->assertEquals(60, $stats['interval_seconds']);
    }

    public function testIsRunningInitiallyFalse(): void
    {
        $loop = new EvolutionLoop($this->db);

        $this->assertFalse($loop->isRunning());
    }

    public function testGetStatsReturnsExpectedKeys(): void
    {
        $loop = new EvolutionLoop($this->db);
        $stats = $loop->getStats();

        $this->assertArrayHasKey('running', $stats);
        $this->assertArrayHasKey('cycles_completed', $stats);
        $this->assertArrayHasKey('cycles_failed', $stats);
        $this->assertArrayHasKey('interval_seconds', $stats);
        $this->assertArrayHasKey('uptime_seconds', $stats);
    }

    public function testStopSetsFlag(): void
    {
        $loop = new EvolutionLoop($this->db);
        $loop->stop();

        // After stop, running should be false (we can't easily test the actual loop)
        $this->assertFalse($loop->isRunning());
    }

    public function testInitialCyclesCompletedIsZero(): void
    {
        $loop = new EvolutionLoop($this->db);
        $stats = $loop->getStats();

        $this->assertEquals(0, $stats['cycles_completed']);
        $this->assertEquals(0, $stats['cycles_failed']);
    }

    public function testInitialUptimeIsZero(): void
    {
        $loop = new EvolutionLoop($this->db);
        $stats = $loop->getStats();

        $this->assertEquals(0, $stats['uptime_seconds']);
    }
}
