<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Null embedder for testing and fallback.
 *
 * Returns null for all embeddings, indicating no embedding capability.
 */
final class NullEmbedder implements EmbedderInterface
{
    public function embed(string $text): ?array
    {
        return null;
    }

    public function getDimension(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
