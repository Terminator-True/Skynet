<?php

namespace Tests\Support;

use App\Services\Ollama\EmbeddingProvider;

/**
 * Deterministic sparse bigram-feature embedding for offline tests.
 *
 * Each lowercase alphanumeric bigram maps to a fixed slot in a fixed-size
 * vector. Text sharing bigrams lands on overlapping slots, so naive cosine
 * similarity ranks lexically-related entries first (store "prefiere café",
 * recall "café" → the stored entry wins). Zero HTTP by construction.
 */
class FakeEmbeddingProvider implements EmbeddingProvider
{
    private const DIMENSION = 64;

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSION, 0.0);

        $normalized = mb_strtolower(preg_replace('/[^a-z0-9áéíóúüñ ]/u', '', $text) ?? '');

        foreach (preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            for ($i = 0, $len = mb_strlen($word); $i < $len - 1; $i++) {
                $bigram = mb_substr($word, $i, 2);
                $index = crc32($bigram) % self::DIMENSION;
                $vector[$index] += 1.0;
            }
        }

        return $vector;
    }
}
