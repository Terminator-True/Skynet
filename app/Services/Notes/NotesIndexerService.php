<?php

namespace App\Services\Notes;

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Support\Facades\File;

/**
 * Incremental local Obsidian vault indexer (roadmap §8 local-only).
 *
 * Walks the vault root for *.md files via the File facade (never shell), chunks
 * each note (## header seam + char fallback), embeds chunks via the
 * EmbeddingProvider seam, and persists to the derived notes_index cache. Only
 * files whose content hash changed are re-embedded; deleted files' rows are
 * removed; no-ops when the vault is absent or there is no user.
 */
class NotesIndexerService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly NoteIndex $noteIndex,
    ) {}

    /** Index the vault for the given (or first) user; returns chunks embedded. */
    public function index(?User $user = null): int
    {
        $vault = config('notes.vault_path');

        if (! is_string($vault) || ! File::isDirectory($vault)) {
            return 0;
        }

        $user ??= User::query()->first();

        if ($user === null) {
            return 0;
        }

        $seen = [];
        $embedded = 0;

        foreach ($this->scan($vault) as $absolute) {
            $relative = $this->relativePath($vault, $absolute);
            $seen[] = $relative;

            $content = File::get($absolute);
            $hash = self::contentHash($content);

            $existing = $this->noteIndex->newQuery()
                ->where('user_id', $user->id)
                ->where('path', $relative)
                ->first();

            if ($existing !== null && $existing->content_hash === $hash) {
                continue; // unchanged — skip re-embedding
            }

            $this->noteIndex->newQuery()
                ->where('user_id', $user->id)
                ->where('path', $relative)
                ->delete();

            foreach (self::chunk($content, config('notes.chunk_chars')) as $index => $chunkContent) {
                $this->noteIndex->create([
                    'user_id' => $user->id,
                    'path' => $relative,
                    'relative_path' => $relative,
                    'chunk_index' => $index,
                    'content' => $chunkContent,
                    'embedding' => $this->embeddingProvider->embed($chunkContent),
                    'content_hash' => $hash,
                    'updated_at' => now(),
                ]);

                $embedded++;
            }
        }

        $this->noteIndex->newQuery()
            ->where('user_id', $user->id)
            ->whereNotIn('path', $seen)
            ->delete();

        return $embedded;
    }

    /**
     * Index a single note file, replacing any prior rows for that path.
     *
     * Deletes the existing chunks for the note and re-embeds its current
     * content via the same chunk/hash/embed pipeline as index(). Used to
     * refresh the index immediately after guardar_nota saves a note (D6) so it
     * is recallable without waiting for the 15-minute job. Returns chunks
     * embedded; no-ops when the file is absent, outside the vault, or there is
     * no user.
     */
    public function indexFile(string $absolute, ?User $user = null): int
    {
        $vault = config('notes.vault_path');

        if (! is_string($vault) || ! File::isFile($absolute)) {
            return 0;
        }

        $root = realpath($vault);

        if ($root === false || ! $this->isWithin($root, $absolute)) {
            return 0;
        }

        $user ??= User::query()->first();

        if ($user === null) {
            return 0;
        }

        $relative = $this->relativePath($root, $absolute);
        $content = File::get($absolute);
        $hash = self::contentHash($content);

        $this->noteIndex->newQuery()
            ->where('user_id', $user->id)
            ->where('path', $relative)
            ->delete();

        $embedded = 0;

        foreach (self::chunk($content, config('notes.chunk_chars')) as $index => $chunkContent) {
            $this->noteIndex->create([
                'user_id' => $user->id,
                'path' => $relative,
                'relative_path' => $relative,
                'chunk_index' => $index,
                'content' => $chunkContent,
                'embedding' => $this->embeddingProvider->embed($chunkContent),
                'content_hash' => $hash,
                'updated_at' => now(),
            ]);

            $embedded++;
        }

        return $embedded;
    }

    /**
     * Split a note on `## ` headers (folding any H1 preamble into the first
     * section), falling back to char-bounded slices for headerless or oversized
     * content. One embedding per chunk.
     *
     * @return list<string>
     */
    public static function chunk(string $content, int $maxChars): array
    {
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $sections = [];
        $preamble = [];
        $current = null;

        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current === null) {
                    $current = array_merge($preamble, [$line]);
                } else {
                    $sections[] = implode("\n", $current);
                    $current = [$line];
                }
            } elseif ($current === null) {
                $preamble[] = $line;
            } else {
                $current[] = $line;
            }
        }

        if ($current !== null) {
            $sections[] = implode("\n", $current);
        } elseif ($preamble !== []) {
            $sections[] = implode("\n", $preamble);
        }

        $chunks = [];

        foreach ($sections as $section) {
            if (mb_strlen($section) <= $maxChars) {
                $chunks[] = $section;

                continue;
            }

            foreach (self::charSlices($section, $maxChars) as $slice) {
                $chunks[] = $slice;
            }
        }

        return $chunks;
    }

    /** Deterministic sha256 fingerprint shared across a file's chunks (D2). */
    public static function contentHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Recursively collect *.md files, skipping .obsidian/ and non-markdown.
     *
     * @return list<string>
     */
    private function scan(string $vault): array
    {
        $files = [];

        foreach (File::allFiles($vault) as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.md')) {
                continue;
            }

            if (str_contains($path, DIRECTORY_SEPARATOR.'.obsidian'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /** Path relative to the vault root, preserved verbatim (spaces/accents). */
    private function relativePath(string $vault, string $absolute): string
    {
        return ltrim(str_replace($vault, '', $absolute), '/\\');
    }

    /** True when $absolute is inside (or equal to) the vault $root. */
    private function isWithin(string $root, string $absolute): bool
    {
        $root = rtrim($root, '/\\');

        return $absolute === $root || str_starts_with($absolute, $root.'/');
    }

    /** @return list<string> */
    private static function charSlices(string $text, int $maxChars): array
    {
        $slices = [];

        for ($offset = 0, $length = mb_strlen($text); $offset < $length; $offset += $maxChars) {
            $slices[] = mb_substr($text, $offset, $maxChars);
        }

        return $slices;
    }
}
