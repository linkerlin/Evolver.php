<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Bridge;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bridge_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }

    public function testClipDoesNotModifyShortText(): void
    {
        $text = 'Short text';
        $this->assertEquals($text, Bridge::clip($text, 100));
    }

    public function testClipTruncatesLongText(): void
    {
        $text = str_repeat('a', 200);
        $result = Bridge::clip($text, 100);

        $this->assertLessThanOrEqual(104, strlen($result)); // 100 - 40 + truncation marker
        $this->assertStringContainsString('TRUNCATED', $result);
    }

    public function testClipWithZeroMaxChars(): void
    {
        $text = 'Any text';
        $this->assertEquals($text, Bridge::clip($text, 0));
    }

    public function testWritePromptArtifactCreatesFiles(): void
    {
        $params = [
            'memoryDir' => $this->tempDir,
            'prompt' => 'Test prompt content',
            'cycleId' => 'cycle123',
            'runId' => 'run456',
        ];

        $result = Bridge::writePromptArtifact($params);

        $this->assertArrayHasKey('promptPath', $result);
        $this->assertArrayHasKey('metaPath', $result);
        $this->assertFileExists($result['promptPath']);
        $this->assertFileExists($result['metaPath']);
        $this->assertEquals('Test prompt content', file_get_contents($result['promptPath']));
    }

    public function testWritePromptArtifactThrowsWithoutMemoryDir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing memoryDir');

        Bridge::writePromptArtifact(['prompt' => 'test']);
    }

    public function testWritePromptArtifactCreatesDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        Bridge::writePromptArtifact([
            'memoryDir' => $this->tempDir,
            'prompt' => 'test',
        ]);

        $this->assertDirectoryExists($this->tempDir);
    }

    public function testRenderSessionsSpawnCall(): void
    {
        $params = [
            'task' => 'Do something',
            'agentId' => 'agent1',
            'label' => 'test_label',
            'cleanup' => 'keep',
        ];

        $result = Bridge::renderSessionsSpawnCall($params);

        $this->assertStringStartsWith('sessions_spawn(', $result);
        $this->assertStringContainsString('Do something', $result);
        $this->assertStringContainsString('agent1', $result);
        $this->assertStringContainsString('test_label', $result);
    }

    public function testRenderSessionsSpawnCallThrowsWithoutTask(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing task');

        Bridge::renderSessionsSpawnCall(['agentId' => 'test']);
    }

    public function testRenderSessionsSpawnCallUsesDefaults(): void
    {
        $result = Bridge::renderSessionsSpawnCall(['task' => 'Test task']);

        $this->assertStringContainsString('main', $result); // default agentId
        $this->assertStringContainsString('delete', $result); // default cleanup
        $this->assertStringContainsString('gep_bridge', $result); // default label
    }

    public function testWritePromptArtifactCreatesValidJsonMeta(): void
    {
        Bridge::writePromptArtifact([
            'memoryDir' => $this->tempDir,
            'prompt' => 'test',
            'meta' => ['key' => 'value'],
        ]);

        $metaFile = glob($this->tempDir . '/*.json')[0];
        $meta = json_decode(file_get_contents($metaFile), true);

        $this->assertEquals('GepPromptArtifact', $meta['type']);
        $this->assertEquals(['key' => 'value'], $meta['meta']);
    }
}
