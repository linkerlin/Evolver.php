<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\HubSearch;
use PHPUnit\Framework\TestCase;

final class HubSearchTest extends TestCase
{
    // =========================================================================
    // Configuration Tests
    // =========================================================================

    public function testGetHubUrlReturnsEmptyStringWhenNotSet(): void
    {
        // Save and clear env
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL');

        $url = HubSearch::getHubUrl();
        $this->assertSame('', $url);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        }
    }

    public function testGetHubUrlReturnsEnvValue(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://test.hub.com');

        $url = HubSearch::getHubUrl();
        $this->assertSame('https://test.hub.com', $url);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }

    public function testGetHubUrlStripsTrailingSlash(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://test.hub.com/');

        $url = HubSearch::getHubUrl();
        $this->assertSame('https://test.hub.com', $url);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }

    public function testGetReuseModeDefaultsToReference(): void
    {
        $original = getenv('EVOLVER_REUSE_MODE');
        putenv('EVOLVER_REUSE_MODE');

        $mode = HubSearch::getReuseMode();
        $this->assertSame('reference', $mode);

        // Restore
        if ($original !== false) {
            putenv("EVOLVER_REUSE_MODE={$original}");
        }
    }

    public function testGetReuseModeReturnsDirectWhenSet(): void
    {
        $original = getenv('EVOLVER_REUSE_MODE');
        putenv('EVOLVER_REUSE_MODE=direct');

        $mode = HubSearch::getReuseMode();
        $this->assertSame('direct', $mode);

        // Restore
        if ($original !== false) {
            putenv("EVOLVER_REUSE_MODE={$original}");
        } else {
            putenv('EVOLVER_REUSE_MODE');
        }
    }

    public function testGetMinReuseScoreDefaultsToCorrectValue(): void
    {
        $original = getenv('EVOLVER_MIN_REUSE_SCORE');
        putenv('EVOLVER_MIN_REUSE_SCORE');

        $score = HubSearch::getMinReuseScore();
        $this->assertSame(0.72, $score);

        // Restore
        if ($original !== false) {
            putenv("EVOLVER_MIN_REUSE_SCORE={$original}");
        }
    }

    public function testGetMinReuseScoreReturnsEnvValue(): void
    {
        $original = getenv('EVOLVER_MIN_REUSE_SCORE');
        putenv('EVOLVER_MIN_REUSE_SCORE=0.85');

        $score = HubSearch::getMinReuseScore();
        $this->assertSame(0.85, $score);

        // Restore
        if ($original !== false) {
            putenv("EVOLVER_MIN_REUSE_SCORE={$original}");
        } else {
            putenv('EVOLVER_MIN_REUSE_SCORE');
        }
    }

    // =========================================================================
    // Score Calculation Tests
    // =========================================================================

    public function testScoreHubResultWithMinimalAsset(): void
    {
        $score = HubSearch::scoreHubResult([]);
        $this->assertSame(0.0, $score);
    }

    public function testScoreHubResultWithConfidence(): void
    {
        $score = HubSearch::scoreHubResult(['confidence' => 0.8]);
        // confidence * max(streak, 1) * (reputation / 100)
        // 0.8 * 1 * (50 / 100) = 0.4
        $this->assertSame(0.4, $score);
    }

    public function testScoreHubResultWithSuccessStreak(): void
    {
        $score = HubSearch::scoreHubResult([
            'confidence' => 0.8,
            'success_streak' => 5,
        ]);
        // 0.8 * 5 * 0.5 = 2.0
        $this->assertSame(2.0, $score);
    }

    public function testScoreHubResultWithReputation(): void
    {
        $score = HubSearch::scoreHubResult([
            'confidence' => 0.8,
            'reputation_score' => 100,
        ]);
        // 0.8 * 1 * 1.0 = 0.8
        $this->assertSame(0.8, $score);
    }

    public function testScoreHubResultWithAllFactors(): void
    {
        $score = HubSearch::scoreHubResult([
            'confidence' => 1.0,
            'success_streak' => 10,
            'reputation_score' => 100,
        ]);
        // 1.0 * 10 * 1.0 = 10.0
        $this->assertSame(10.0, $score);
    }

    // =========================================================================
    // Pick Best Match Tests
    // =========================================================================

    public function testPickBestMatchReturnsNullForEmptyResults(): void
    {
        $result = HubSearch::pickBestMatch([], 0.5);
        $this->assertNull($result);
    }

    public function testPickBestMatchReturnsNullWhenBelowThreshold(): void
    {
        $assets = [
            ['confidence' => 0.1, 'status' => 'promoted'],
        ];

        $result = HubSearch::pickBestMatch($assets, 0.72);
        $this->assertNull($result);
    }

    public function testPickBestMatchIgnoresNonPromotedAssets(): void
    {
        $assets = [
            ['confidence' => 0.9, 'status' => 'pending'],
            ['confidence' => 0.3, 'status' => 'promoted'],
        ];

        $result = HubSearch::pickBestMatch($assets, 0.1);
        $this->assertNotNull($result);
        // Should pick the promoted one with lower confidence
        $this->assertSame(0.3, $result['match']['confidence']);
    }

    public function testPickBestMatchPicksHighestScoring(): void
    {
        $assets = [
            ['id' => 'low', 'confidence' => 0.5, 'status' => 'promoted'],
            ['id' => 'high', 'confidence' => 0.9, 'status' => 'promoted'],
        ];

        $result = HubSearch::pickBestMatch($assets, 0.1);
        $this->assertNotNull($result);
        $this->assertSame('high', $result['match']['id']);
    }

    public function testPickBestMatchReturnsCorrectStructure(): void
    {
        $assets = [
            ['id' => 'test', 'confidence' => 0.8, 'status' => 'promoted'],
        ];

        $result = HubSearch::pickBestMatch($assets, 0.1);
        $this->assertNotNull($result);
        $this->assertArrayHasKey('match', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('mode', $result);
    }

    // =========================================================================
    // Hub Search Tests
    // =========================================================================

    public function testHubSearchReturnsNoHubUrlWhenNotConfigured(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL');

        $result = HubSearch::hubSearch(['error', 'syntax']);

        $this->assertFalse($result['hit']);
        $this->assertSame('no_hub_url', $result['reason']);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        }
    }

    public function testHubSearchReturnsNoSignalsWhenEmpty(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://test.hub.com');

        $result = HubSearch::hubSearch([]);

        $this->assertFalse($result['hit']);
        $this->assertSame('no_signals', $result['reason']);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }

    public function testHubSearchFiltersEmptySignals(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://test.hub.com');

        $result = HubSearch::hubSearch(['', 'valid_signal', null]);

        $this->assertFalse($result['hit']);
        // Should not be 'no_signals' since we have one valid signal
        // Will fail due to network but not due to missing signals
        $this->assertNotSame('no_signals', $result['reason']);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }

    public function testHubSearchAcceptsCustomOptions(): void
    {
        $original = getenv('A2A_HUB_URL');
        putenv('A2A_HUB_URL=https://test.hub.com');

        // This will fail due to network, but we verify it accepts options
        $result = HubSearch::hubSearch(['test'], [
            'threshold' => 0.9,
            'limit' => 10,
            'timeoutMs' => 5000,
        ]);

        // Just verify it didn't throw
        $this->assertIsArray($result);

        // Restore
        if ($original !== false) {
            putenv("A2A_HUB_URL={$original}");
        } else {
            putenv('A2A_HUB_URL');
        }
    }
}
