<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\LlmReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmReviewTest extends TestCase
{
    private ?string $originalLlmReview = null;
    private ?string $originalLlmCommand = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLlmReview = getenv('EVOLVER_LLM_REVIEW') ?: null;
        $this->originalLlmCommand = getenv('EVOLVER_LLM_COMMAND') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->originalLlmReview !== null) {
            putenv('EVOLVER_LLM_REVIEW=' . $this->originalLlmReview);
        } else {
            putenv('EVOLVER_LLM_REVIEW');
        }
        if ($this->originalLlmCommand !== null) {
            putenv('EVOLVER_LLM_COMMAND=' . $this->originalLlmCommand);
        } else {
            putenv('EVOLVER_LLM_COMMAND');
        }
        parent::tearDown();
    }

    #[Test]
    public function isEnabledReturnsFalseByDefault(): void
    {
        putenv('EVOLVER_LLM_REVIEW');
        $review = new LlmReview();
        $this->assertFalse($review->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsTrueWhenSet(): void
    {
        putenv('EVOLVER_LLM_REVIEW=true');
        $review = new LlmReview();
        $this->assertTrue($review->isEnabled());
    }

    #[Test]
    public function isEnabledReturnsFalseForOtherValues(): void
    {
        putenv('EVOLVER_LLM_REVIEW=false');
        $review = new LlmReview();
        $this->assertFalse($review->isEnabled());

        putenv('EVOLVER_LLM_REVIEW=1');
        $review = new LlmReview();
        $this->assertFalse($review->isEnabled());
    }

    #[Test]
    public function buildPromptIncludesGeneId(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'gene' => ['id' => 'gene_test_123', 'category' => 'refactor'],
        ]);

        $this->assertStringContainsString('gene_test_123', $prompt);
    }

    #[Test]
    public function buildPromptIncludesGeneCategory(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'gene' => ['id' => 'gene_test', 'category' => 'refactor'],
        ]);

        $this->assertStringContainsString('refactor', $prompt);
    }

    #[Test]
    public function buildPromptIncludesSignals(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'signals' => ['signal_a', 'signal_b', 'signal_c'],
        ]);

        $this->assertStringContainsString('signal_a, signal_b, signal_c', $prompt);
    }

    #[Test]
    public function buildPromptLimitsSignalsToEight(): void
    {
        $review = new LlmReview();
        $signals = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10'];
        $prompt = $review->buildPrompt(['signals' => $signals]);

        $this->assertStringContainsString('s1, s2, s3, s4, s5, s6, s7, s8', $prompt);
        $this->assertStringNotContainsString('s9', $prompt);
    }

    #[Test]
    public function buildPromptIncludesRationale(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'mutation' => ['rationale' => 'This is my reasoning for the change'],
        ]);

        $this->assertStringContainsString('This is my reasoning for the change', $prompt);
    }

    #[Test]
    public function buildPromptTruncatesLongRationale(): void
    {
        $review = new LlmReview();
        $longRationale = str_repeat('a', 600);
        $prompt = $review->buildPrompt([
            'mutation' => ['rationale' => $longRationale],
        ]);

        // Should be truncated to ~500 chars
        $this->assertStringContainsString(str_repeat('a', 500), $prompt);
        $this->assertStringNotContainsString(str_repeat('a', 600), $prompt);
    }

    #[Test]
    public function buildPromptIncludesDiff(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'diff' => '--- a/file.php\n+++ b/file.php\n@@ -1 +1 @@',
        ]);

        $this->assertStringContainsString('--- a/file.php', $prompt);
    }

    #[Test]
    public function buildPromptTruncatesLongDiff(): void
    {
        $review = new LlmReview();
        $longDiff = str_repeat('a', 6500);
        $prompt = $review->buildPrompt(['diff' => $longDiff]);

        // Should be truncated to ~6000 chars
        $this->assertStringContainsString(str_repeat('a', 1000), $prompt);
    }

    #[Test]
    public function buildPromptIncludesReviewCriteria(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([]);

        $this->assertStringContainsString('## Review Criteria', $prompt);
        $this->assertStringContainsString('regressions or bugs', $prompt);
        $this->assertStringContainsString('security', $prompt);
    }

    #[Test]
    public function buildPromptIncludesResponseFormat(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([]);

        $this->assertStringContainsString('approved', $prompt);
        $this->assertStringContainsString('confidence', $prompt);
        $this->assertStringContainsString('concerns', $prompt);
    }

    #[Test]
    public function buildPromptUsesMutationCategoryOverGeneCategory(): void
    {
        $review = new LlmReview();
        $prompt = $review->buildPrompt([
            'gene' => ['id' => 'gene_test', 'category' => 'refactor'],
            'mutation' => ['category' => 'feature'],
        ]);

        $this->assertStringContainsString('feature', $prompt);
    }

    #[Test]
    public function runReviewReturnsNullWhenDisabled(): void
    {
        putenv('EVOLVER_LLM_REVIEW=false');
        $review = new LlmReview();
        $result = $review->runReview([]);

        $this->assertNull($result);
    }

    #[Test]
    public function runReviewReturnsDefaultWhenEnabled(): void
    {
        putenv('EVOLVER_LLM_REVIEW=true');
        putenv('EVOLVER_LLM_COMMAND'); // Unset command
        $review = new LlmReview();
        $result = $review->runReview([]);

        $this->assertNotNull($result);
        $this->assertTrue($result['approved']);
        $this->assertEquals(0.7, $result['confidence']);
        $this->assertIsArray($result['concerns']);
        $this->assertStringContainsString('auto-approved', $result['summary']);
    }

    #[Test]
    public function runReviewReturnsCorrectStructure(): void
    {
        putenv('EVOLVER_LLM_REVIEW=true');
        putenv('EVOLVER_LLM_COMMAND');
        $review = new LlmReview();
        $result = $review->runReview([
            'gene' => ['id' => 'test_gene'],
            'signals' => ['test_signal'],
        ]);

        $this->assertArrayHasKey('approved', $result);
        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('concerns', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    #[Test]
    public function runReviewHandlesMissingData(): void
    {
        putenv('EVOLVER_LLM_REVIEW=true');
        putenv('EVOLVER_LLM_COMMAND');
        $review = new LlmReview();
        $result = $review->runReview([]);

        $this->assertNotNull($result);
        $this->assertTrue($result['approved']);
    }
}
