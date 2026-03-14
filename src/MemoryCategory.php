<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Memory category enum - 6-category classification system.
 *
 * UserMemory: profile, preferences, entities, events
 * AgentMemory: cases, patterns
 */
enum MemoryCategory: string
{
    case PROFILE = 'profile';
    case PREFERENCES = 'preferences';
    case ENTITIES = 'entities';
    case EVENTS = 'events';
    case CASES = 'cases';
    case PATTERNS = 'patterns';

    /**
     * Get all category values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if this category always merges (skip dedup).
     */
    public function alwaysMerge(): bool
    {
        return $this === self::PROFILE;
    }

    /**
     * Check if this category supports MERGE decision from LLM dedup.
     */
    public function supportsMerge(): bool
    {
        return in_array($this, [
            self::PREFERENCES,
            self::ENTITIES,
            self::PATTERNS,
        ], true);
    }

    /**
     * Check if this category supports temporal versioning.
     */
    public function isTemporalVersioned(): bool
    {
        return in_array($this, [
            self::PREFERENCES,
            self::ENTITIES,
        ], true);
    }

    /**
     * Check if this category is append-only (CREATE or SKIP only).
     */
    public function isAppendOnly(): bool
    {
        return in_array($this, [
            self::EVENTS,
            self::CASES,
        ], true);
    }

    /**
     * Get default importance for this category.
     */
    public function getDefaultImportance(): float
    {
        return match ($this) {
            self::PROFILE => 0.9,
            self::PREFERENCES => 0.8,
            self::ENTITIES => 0.7,
            self::EVENTS => 0.6,
            self::CASES => 0.8,
            self::PATTERNS => 0.85,
        };
    }

    /**
     * Map to existing store category for backward compatibility.
     */
    public function toStoreCategory(): string
    {
        return match ($this) {
            self::PROFILE => 'fact',
            self::PREFERENCES => 'preference',
            self::ENTITIES => 'entity',
            self::EVENTS => 'decision',
            self::CASES => 'fact',
            self::PATTERNS => 'other',
        };
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        $normalized = strtolower(trim($value));
        return self::tryFrom($normalized);
    }
}
