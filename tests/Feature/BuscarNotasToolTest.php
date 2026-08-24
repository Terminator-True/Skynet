<?php

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use App\Services\Ollama\EmbeddingProvider;
use App\Tools\BuscarNotas;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

it('registers buscar_notas in the container-bound registry with a scoped schema', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('buscar_notas'))->toBeTrue()
        ->and($registry->get('buscar_notas'))->toBeInstanceOf(BuscarNotas::class);

    $schema = $registry->get('buscar_notas')->schema();
    expect($schema['type'])->toBe('object')
        ->and($schema['properties']['tema']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['tema']);
});

it('returns structured excerpts when the tool runs', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $vault = sys_get_temp_dir().'/tool_vault_'.uniqid();
    File::ensureDirectoryExists($vault);
    File::put($vault.'/nota.md', "## Colas\nlas colas de laravel");
    config(['notes.vault_path' => $vault]);
    (new NotesIndexerService(new FakeEmbeddingProvider, new NoteIndex))->index($user);

    $tool = app(ToolRegistry::class)->get('buscar_notas');
    $result = $tool->execute(['tema' => 'colas']);

    expect($result)->toHaveKey('results')
        ->and($result['results'])->not->toBeEmpty()
        ->and($result['results'][0]['path'])->toBe('nota.md')
        ->and($result['results'][0])->toHaveKeys(['path', 'snippet', 'similarity']);

    Http::assertNothingSent();
});

it('is read-only: searching never writes the index or vault', function () {
    Http::fake();
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $vault = sys_get_temp_dir().'/tool_vault_'.uniqid();
    File::ensureDirectoryExists($vault);
    File::put($vault.'/nota.md', "## Colas\nlas colas de laravel");
    config(['notes.vault_path' => $vault]);
    (new NotesIndexerService(new FakeEmbeddingProvider, new NoteIndex))->index($user);

    $beforeRows = NoteIndex::count();
    $beforeFiles = count(File::allFiles($vault));

    $tool = app(ToolRegistry::class)->get('buscar_notas');
    $tool->execute(['tema' => 'colas']);

    expect(NoteIndex::count())->toBe($beforeRows)
        ->and(count(File::allFiles($vault)))->toBe($beforeFiles);

    Http::assertNothingSent();
});

it('rejects a blank or missing tema argument', function () {
    $tool = app(ToolRegistry::class)->get('buscar_notas');

    $tool->execute(['tema' => '   ']);
})->throws(InvalidArgumentException::class);
