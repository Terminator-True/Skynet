<?php

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\BuscarNotas;
use App\Services\Notes\NotesIndexerService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

/** Build an isolated temp vault (never the real one) from rel => content. */
function buscarNotasVault(array $files): string
{
    $vault = sys_get_temp_dir().'/notas_vault_'.uniqid();

    foreach ($files as $rel => $content) {
        File::ensureDirectoryExists(dirname($vault.'/'.$rel));
        File::put($vault.'/'.$rel, $content);
    }

    return $vault;
}

/** Index a temp vault under the given user via NotesIndexerService. */
function buscarNotasSeed(string $vault, User $user): void
{
    config(['notes.vault_path' => $vault]);
    (new NotesIndexerService(
        new FakeEmbeddingProvider,
        new NoteIndex,
    ))->index($user);
}

it('returns the top-ranked relevant excerpt for a matching query', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $vault = buscarNotasVault([
        'colas.md' => "## Colas\nlas colas de laravel procesan trabajos",
        'cafe.md' => "## Cafe\nprefiero el cafe con leche",
    ]);
    buscarNotasSeed($vault, $user);

    $hits = (new BuscarNotas(new FakeEmbeddingProvider, new NoteIndex))
        ->search('colas', 3, 500);

    expect($hits)->not->toBeEmpty()
        ->and($hits[0]['path'])->toBe('colas.md')
        ->and($hits[0]['similarity'])->toBeGreaterThan($hits[1]['similarity'])
        ->and($hits[0])->toHaveKeys(['path', 'snippet', 'similarity']);

    Http::assertNothingSent();
});

it('returns no results on an empty index', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();

    $hits = (new BuscarNotas(new FakeEmbeddingProvider, new NoteIndex))
        ->search('cualquier cosa', 3, 500);

    expect($hits)->toBe([]);

    Http::assertNothingSent();
});

it('respects top-k and char-cap bounds', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $long = str_repeat('palabra buscada ', 40); // > 500 chars of shared bigrams
    $vault = buscarNotasVault([
        'a.md' => "## Uno\n$long",
        'b.md' => "## Dos\n$long",
        'c.md' => "## Tres\n$long",
    ]);
    buscarNotasSeed($vault, $user);

    $hits = (new BuscarNotas(new FakeEmbeddingProvider, new NoteIndex))
        ->search('palabra buscada', 2, 20);

    expect(count($hits))->toBe(2);

    foreach ($hits as $hit) {
        expect(mb_strlen($hit['snippet']))->toBeLessThanOrEqual(20);
    }
});

it('is strictly read-only: never writes the index or the vault', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $vault = buscarNotasVault(['nota.md' => "## Nota\ncontenido de la nota"]);
    buscarNotasSeed($vault, $user);

    $beforeRows = NoteIndex::count();
    $beforeFiles = count(File::allFiles($vault));

    (new BuscarNotas(new FakeEmbeddingProvider, new NoteIndex))->search('nota', 3, 500);

    expect(NoteIndex::count())->toBe($beforeRows)
        ->and(count(File::allFiles($vault)))->toBe($beforeFiles);

    Http::assertNothingSent();
});
