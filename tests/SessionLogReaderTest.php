<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SessionLogReader;
use PHPUnit\Framework\TestCase;

final class SessionLogReaderTest extends TestCase
{
    private SessionLogReader $reader;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->reader = new SessionLogReader();
        $this->tempDir = sys_get_temp_dir() . '/evolver_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function testParseSessionEntry(): void
    {
        // Test message entry
        $json = json_encode([
            'timestamp' => '2026-03-03T10:00:00Z',
            'type' => 'message',
            'role' => 'user',
            'content' => 'Hello world',
        ]);

        $entry = $this->reader->parseSessionEntry($json);

        $this->assertNotNull($entry);
        $this->assertEquals('2026-03-03T10:00:00Z', $entry['timestamp']);
        $this->assertEquals('message', $entry['type']);
        $this->assertEquals('user', $entry['role']);
        $this->assertEquals('Hello world', $entry['content']);
    }

    public function testParseSessionEntryWithToolCall(): void
    {
        $json = json_encode([
            'timestamp' => '2026-03-03T10:00:00Z',
            'type' => 'message',
            'role' => 'assistant',
            'message' => [
                'content' => 'Using tool',
                'tool_calls' => [
                    ['name' => 'read_file', 'args' => ['path' => '/test']],
                ],
            ],
        ]);

        $entry = $this->reader->parseSessionEntry($json);

        $this->assertNotNull($entry);
        $this->assertEquals('assistant', $entry['role']);
        $this->assertNotNull($entry['tool_calls']);
        $this->assertCount(1, $entry['tool_calls']);
    }

    public function testParseSessionEntryWithError(): void
    {
        $json = json_encode([
            'timestamp' => '2026-03-03T10:00:00Z',
            'type' => 'error',
            'errorMessage' => 'Something went wrong',
        ]);

        $entry = $this->reader->parseSessionEntry($json);

        $this->assertNotNull($entry);
        $this->assertNotNull($entry['error']);
        $this->assertEquals('Something went wrong', $entry['error']['message']);
    }

    public function testParseSessionEntryInvalidJson(): void
    {
        $entry = $this->reader->parseSessionEntry('not valid json');
        $this->assertNull($entry);
    }

    public function testDeduplicateLines(): void
    {
        $entries = [
            ['content' => 'First line', 'type' => 'message'],
            ['content' => 'First line', 'type' => 'message'],
            ['content' => 'First line', 'type' => 'message'],
            ['content' => 'Second line', 'type' => 'message'],
            ['content' => 'Second line', 'type' => 'message'],
        ];

        $deduplicated = $this->reader->deduplicateLines($entries);

        // Should have: First line, folded (3 total), Second line, folded (2 total)
        // First line: 3 entries -> 1 original + folded with count=3
        // Second line: 2 entries -> 1 original + folded with count=2
        $this->assertCount(4, $deduplicated);
        $this->assertEquals('First line', $deduplicated[0]['content']);
        $this->assertEquals('folded', $deduplicated[1]['type']);
        $this->assertEquals(3, $deduplicated[1]['count']); // 3 total occurrences
        $this->assertEquals('Second line', $deduplicated[2]['content']);
        $this->assertEquals('folded', $deduplicated[3]['type']);
        $this->assertEquals(2, $deduplicated[3]['count']); // 2 total occurrences
    }

    public function testDeduplicateLinesEmpty(): void
    {
        $deduplicated = $this->reader->deduplicateLines([]);
        $this->assertEmpty($deduplicated);
    }

    public function testReadSessionFile(): void
    {
        $filepath = $this->tempDir . '/test_session.jsonl';
        $entries = [
            ['timestamp' => '2026-03-03T10:00:00Z', 'type' => 'message', 'content' => 'Entry 1'],
            ['timestamp' => '2026-03-03T10:01:00Z', 'type' => 'message', 'content' => 'Entry 2'],
        ];

        $handle = fopen($filepath, 'w');
        foreach ($entries as $entry) {
            fwrite($handle, json_encode($entry) . "\n");
        }
        fclose($handle);

        $readEntries = $this->reader->readSessionFile($filepath);

        $this->assertCount(2, $readEntries);
        $this->assertEquals('Entry 1', $readEntries[0]['content']);
        $this->assertEquals('Entry 2', $readEntries[1]['content']);
    }

    public function testReadSessionFileNotFound(): void
    {
        $entries = $this->reader->readSessionFile('/nonexistent/file.jsonl');
        $this->assertEmpty($entries);
    }

    public function testExtractTranscript(): void
    {
        $entries = [
            ['content' => 'Hello', 'role' => 'user', 'type' => 'message'],
            ['content' => 'Hi there', 'role' => 'assistant', 'type' => 'message'],
            ['content' => null, 'role' => null, 'tool_results' => [['status' => 'success']], 'type' => 'tool_result'],
            ['content' => null, 'role' => null, 'error' => ['message' => 'Error occurred'], 'type' => 'error'],
        ];

        $transcript = $this->reader->extractTranscript($entries);

        $this->assertStringContainsString('[user] Hello', $transcript);
        $this->assertStringContainsString('[assistant] Hi there', $transcript);
        $this->assertStringContainsString('[tool_result]', $transcript);
        $this->assertStringContainsString('[error] Error occurred', $transcript);
    }

    public function testGetSessionScope(): void
    {
        // Test with no scope set
        putenv('EVOLVER_SESSION_SCOPE=');
        $scope = SessionLogReader::getSessionScope();
        $this->assertNull($scope);

        // Test with scope set
        putenv('EVOLVER_SESSION_SCOPE=test_project');
        $scope = SessionLogReader::getSessionScope();
        $this->assertEquals('test_project', $scope);

        // Cleanup
        putenv('EVOLVER_SESSION_SCOPE');
    }

    public function testFilterByScope(): void
    {
        $sessions = [
            ['path' => '/sessions/user_session.jsonl', 'mtime' => time(), 'entries' => [], 'summary' => ''],
            ['path' => '/sessions/evolution_test.jsonl', 'mtime' => time(), 'entries' => [], 'summary' => ''],
            ['path' => '/sessions/user_other.jsonl', 'mtime' => time(), 'entries' => [], 'summary' => ''],
        ];

        $filtered = $this->reader->filterByScope('user', $sessions);

        $this->assertCount(2, $filtered);
    }

    public function testIsEvolverSessionDetection(): void
    {
        // Create a mock evolver session file
        $evolverFile = $this->tempDir . '/evolution_test.jsonl';
        file_put_contents($evolverFile, json_encode(['gene_id' => 'test_gene', 'type' => 'evolution']) . "\n");

        // Create a regular session file
        $regularFile = $this->tempDir . '/user_session.jsonl';
        file_put_contents($regularFile, json_encode(['message' => 'Hello']) . "\n");

        // We can't directly test isEvolverSession since it's private,
        // but we can test via findSessionFiles
        putenv('SESSIONS_DIR=' . $this->tempDir);
        
        $files = $this->reader->findSessionFiles();
        $paths = array_column($files, 'path');

        // Should only include user_session, not evolution_test
        $this->assertContains($regularFile, $paths);
        $this->assertNotContains($evolverFile, $paths);

        putenv('SESSIONS_DIR');
    }
}
