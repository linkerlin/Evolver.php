<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\SkillPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Skill Publisher tests.
 */
final class SkillPublisherTest extends TestCase
{
    private SkillPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new SkillPublisher(null, 'https://test-hub.example.com');
    }

    // =========================================================================
    // Gene to SKILL.md Conversion Tests
    // =========================================================================

    public function testGeneToSkillMdGeneratesYamlFrontmatter(): void
    {
        $gene = [
            'id' => 'gene_fix_type_error',
            'summary' => 'Automatically fixes TypeError issues',
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('---', $skill);
        $this->assertStringContainsString('name:', $skill);
        $this->assertStringContainsString('description:', $skill);
        $this->assertStringContainsString('fix-type-error', $skill);
    }

    public function testGeneToSkillMdIncludesTriggerSignals(): void
    {
        $gene = [
            'id' => 'gene_test',
            'signals_match' => ['log_error', 'errsig:TypeError', 'exception'],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('## Trigger Signals', $skill);
        $this->assertStringContainsString('`log_error`', $skill);
        $this->assertStringContainsString('`errsig:TypeError`', $skill);
    }

    public function testGeneToSkillMdIncludesStrategy(): void
    {
        $gene = [
            'id' => 'gene_test',
            'strategy' => [
                'Analyze the error stack trace',
                'Locate the problematic variable',
                'Add type checking',
            ],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('## Strategy', $skill);
        $this->assertStringContainsString('1. Analyze the error stack trace', $skill);
        $this->assertStringContainsString('2. Locate the problematic variable', $skill);
        $this->assertStringContainsString('3. Add type checking', $skill);
    }

    public function testGeneToSkillMdIncludesConstraints(): void
    {
        $gene = [
            'id' => 'gene_test',
            'constraints' => [
                'max_files' => 5,
                'forbidden_paths' => ['vendor/', 'node_modules/'],
            ],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('## Constraints', $skill);
        $this->assertStringContainsString('Max files: 5', $skill);
        $this->assertStringContainsString('vendor/', $skill);
    }

    public function testGeneToSkillMdIncludesValidation(): void
    {
        $gene = [
            'id' => 'gene_test',
            'validation' => [
                'php vendor/bin/phpunit tests/',
                'php -l src/',
            ],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('## Validation', $skill);
        $this->assertStringContainsString('```bash', $skill);
        $this->assertStringContainsString('php vendor/bin/phpunit', $skill);
    }

    public function testGeneToSkillMdIncludesLicense(): void
    {
        $gene = ['id' => 'gene_test'];

        $skill = $this->publisher->geneToSkillMd($gene);

        $this->assertStringContainsString('Evolver', $skill);
        $this->assertStringContainsString('EvoMap Skill License', $skill);
    }

    public function testGeneToSkillMdSanitizesTimestampNames(): void
    {
        $gene = [
            'id' => 'gene_1700000000000',
            'signals_match' => ['error', 'syntax'],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        // Should use fallback name from signals, not timestamp
        $this->assertStringContainsString('name:', $skill);
        $this->assertStringNotContainsString('1700000000000', $skill);
    }

    public function testGeneToSkillMdSanitizesIdeNames(): void
    {
        $gene = [
            'id' => 'gene_cursor',
            'signals_match' => ['error', 'fix'],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        // Should use fallback name from signals, not "cursor"
        $this->assertDoesNotMatchRegularExpression('/name:\s*cursor/i', $skill);
    }

    public function testGeneToSkillMdUsesFallbackForShortNames(): void
    {
        $gene = [
            'id' => 'gene_abc', // Too short
            'signals_match' => ['error', 'syntax', 'bug'],
        ];

        $skill = $this->publisher->geneToSkillMd($gene);

        // Should generate fallback name
        $this->assertStringContainsString('name:', $skill);
    }

    // =========================================================================
    // Hub Publishing Tests (Network Mock)
    // =========================================================================

    public function testPublishToHubReturnsErrorWithNoHubUrl(): void
    {
        $publisher = new SkillPublisher(null, '');
        $gene = ['id' => 'gene_test'];

        $result = $publisher->publishToHub($gene);

        $this->assertFalse($result['ok']);
        $this->assertEquals('no_hub_url', $result['error']);
    }

    public function testPublishToHubReturnsCorrectStructure(): void
    {
        $gene = [
            'id' => 'gene_test_skill',
            'summary' => 'Test skill',
        ];

        // Will fail due to network, but should have correct structure
        $result = $this->publisher->publishToHub($gene);

        $this->assertArrayHasKey('ok', $result);
        $this->assertIsBool($result['ok']);

        if (!$result['ok']) {
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function testUpdateOnHubReturnsErrorWithNoHubUrl(): void
    {
        $publisher = new SkillPublisher(null, '');

        $result = $publisher->updateOnHub('node_123', 'skill_test', 'content', [], []);

        $this->assertFalse($result['ok']);
        $this->assertEquals('no_hub_url', $result['error']);
    }

    // =========================================================================
    // Getter Tests
    // =========================================================================

    public function testGetHubUrlReturnsConfiguredUrl(): void
    {
        $this->assertSame('https://test-hub.example.com', $this->publisher->getHubUrl());
    }

    public function testConstructorWithDefaultParams(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://default.hub.com');

        $publisher = new SkillPublisher();
        $this->assertSame('https://default.hub.com', $publisher->getHubUrl());

        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }
}
