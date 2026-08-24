<?php

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

/**
 * Counting embed seam: deterministic and zero egress, records embed calls.
 */
final class CountingEmbedder implements EmbeddingProvider
{
    public int $calls = 0;

    public function embed(string $text): array
    {
        $this->calls++;

        return (new FakeEmbeddingProvider)->embed($text);
    }
}

/** Build an isolated temp vault (never the real one) from rel => content. */
function notesVault(array $files): string
{
    $vault = sys_get_temp_dir().'/notes_vault_'.uniqid();

    foreach ($files as $rel => $content) {
        File::ensureDirectoryExists(dirname($vault.'/'.$rel));
        File::put($vault.'/'.$rel, $content);
    }

    return $vault;
}

function notesIndexer(CountingEmbedder $embedder, string $vault): NotesIndexerService
{
    config(['notes.vault_path' => $vault]);
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    return new NotesIndexerService($embedder, new NoteIndex);
}

it('indexes a new note and makes it searchable', function () {
    Http::fake();
    $spy = new CountingEmbedder;

    $embedded = notesIndexer($spy, notesVault(['laravel.md' => "## Colas\nqueues\n## Caché\nstores"]))->index();

    expect($embedded)->toBe(2)
        ->and(NoteIndex::count())->toBe(2)
        ->and($spy->calls)->toBe(2)
        ->and(NoteIndex::first()->embedding)->toBeArray();
});

it('re-embeds only a changed file on re-index (per-file content_hash)', function () {
    Http::fake();
    $vault = notesVault(['a.md' => "## Uno\nc\n## Dos\nx", 'b.md' => "## Tres\nb"]);
    $spy = new CountingEmbedder;
    $service = notesIndexer($spy, $vault);

    $service->index();
    expect(NoteIndex::count())->toBe(3);
    $spy->calls = 0;

    File::put($vault.'/a.md', "## Uno\nc EDITADO\n## Dos\nx");
    $service->index();

    // Only a.md re-embedded (2 chunks); unchanged b.md skipped; no dupes.
    expect($spy->calls)->toBe(2)
        ->and(NoteIndex::count())->toBe(3);

    $rows = NoteIndex::where('path', 'a.md')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('content_hash')->unique())->toHaveCount(1)
        ->and($rows->first()->content)->toContain('EDITADO');
});

it('removes rows for deleted files', function () {
    Http::fake();
    $vault = notesVault(['a.md' => "## Uno\nc", 'b.md' => "## Dos\nb"]);
    $service = notesIndexer(new CountingEmbedder, $vault);

    $service->index();
    expect(NoteIndex::count())->toBe(2);

    File::delete($vault.'/b.md');
    $service->index();

    expect(NoteIndex::where('path', 'b.md')->count())->toBe(0)
        ->and(NoteIndex::where('path', 'a.md')->count())->toBe(1);
});

it('filters out .obsidian, images and canvas files', function () {
    Http::fake();
    $spy = new CountingEmbedder;
    $vault = notesVault([
        'nota.md' => "## Solo\nmd",
        'imagen.png' => 'fake',
        'diagrama.canvas' => '{}',
        '.obsidian/config.json' => '{}',
    ]);

    notesIndexer($spy, $vault)->index();

    expect($spy->calls)->toBe(1)
        ->and(NoteIndex::pluck('path')->all())->toBe(['nota.md']);
});

it('no-ops without error or egress when the vault is absent', function () {
    Http::fake();
    config(['notes.vault_path' => sys_get_temp_dir().'/does_not_exist_'.uniqid()]);
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    $service = new NotesIndexerService(new FakeEmbeddingProvider, new NoteIndex);

    expect($service->index())->toBe(0)
        ->and(NoteIndex::count())->toBe(0);

    Http::assertNothingSent();
});
