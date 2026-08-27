<?php

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\BuscarNotas;
use App\Services\Notes\NotesIndexerService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

/** An isolated temp vault (never the real one) with config pointed at it. */
function indexFileVault(): string
{
    $vault = sys_get_temp_dir().'/notes_indexfile_vault_'.uniqid();
    File::ensureDirectoryExists($vault);
    config(['notes.vault_path' => $vault]);

    return $vault;
}

it('indexes a single file and makes it immediately recallable via buscar_notas', function () {
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
    $user = User::factory()->create();
    $vault = indexFileVault();

    $note = $vault.'/mi-nota.md';
    File::put($note, "## Colas\nlas colas de laravel procesan trabajos");

    $embedded = app(NotesIndexerService::class)->indexFile($note, $user);

    expect($embedded)->toBeGreaterThan(0)
        ->and(NoteIndex::where('path', 'mi-nota.md')->count())->toBeGreaterThan(0);

    $results = app(BuscarNotas::class)->search('colas', 3, 500);

    expect($results)->not->toBeEmpty()
        ->and($results[0]['path'])->toBe('mi-nota.md')
        ->and($results[0])->toHaveKeys(['path', 'snippet', 'similarity']);
});

it('re-embeds a single file, replacing prior chunks without duplicates', function () {
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
    $user = User::factory()->create();
    $vault = indexFileVault();
    $service = app(NotesIndexerService::class);

    $note = $vault.'/a.md';
    File::put($note, "## Uno\ncontenido uno\n## Dos\ncontenido dos");

    expect($service->indexFile($note, $user))->toBe(2);
    expect(NoteIndex::where('path', 'a.md')->count())->toBe(2);

    File::put($note, "## Uno\ncontenido uno EDITADO\n## Dos\ncontenido dos");

    expect($service->indexFile($note, $user))->toBe(2);
    expect(NoteIndex::where('path', 'a.md')->count())->toBe(2);
});

it('no-ops without egress when the target file is missing', function () {
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
    $user = User::factory()->create();
    indexFileVault();

    expect(app(NotesIndexerService::class)->indexFile($user->id.'/missing.md', $user))->toBe(0);
});