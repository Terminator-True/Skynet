<?php

namespace App\Services;

use App\Models\MemoryEntry;
use App\Models\User;
use App\Services\Ollama\EmbeddingProvider;

/**
 * Long-term preference memory (Fase 6).
 *
 * Public API is remember()/recall(). recall() returns recalled contents
 * concatenated as "- content" lines, each trimmed to charCap, ready for
 * injection into the system prompt. Scoped to the single tenant via
 * User::query()->first() (the /chat route has no auth middleware), matching
 * the CheckForNotifications / GoogleToken first-user-wins precedent.
 */
class MemoryService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly MemoryEntry $memoryEntry,
    ) {}

    public function remember(string $content): void
    {
        $user = User::query()->first();

        if ($user === null) {
            return;
        }

        $this->memoryEntry->create([
            'user_id' => $user->id,
            'content' => $content,
            'embedding' => $this->embeddingProvider->embed($content),
        ]);
    }

    /**
     * Return the top-k most similar entries to $query, each trimmed to
     * $charCap, concatenated as "- content" lines.
     */
    public function recall(string $query, int $topK, int $charCap): string
    {
        $user = User::query()->first();

        if ($user === null) {
            return '';
        }

        $queryVector = $this->embeddingProvider->embed($query);

        $scored = [];

        foreach ($this->memoryEntry->newQuery()->where('user_id', $user->id)->get() as $entry) {
            if (! is_array($entry->embedding)) {
                continue;
            }

            $scored[] = [
                'content' => $entry->content,
                'score' => self::cosine($queryVector, $entry->embedding),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $lines = [];

        foreach (array_slice($scored, 0, $topK) as $hit) {
            if ($hit['score'] <= 0.0) {
                continue;
            }

            $lines[] = '- '.mb_substr(trim($hit['content']), 0, $charCap);
        }

        return implode("\n", $lines);
    }

    /**
     * Naive cosine similarity over equal-length float vectors. Returns 0.0 for
     * empty or all-zero vectors (no direction to compare).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $valueA) {
            $valueB = $b[$i] ?? 0.0;
            $dot += $valueA * $valueB;
            $normA += $valueA * $valueA;
        }

        foreach ($b as $valueB) {
            $normB += $valueB * $valueB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
