<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Interface for memory objects that can be decay-scored.
 *
 * Implement this interface on any class that needs lifecycle decay management.
 */
interface DecayableMemory
{
    /**
     * Get the unique identifier.
     */
    public function getId(): string;

    /**
     * Get the tier: 'core', 'working', or 'peripheral'.
     */
    public function getTier(): string;

    /**
     * Get the importance score (0.0 to 1.0).
     */
    public function getImportance(): float;

    /**
     * Get the confidence score (0.0 to 1.0).
     */
    public function getConfidence(): float;

    /**
     * Get creation timestamp in milliseconds.
     */
    public function getCreatedAt(): int;

    /**
     * Get last accessed timestamp in milliseconds.
     */
    public function getLastAccessedAt(): int;

    /**
     * Get access count.
     */
    public function getAccessCount(): int;
}
