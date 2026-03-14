<?php

declare(strict_types=1);

namespace Evolver\Tests;

use Evolver\MemoryCategory;
use PHPUnit\Framework\TestCase;

/**
 * MemoryCategory tests - 6-category classification system.
 */
final class MemoryCategoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Enum Cases
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $cases = MemoryCategory::cases();

        $this->assertCount(6, $cases);

        $values = array_map(fn($c) => $c->value, $cases);
        $this->assertContains('profile', $values);
        $this->assertContains('preferences', $values);
        $this->assertContains('entities', $values);
        $this->assertContains('events', $values);
        $this->assertContains('cases', $values);
        $this->assertContains('patterns', $values);
    }

    public function testValuesReturnsAllStrings(): void
    {
        $values = MemoryCategory::values();

        $this->assertSame(['profile', 'preferences', 'entities', 'events', 'cases', 'patterns'], $values);
    }

    // -------------------------------------------------------------------------
    // Always Merge
    // -------------------------------------------------------------------------

    public function testProfileAlwaysMerges(): void
    {
        $this->assertTrue(MemoryCategory::PROFILE->alwaysMerge());
    }

    public function testOtherCategoriesDoNotAlwaysMerge(): void
    {
        $this->assertFalse(MemoryCategory::PREFERENCES->alwaysMerge());
        $this->assertFalse(MemoryCategory::ENTITIES->alwaysMerge());
        $this->assertFalse(MemoryCategory::EVENTS->alwaysMerge());
        $this->assertFalse(MemoryCategory::CASES->alwaysMerge());
        $this->assertFalse(MemoryCategory::PATTERNS->alwaysMerge());
    }

    // -------------------------------------------------------------------------
    // Supports Merge
    // -------------------------------------------------------------------------

    public function testPreferencesSupportsMerge(): void
    {
        $this->assertTrue(MemoryCategory::PREFERENCES->supportsMerge());
    }

    public function testEntitiesSupportsMerge(): void
    {
        $this->assertTrue(MemoryCategory::ENTITIES->supportsMerge());
    }

    public function testPatternsSupportsMerge(): void
    {
        $this->assertTrue(MemoryCategory::PATTERNS->supportsMerge());
    }

    public function testProfileDoesNotSupportMerge(): void
    {
        // Profile always merges - doesn't go through merge decision
        $this->assertFalse(MemoryCategory::PROFILE->supportsMerge());
    }

    public function testEventsDoesNotSupportMerge(): void
    {
        $this->assertFalse(MemoryCategory::EVENTS->supportsMerge());
    }

    public function testCasesDoesNotSupportMerge(): void
    {
        $this->assertFalse(MemoryCategory::CASES->supportsMerge());
    }

    // -------------------------------------------------------------------------
    // Temporal Versioned
    // -------------------------------------------------------------------------

    public function testPreferencesIsTemporalVersioned(): void
    {
        $this->assertTrue(MemoryCategory::PREFERENCES->isTemporalVersioned());
    }

    public function testEntitiesIsTemporalVersioned(): void
    {
        $this->assertTrue(MemoryCategory::ENTITIES->isTemporalVersioned());
    }

    public function testOtherCategoriesAreNotTemporalVersioned(): void
    {
        $this->assertFalse(MemoryCategory::PROFILE->isTemporalVersioned());
        $this->assertFalse(MemoryCategory::EVENTS->isTemporalVersioned());
        $this->assertFalse(MemoryCategory::CASES->isTemporalVersioned());
        $this->assertFalse(MemoryCategory::PATTERNS->isTemporalVersioned());
    }

    // -------------------------------------------------------------------------
    // Append Only
    // -------------------------------------------------------------------------

    public function testEventsIsAppendOnly(): void
    {
        $this->assertTrue(MemoryCategory::EVENTS->isAppendOnly());
    }

    public function testCasesIsAppendOnly(): void
    {
        $this->assertTrue(MemoryCategory::CASES->isAppendOnly());
    }

    public function testOtherCategoriesAreNotAppendOnly(): void
    {
        $this->assertFalse(MemoryCategory::PROFILE->isAppendOnly());
        $this->assertFalse(MemoryCategory::PREFERENCES->isAppendOnly());
        $this->assertFalse(MemoryCategory::ENTITIES->isAppendOnly());
        $this->assertFalse(MemoryCategory::PATTERNS->isAppendOnly());
    }

    // -------------------------------------------------------------------------
    // Default Importance
    // -------------------------------------------------------------------------

    public function testProfileDefaultImportance(): void
    {
        $this->assertSame(0.9, MemoryCategory::PROFILE->getDefaultImportance());
    }

    public function testPreferencesDefaultImportance(): void
    {
        $this->assertSame(0.8, MemoryCategory::PREFERENCES->getDefaultImportance());
    }

    public function testEntitiesDefaultImportance(): void
    {
        $this->assertSame(0.7, MemoryCategory::ENTITIES->getDefaultImportance());
    }

    public function testEventsDefaultImportance(): void
    {
        $this->assertSame(0.6, MemoryCategory::EVENTS->getDefaultImportance());
    }

    public function testCasesDefaultImportance(): void
    {
        $this->assertSame(0.8, MemoryCategory::CASES->getDefaultImportance());
    }

    public function testPatternsDefaultImportance(): void
    {
        $this->assertSame(0.85, MemoryCategory::PATTERNS->getDefaultImportance());
    }

    // -------------------------------------------------------------------------
    // To Store Category
    // -------------------------------------------------------------------------

    public function testProfileMapsToFact(): void
    {
        $this->assertSame('fact', MemoryCategory::PROFILE->toStoreCategory());
    }

    public function testPreferencesMapsToPreference(): void
    {
        $this->assertSame('preference', MemoryCategory::PREFERENCES->toStoreCategory());
    }

    public function testEntitiesMapsToEntity(): void
    {
        $this->assertSame('entity', MemoryCategory::ENTITIES->toStoreCategory());
    }

    public function testEventsMapsToDecision(): void
    {
        $this->assertSame('decision', MemoryCategory::EVENTS->toStoreCategory());
    }

    public function testCasesMapsToFact(): void
    {
        $this->assertSame('fact', MemoryCategory::CASES->toStoreCategory());
    }

    public function testPatternsMapsToOther(): void
    {
        $this->assertSame('other', MemoryCategory::PATTERNS->toStoreCategory());
    }

    // -------------------------------------------------------------------------
    // Try From String
    // -------------------------------------------------------------------------

    public function testTryFromStringValid(): void
    {
        $this->assertSame(MemoryCategory::PROFILE, MemoryCategory::tryFromString('profile'));
        $this->assertSame(MemoryCategory::PREFERENCES, MemoryCategory::tryFromString('preferences'));
    }

    public function testTryFromStringCaseInsensitive(): void
    {
        $this->assertSame(MemoryCategory::PROFILE, MemoryCategory::tryFromString('PROFILE'));
        $this->assertSame(MemoryCategory::PREFERENCES, MemoryCategory::tryFromString('Preferences'));
    }

    public function testTryFromStringTrimWhitespace(): void
    {
        $this->assertSame(MemoryCategory::PROFILE, MemoryCategory::tryFromString('  profile  '));
    }

    public function testTryFromStringInvalidReturnsNull(): void
    {
        $this->assertNull(MemoryCategory::tryFromString('invalid'));
        $this->assertNull(MemoryCategory::tryFromString(''));
    }
}
