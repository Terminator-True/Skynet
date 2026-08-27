<?php

namespace App\Tools;

use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use App\Tools\Contracts\Tool;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Vault-scoped Obsidian write tool (REQ guardar_nota, D4/D5).
 *
 * Writes an .md note under the vault root (realpath of notes.vault_path),
 * slugging the title for the filename. The optional {folder} is a relative
 * sub-path with no leading slash and no `..` segments. Defense-in-depth (D5):
 * rejects `..` and absolute paths in both title and folder, rejects any path
 * segment equal to `.obsidian`, and refuses to write through a symlink that
 * resolves outside the vault root — so a note can never be created outside the
 * vault. After a successful write the note is re-indexed immediately (D6) so
 * buscar_notas can recall it without waiting for the 15-minute job.
 */
class GuardarNota implements Tool
{
    public function __construct(
        private readonly NotesIndexerService $notesIndexer,
    ) {}

    public function name(): string
    {
        return 'guardar_nota';
    }

    public function description(): string
    {
        return 'Creates a new Markdown note in the user\'s local Obsidian vault. '
            .'Call ONLY after the user has explicitly agreed to save the note (for example, '
            .'they said yes to "¿Quieres que apunte en Obsidian lo que has aprendido?"). '
            .'Never call it on ordinary chat, questions, or without explicit consent. '
            .'Provide a short {title} and the Markdown {body}; use {folder} only for a relative '
            .'sub-folder (e.g. "projects/php"), never an absolute path.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short note title used to derive the filename',
                ],
                'body' => [
                    'type' => 'string',
                    'description' => 'Markdown body of the note',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Optional relative sub-folder within the vault (no leading / and no "..")',
                ],
            ],
            'required' => ['title', 'body'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status?: string, path?: string, error?: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['title']) || ! is_string($args['title']) || trim($args['title']) === '') {
            throw new InvalidArgumentException('guardar_nota requires a non-empty string title argument.');
        }

        if (! isset($args['body']) || ! is_string($args['body']) || trim($args['body']) === '') {
            throw new InvalidArgumentException('guardar_nota requires a non-empty string body argument.');
        }

        $title = trim($args['title']);

        // Reject path-traversal and absolute constructs in the raw title before
        // slugging, so a hostile title can never smuggle `..`/separators.
        if (str_contains($title, '/') || str_contains($title, '\\') || str_contains($title, '..')) {
            return ['error' => 'invalid_path'];
        }

        $folder = $this->normalizeFolder($args['folder'] ?? null);

        if ($folder === false) {
            return ['error' => 'invalid_path'];
        }

        $root = realpath((string) config('notes.vault_path'));

        if ($root === false || ! File::isDirectory($root)) {
            return ['error' => 'invalid_path'];
        }

        $file = Str::slug($title).'.md';
        $relative = $folder === '' ? $file : $folder.'/'.$file;
        $candidate = $root.'/'.$relative;
        $dirname = dirname($candidate);

        // Symlink-escape guard: any EXISTING ancestor that resolves outside the
        // root (via realpath following symlinks) rejects the write before we
        // create a single directory or file.
        if (! $this->resolvesWithinRoot($root, $dirname)) {
            return ['error' => 'outside_vault'];
        }

        File::ensureDirectoryExists($dirname);

        // Re-verify after directory creation: a freshly-created chain is plain,
        // but this is the authoritative containment check for the final dirname.
        if (! $this->resolvesWithinRoot($root, $dirname)) {
            return ['error' => 'outside_vault'];
        }

        File::put($candidate, $args['body']);

        $this->notesIndexer->indexFile($candidate, User::query()->first());

        return ['status' => 'saved', 'path' => $relative];
    }

    /**
     * Normalise the optional relative folder; returns false on any traversal,
     * absolute, empty-segment, or reserved (.obsidian) construct.
     *
     * @return string|false
     */
    private function normalizeFolder(mixed $folder): string|false
    {
        if ($folder === null) {
            return '';
        }

        if (! is_string($folder)) {
            return false;
        }

        $folder = trim(str_replace('\\', '/', $folder));

        if ($folder === '') {
            return '';
        }

        if (str_starts_with($folder, '/') || str_starts_with($folder, '..')) {
            return false;
        }

        foreach (explode('/', $folder) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment === '.obsidian') {
                return false;
            }
        }

        return $folder;
    }

    /**
     * True when the deepest existing ancestor of $path resolves (following
     * symlinks) to a directory strictly inside $root, i.e. realpath is a child
     * of the vault root.
     */
    private function resolvesWithinRoot(string $root, string $path): bool
    {
        $probe = rtrim($root, '/\\');

        foreach ($this->relativeSegments($root, $path) as $segment) {
            $probe .= '/'.$segment;

            if (! File::exists($probe)) {
                continue;
            }

            $resolved = realpath($probe);

            if ($resolved === false || ! $this->isWithin($root, $resolved)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string> path segments of $path relative to $root
     */
    private function relativeSegments(string $root, string $path): array
    {
        $relative = ltrim(substr($path, strlen(rtrim($root, '/\\'))), '/\\');

        return $relative === '' ? [] : explode('/', $relative);
    }

    private function isWithin(string $root, string $resolved): bool
    {
        $root = rtrim($root, '/\\');

        return $resolved === $root || str_starts_with($resolved, $root.'/');
    }
}