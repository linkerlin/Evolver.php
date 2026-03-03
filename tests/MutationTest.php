<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Mutation;
use PHPUnit\Framework\TestCase;

final class MutationTest extends TestCase
{
    public function testClamp01WithValidValue(): void
    {
        $this->assertEquals(0.5, Mutation::clamp01(0.5));
        $this->assertEquals(0.0, Mutation::clamp01(0.0));
        $this->assertEquals(1.0, Mutation::clamp01(1.0));
    }

    public function testClamp01WithOutOfRangeValue(): void
    {
        $this->assertEquals(0.0, Mutation::clamp01(-0.5));
        $this->assertEquals(1.0, Mutation::clamp01(1.5));
    }

    public function testClamp01WithNonNumeric(): void
    {
        $this->assertEquals(0.0, Mutation::clamp01('invalid'));
        $this->assertEquals(0.0, Mutation::clamp01(null));
    }

    public function testNowTsMsReturnsInt(): void
    {
        $ts = Mutation::nowTsMs();

        $this->assertIsInt($ts);
        $this->assertGreaterThan(0, $ts);
    }

    public function testUniqStringsRemovesDuplicates(): void
    {
        $input = ['a', 'b', 'a', 'c', 'B', ''];
        $result = Mutation::uniqStrings($input);

        $this->assertCount(3, $result);
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);
    }

    public function testHasErrorishSignalWithLogError(): void
    {
        $this->assertTrue(Mutation::hasErrorishSignal(['log_error']));
    }

    public function testHasErrorishSignalWithErrsig(): void
    {
        $this->assertTrue(Mutation::hasErrorishSignal(['errsig:PDOException']));
    }

    public function testHasErrorishSignalWithResolvedIssue(): void
    {
        $this->assertFalse(Mutation::hasErrorishSignal(['issue_already_resolved', 'log_error']));
    }

    public function testHasErrorishSignalWithEmpty(): void
    {
        $this->assertFalse(Mutation::hasErrorishSignal([]));
    }

    public function testHasOpportunitySignalWithValidSignal(): void
    {
        $this->assertTrue(Mutation::hasOpportunitySignal(['user_feature_request']));
        $this->assertTrue(Mutation::hasOpportunitySignal(['capability_gap']));
    }

    public function testHasOpportunitySignalWithEmpty(): void
    {
        $this->assertFalse(Mutation::hasOpportunitySignal([]));
    }

    public function testMutationCategoryFromContextWithError(): void
    {
        $this->assertEquals('repair', Mutation::mutationCategoryFromContext(['log_error']));
    }

    public function testMutationCategoryFromContextWithOpportunity(): void
    {
        $this->assertEquals('innovate', Mutation::mutationCategoryFromContext(['user_feature_request']));
    }

    public function testMutationCategoryFromContextWithDrift(): void
    {
        $this->assertEquals('innovate', Mutation::mutationCategoryFromContext([], true));
    }

    public function testExpectedEffectFromCategory(): void
    {
        $this->assertStringContainsString('error', Mutation::expectedEffectFromCategory('repair'));
        $this->assertStringContainsString('success', Mutation::expectedEffectFromCategory('optimize'));
        $this->assertStringContainsString('strategy', Mutation::expectedEffectFromCategory('innovate'));
    }

    public function testBuildMutationDefaults(): void
    {
        $mutation = Mutation::buildMutation();

        $this->assertEquals('Mutation', $mutation['type']);
        $this->assertArrayHasKey('id', $mutation);
        $this->assertArrayHasKey('category', $mutation);
        $this->assertArrayHasKey('trigger_signals', $mutation);
        $this->assertArrayHasKey('target', $mutation);
        $this->assertArrayHasKey('expected_effect', $mutation);
        $this->assertArrayHasKey('risk_level', $mutation);
    }

    public function testBuildMutationWithSignals(): void
    {
        $mutation = Mutation::buildMutation(['log_error', 'user_feature_request']);

        $this->assertEquals('repair', $mutation['category']); // Error takes priority
        $this->assertContains('log_error', $mutation['trigger_signals']);
    }

    public function testBuildMutationWithHighRiskPersonality(): void
    {
        $highRiskPersonality = ['rigor' => 0.3, 'risk_tolerance' => 0.8];

        $mutation = Mutation::buildMutation(['user_feature_request'], null, false, $highRiskPersonality);

        // Should downgrade to optimize
        $this->assertEquals('optimize', $mutation['category']);
    }

    public function testIsValidMutationWithValid(): void
    {
        $mutation = Mutation::buildMutation();
        $this->assertTrue(Mutation::isValidMutation($mutation));
    }

    public function testIsValidMutationWithInvalid(): void
    {
        $this->assertFalse(Mutation::isValidMutation([]));
        $this->assertFalse(Mutation::isValidMutation(['type' => 'Wrong']));
        $this->assertFalse(Mutation::isValidMutation([
            'type' => 'Mutation',
            'id' => '',
        ]));
    }

    public function testNormalizeMutation(): void
    {
        $normalized = Mutation::normalizeMutation(['type' => 'Mutation']);

        $this->assertEquals('Mutation', $normalized['type']);
        $this->assertEquals('optimize', $normalized['category']);
        $this->assertEquals('low', $normalized['risk_level']);
    }

    public function testIsHighRiskPersonalityWithLowRigor(): void
    {
        $this->assertTrue(Mutation::isHighRiskPersonality(['rigor' => 0.3]));
    }

    public function testIsHighRiskPersonalityWithHighRiskTolerance(): void
    {
        $this->assertTrue(Mutation::isHighRiskPersonality(['risk_tolerance' => 0.7]));
    }

    public function testIsHighRiskPersonalityWithSafe(): void
    {
        $this->assertFalse(Mutation::isHighRiskPersonality(['rigor' => 0.7, 'risk_tolerance' => 0.3]));
    }

    public function testIsHighRiskMutationAllowed(): void
    {
        $safePersonality = ['rigor' => 0.7, 'risk_tolerance' => 0.3];
        $unsafePersonality = ['rigor' => 0.5, 'risk_tolerance' => 0.6];

        $this->assertTrue(Mutation::isHighRiskMutationAllowed($safePersonality));
        $this->assertFalse(Mutation::isHighRiskMutationAllowed($unsafePersonality));
    }
}
