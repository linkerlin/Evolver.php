<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\Personality;
use PHPUnit\Framework\TestCase;

final class PersonalityTest extends TestCase
{
    public function testClamp01WithValidValue(): void
    {
        $this->assertEquals(0.5, Personality::clamp01(0.5));
        $this->assertEquals(0.0, Personality::clamp01(0.0));
        $this->assertEquals(1.0, Personality::clamp01(1.0));
    }

    public function testClamp01WithOutOfRangeValue(): void
    {
        $this->assertEquals(0.0, Personality::clamp01(-0.5));
        $this->assertEquals(1.0, Personality::clamp01(1.5));
    }

    public function testDefaultPersonalityState(): void
    {
        $default = Personality::defaultPersonalityState();

        $this->assertEquals('PersonalityState', $default['type']);
        $this->assertArrayHasKey('rigor', $default);
        $this->assertArrayHasKey('creativity', $default);
        $this->assertArrayHasKey('verbosity', $default);
        $this->assertArrayHasKey('risk_tolerance', $default);
        $this->assertArrayHasKey('obedience', $default);
    }

    public function testNormalizePersonalityState(): void
    {
        $normalized = Personality::normalizePersonalityState([
            'rigor' => 1.5, // Out of range
            'creativity' => -0.5, // Out of range
        ]);

        $this->assertEquals(1.0, $normalized['rigor']);
        $this->assertEquals(0.0, $normalized['creativity']);
    }

    public function testNormalizePersonalityStateWithNonArray(): void
    {
        $normalized = Personality::normalizePersonalityState('invalid');

        $this->assertEquals('PersonalityState', $normalized['type']);
    }

    public function testIsValidPersonalityStateWithValid(): void
    {
        $valid = Personality::defaultPersonalityState();
        $this->assertTrue(Personality::isValidPersonalityState($valid));
    }

    public function testIsValidPersonalityStateWithInvalid(): void
    {
        $this->assertFalse(Personality::isValidPersonalityState([]));
        $this->assertFalse(Personality::isValidPersonalityState(['type' => 'Wrong']));
        $this->assertFalse(Personality::isValidPersonalityState([
            'type' => 'PersonalityState',
            'rigor' => 1.5, // Out of range
        ]));
    }

    public function testPersonalityKey(): void
    {
        $state = Personality::defaultPersonalityState();
        $key = Personality::personalityKey($state);

        $this->assertStringContainsString('rigor=', $key);
        $this->assertStringContainsString('creativity=', $key);
    }

    public function testPersonalityKeyIsConsistent(): void
    {
        $state = Personality::defaultPersonalityState();

        $key1 = Personality::personalityKey($state);
        $key2 = Personality::personalityKey($state);

        $this->assertEquals($key1, $key2);
    }

    public function testLoadPersonalityModelReturnsValidStructure(): void
    {
        $model = Personality::loadPersonalityModel();

        $this->assertArrayHasKey('version', $model);
        $this->assertArrayHasKey('current', $model);
        $this->assertArrayHasKey('stats', $model);
        $this->assertArrayHasKey('history', $model);
    }

    public function testSelectPersonalityForRunReturnsValidStructure(): void
    {
        $result = Personality::selectPersonalityForRun();

        $this->assertArrayHasKey('personality_state', $result);
        $this->assertArrayHasKey('personality_key', $result);
        $this->assertArrayHasKey('personality_known', $result);
        $this->assertArrayHasKey('personality_mutations', $result);
    }

    public function testSelectPersonalityForRunWithDrift(): void
    {
        $result = Personality::selectPersonalityForRun(['driftEnabled' => true]);

        $this->assertEquals('PersonalityState', $result['personality_state']['type']);
    }

    public function testUpdatePersonalityStatsWithSuccess(): void
    {
        $state = Personality::defaultPersonalityState();

        $result = Personality::updatePersonalityStats([
            'personalityState' => $state,
            'outcome' => 'success',
            'score' => 0.8,
        ]);

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('stats', $result);
    }

    public function testUpdatePersonalityStatsWithFailure(): void
    {
        $state = Personality::defaultPersonalityState();

        $result = Personality::updatePersonalityStats([
            'personalityState' => $state,
            'outcome' => 'failed',
        ]);

        $this->assertArrayHasKey('key', $result);
    }

    public function testNowIsoReturnsValidFormat(): void
    {
        $iso = Personality::nowIso();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $iso);
    }
}
