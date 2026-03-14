<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\HubReview;
use Evolver\Paths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HubReviewTest extends TestCase
{
    private ?string $originalEvolutionDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEvolutionDir = getenv('EVOLUTION_DIR') ?: null;
        putenv('EVOLUTION_DIR=' . sys_get_temp_dir() . '/evolver_hubreview_test_' . uniqid());
        $this->cleanupTestFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
        if ($this->originalEvolutionDir !== null) {
            putenv('EVOLUTION_DIR=' . $this->originalEvolutionDir);
        } else {
            putenv('EVOLUTION_DIR');
        }
        parent::tearDown();
    }

    private function cleanupTestFiles(): void
    {
        $historyFile = HubReview::getHistoryFilePath();
        if (file_exists($historyFile)) {
            unlink($historyFile);
        }
        if (file_exists($historyFile . '.tmp')) {
            unlink($historyFile . '.tmp');
        }
    }

    #[Test]
    public function deriveRatingReturns5ForSuccessWithHighScore(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'success', 'score' => 0.9];
        $this->assertEquals(5, $review->deriveRating($outcome, null));
    }

    #[Test]
    public function deriveRatingReturns5ForSuccessWithExactScore(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'success', 'score' => 0.85];
        $this->assertEquals(5, $review->deriveRating($outcome, null));
    }

    #[Test]
    public function deriveRatingReturns4ForSuccessWithLowScore(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'success', 'score' => 0.7];
        $this->assertEquals(4, $review->deriveRating($outcome, null));
    }

    #[Test]
    public function deriveRatingReturns4ForSuccessWithNoScore(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'success'];
        $this->assertEquals(4, $review->deriveRating($outcome, null));
    }

    #[Test]
    public function deriveRatingReturns1ForFailureWithConstraintViolation(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'failed'];
        $constraintCheck = ['violations' => ['max_files_exceeded']];
        $this->assertEquals(1, $review->deriveRating($outcome, $constraintCheck));
    }

    #[Test]
    public function deriveRatingReturns2ForFailureWithoutConstraintViolation(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'failed'];
        $this->assertEquals(2, $review->deriveRating($outcome, null));
    }

    #[Test]
    public function deriveRatingReturns2ForNullOutcome(): void
    {
        $review = new HubReview(null, '');
        $this->assertEquals(2, $review->deriveRating(null, null));
    }

    #[Test]
    public function deriveRatingReturns2ForFailureWithEmptyViolations(): void
    {
        $review = new HubReview(null, '');
        $outcome = ['status' => 'failed'];
        $constraintCheck = ['violations' => []];
        $this->assertEquals(2, $review->deriveRating($outcome, $constraintCheck));
    }

    #[Test]
    public function buildContentIncludesOutcomeAndScore(): void
    {
        $review = new HubReview(null, '');
        $content = $review->buildContent(
            ['status' => 'success', 'score' => 0.92],
            null,
            [],
            null,
            'reused'
        );

        $this->assertStringContainsString('Outcome: success', $content);
        $this->assertStringContainsString('score: 0.92', $content);
    }

    #[Test]
    public function buildContentIncludesReuseMode(): void
    {
        $review = new HubReview(null, '');
        $content = $review->buildContent(null, null, [], null, 'reference');

        $this->assertStringContainsString('Reuse mode: reference', $content);
    }

    #[Test]
    public function buildContentIncludesGeneInfo(): void
    {
        $review = new HubReview(null, '');
        $gene = ['id' => 'gene_123', 'category' => 'refactor'];
        $content = $review->buildContent(null, $gene, [], null, null);

        $this->assertStringContainsString('Gene: gene_123', $content);
        $this->assertStringContainsString('refactor', $content);
    }

    #[Test]
    public function buildContentIncludesSignals(): void
    {
        $review = new HubReview(null, '');
        $signals = ['signal_a', 'signal_b', 'signal_c'];
        $content = $review->buildContent(null, null, $signals, null, null);

        $this->assertStringContainsString('Signals:', $content);
        $this->assertStringContainsString('signal_a, signal_b, signal_c', $content);
    }

    #[Test]
    public function buildContentLimitsSignalsToSix(): void
    {
        $review = new HubReview(null, '');
        $signals = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'];
        $content = $review->buildContent(null, null, $signals, null, null);

        $this->assertStringContainsString('s1, s2, s3, s4, s5, s6', $content);
        $this->assertStringNotContainsString('s7', $content);
    }

    #[Test]
    public function buildContentIncludesBlastRadius(): void
    {
        $review = new HubReview(null, '');
        $blast = ['files' => 5, 'lines' => 120];
        $content = $review->buildContent(null, null, [], $blast, null);

        $this->assertStringContainsString('Blast radius:', $content);
        $this->assertStringContainsString('5 file(s)', $content);
        $this->assertStringContainsString('120 line(s)', $content);
    }

    #[Test]
    public function buildContentIncludesSuccessMessage(): void
    {
        $review = new HubReview(null, '');
        $content = $review->buildContent(['status' => 'success'], null, [], null, null);

        $this->assertStringContainsString('successfully applied and solidified', $content);
    }

    #[Test]
    public function buildContentIncludesFailureMessage(): void
    {
        $review = new HubReview(null, '');
        $content = $review->buildContent(['status' => 'failed'], null, [], null, null);

        $this->assertStringContainsString('did not lead to a successful evolution', $content);
    }

    #[Test]
    public function submitReturnsNoHubUrlWhenNotConfigured(): void
    {
        $review = new HubReview(null, '');
        $result = $review->submit([
            'reusedAssetId' => 'asset_123',
            'sourceType' => 'reused',
            'outcome' => ['status' => 'success', 'score' => 0.9],
        ]);

        $this->assertFalse($result['submitted']);
        $this->assertEquals('no_hub_url', $result['reason']);
    }

    #[Test]
    public function submitReturnsNoAssetIdWhenMissing(): void
    {
        $review = new HubReview(null, 'https://hub.example.com');
        $result = $review->submit([
            'sourceType' => 'reused',
            'outcome' => ['status' => 'success'],
        ]);

        $this->assertFalse($result['submitted']);
        $this->assertEquals('no_reused_asset_id', $result['reason']);
    }

    #[Test]
    public function submitReturnsNotHubSourcedForWrongSourceType(): void
    {
        $review = new HubReview(null, 'https://hub.example.com');
        $result = $review->submit([
            'reusedAssetId' => 'asset_123',
            'sourceType' => 'generated',
            'outcome' => ['status' => 'success'],
        ]);

        $this->assertFalse($result['submitted']);
        $this->assertEquals('not_hub_sourced', $result['reason']);
    }

    #[Test]
    public function submitAcceptsReusedSourceType(): void
    {
        // This test will fail at HTTP stage since no real hub, but validates source type check
        $review = new HubReview(null, 'https://hub.example.com');
        $result = $review->submit([
            'reusedAssetId' => 'asset_123',
            'sourceType' => 'reused',
            'outcome' => ['status' => 'success'],
        ]);

        // Should not be rejected for source type
        $this->assertNotEquals('not_hub_sourced', $result['reason'] ?? '');
    }

    #[Test]
    public function submitAcceptsReferenceSourceType(): void
    {
        $review = new HubReview(null, 'https://hub.example.com');
        $result = $review->submit([
            'reusedAssetId' => 'asset_123',
            'sourceType' => 'reference',
            'outcome' => ['status' => 'success'],
        ]);

        // Should not be rejected for source type
        $this->assertNotEquals('not_hub_sourced', $result['reason'] ?? '');
    }

    #[Test]
    public function getHistoryFilePathReturnsCorrectPath(): void
    {
        $path = HubReview::getHistoryFilePath();
        $this->assertStringEndsWith('hub_review_history.json', $path);
        $this->assertStringContainsString(Paths::getEvolutionDir(), $path);
    }

    #[Test]
    public function getHubUrlReturnsConfiguredUrl(): void
    {
        $review = new HubReview(null, 'https://custom.hub.com');
        $this->assertEquals('https://custom.hub.com', $review->getHubUrl());
    }

    #[Test]
    public function getHubUrlStripsTrailingSlash(): void
    {
        $review = new HubReview(null, 'https://hub.com/');
        $this->assertEquals('https://hub.com', $review->getHubUrl());
    }

    #[Test]
    public function constructorWithDefaultParams(): void
    {
        putenv('A2A_HUB_URL=');
        putenv('EVOMAP_HUB_URL=');
        $review = new HubReview();
        $this->assertEquals('', $review->getHubUrl());
    }
}
