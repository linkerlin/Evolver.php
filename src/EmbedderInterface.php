<?php

declare(strict_types=1);

namespace Evolver;

/**
 * Interface for embedding providers.
 *
 * Implementations generate vector embeddings for text,
 * which can be used for semantic similarity search.
 */
interface EmbedderInterface
{
    /**
     * Generate a vector embedding for the given text.
     *
     * @param string $text The text to embed
     * @return float[]|null The embedding vector, or null if embedding failed
     */
    public function embed(string $text): ?array;

    /**
     * Get the dimension of the embedding vectors.
     *
     * @return int The number of dimensions in the embedding vectors
     */
    public function getDimension(): int;

    /**
     * Check if the embedder is available and configured.
     *
     * @return bool True if embeddings can be generated
     */
    public function isAvailable(): bool;
}
