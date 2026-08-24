<?php

namespace App\Services;

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Ollama\EmbeddingProvider;

/**
 * Read-only local notes recall (Slice 2, REQ buscar_notas).
 *
 * Embeds a {tema} query and returns cosine top-k excerpts over the derived
 * notes_index cache. Mirrors MemoryService::recall but returns structured
 * {path, snippet, similarity} hits. Strictly read-only: never writes the
 * vault or the notes_index table.
 */
class BuscarNotas
{
    public function __construct(
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly NoteIndex $noteIndex,
    ) {}

    /**
     * Return the top-k most similar indexed chunks to $tema, each trimmed to
     * $charCap. Empty when there is no first user or no indexed rows.
     *
     * @return list<array{path: string, snippet: string, similarity: float}>
     */
    public function search(string $tema, int $topK, int $charCap): array
    {
        $user = User::query()->first();

        if ($user === null) {
            return [];
        }

        $queryVector = $this->embeddingProvider->embed($tema);

        $scored = [];

        foreach ($this->noteIndex->newQuery()->where('user_id', $user->id)->get() as $row) {
            if (! is_array($row->embedding)) {
                continue;
            }

            $scored[] = [
                'path' => $row->path,
                'snippet' => $row->content,
                'similarity' => MemoryService::cosine($queryVector, $row->embedding),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        $hits = [];

        foreach (array_slice($scored, 0, $topK) as $hit) {
            if ($hit['similarity'] <= 0.0) {
                continue;
            }

            $hits[] = [
                'path' => $hit['path'],
                'snippet' => mb_substr(trim($hit['snippet']), 0, $charCap),
                'similarity' => $hit['similarity'],
            ];
        }

        return $hits;
    }
}
