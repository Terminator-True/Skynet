<?php

namespace App\Services\Ollama;

/**
 * Transport seam for computing text embeddings.
 *
 * Mirrors the reader-seam convention: OllamaEmbedClient is the live HTTP
 * transport, FakeEmbeddingProvider swaps in for offline tests with zero
 * egress (roadmap §8 local-only).
 */
interface EmbeddingProvider
{
    /**
     * Compute a fixed-length float vector for the given text.
     *
     * @return list<float>
     */
    public function embed(string $text): array;
}
