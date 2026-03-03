<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Database;
use PHPUnit\Framework\TestCase;

/**
 * Database tests - extracted from EvolverTest.php
 */
final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
    }

    public function testDatabaseCreatesSchema(): void
    {
        $result = $this->db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        $tables = array_column($result, 'name');
        $this->assertContains('genes', $tables);
        $this->assertContains('capsules', $tables);
        $this->assertContains('events', $tables);
        $this->assertContains('failed_capsules', $tables);
    }

    public function testDatabasePragmasSet(): void
    {
        // 内存SQLite使用memory日志模式 (WAL not supported for :memory:)
        // 测试journal_mode pragma is readable and returns a valid value
        $journalMode = $this->db->fetchOne('PRAGMA journal_mode');
        $mode = strtolower($journalMode['journal_mode'] ?? '');
        $this->assertContains($mode, ['wal', 'memory', 'delete', 'truncate', 'persist']);
    }

    public function testFetchAllReturnsArray(): void
    {
        $result = $this->db->fetchAll("SELECT 1 as num");
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(1, $result[0]['num']);
    }

    public function testFetchOneReturnsSingleRow(): void
    {
        $result = $this->db->fetchOne("SELECT 1 as num, 2 as num2");
        $this->assertIsArray($result);
        $this->assertEquals(1, $result['num']);
        $this->assertEquals(2, $result['num2']);
    }

    public function testExecWithParams(): void
    {
        // Test parameterized query execution
        $this->db->exec("INSERT INTO genes (id, category, data, created_at) VALUES (:id, :cat, :data, :ts)", [
            ':id' => 'test_gene_001',
            ':cat' => 'repair',
            ':data' => json_encode(['signals' => ['error'], 'strategy' => ['step1']]),
            ':ts' => date('c'),
        ]);

        $result = $this->db->fetchOne("SELECT * FROM genes WHERE id = 'test_gene_001'");
        $this->assertNotNull($result);
        $this->assertEquals('repair', $result['category']);
    }

    public function testExecWithNamedParameters(): void
    {
        // Test that named parameters work correctly
        $this->db->exec("INSERT INTO genes (id, category, data, created_at) VALUES (:id, :category, :data, :created)", [
            ':id' => 'test_gene_params',
            ':category' => 'optimize',
            ':data' => json_encode(['signals' => ['signal1', 'signal2'], 'strategy' => ['do something']]),
            ':created' => date('c'),
        ]);

        $result = $this->db->fetchOne("SELECT category FROM genes WHERE id = 'test_gene_params'");
        $this->assertEquals('optimize', $result['category']);
    }
}
