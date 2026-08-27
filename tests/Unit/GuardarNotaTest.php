<?php

use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use App\Tools\GuardarNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

/**
 * Build an isolated temp vault under storage/framework/testing (never the real
 * one) and point the notes config at it. Returns the vault root.
 */
function guardarNotaVault(): string
{
    $vault = storage_path('framework/testing/guardar_nota_'.uniqid());
    File::ensureDirectoryExists($vault);
    config(['notes.vault_path' => $vault]);

    return $vault;
}

/** A GuardarNota wired to the counting-free FakeEmbeddingProvider indexer. */
function guardarNotaTool(): GuardarNota
{
    return new GuardarNota(new NotesIndexerService(new FakeEmbeddingProvider, new NoteIndex));
}

it('writes a note and returns the relative path', function () {
    User::create(['name' => 'Tester', 'email' => 't@example.com']);
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute([
        'title' => 'Mi Nota Interesante',
        'body' => "## Colas\nlas colas de laravel",
        'folder' => 'apuntes/desarrollo',
    ]);

    expect($result)->toBe(['status' => 'saved', 'path' => 'apuntes/desarrollo/mi-nota-interesante.md'])
        ->and(File::exists($vault.'/apuntes/desarrollo/mi-nota-interesante.md'))->toBeTrue()
        ->and(NoteIndex::where('path', 'apuntes/desarrollo/mi-nota-interesante.md')->count())->toBe(1);
});

it('rejects a `../` traversal title without creating a file', function () {
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute(['title' => '../escape', 'body' => 'cuerpo']);

    expect($result)->toBe(['error' => 'invalid_path'])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('rejects an absolute title without creating a file', function () {
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute(['title' => '/etc/passwd', 'body' => 'cuerpo']);

    expect($result)->toBe(['error' => 'invalid_path'])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('rejects a `../` traversal folder without creating a file', function () {
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute([
        'title' => 'Nota',
        'body' => 'cuerpo',
        'folder' => '../escape',
    ]);

    expect($result)->toBe(['error' => 'invalid_path'])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('rejects an absolute folder without creating a file', function () {
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute([
        'title' => 'Nota',
        'body' => 'cuerpo',
        'folder' => '/tmp/absolute',
    ]);

    expect($result)->toBe(['error' => 'invalid_path'])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('rejects writing into .obsidian without creating a file', function () {
    $vault = guardarNotaVault();

    $result = guardarNotaTool()->execute([
        'title' => 'Nota',
        'body' => 'cuerpo',
        'folder' => '.obsidian',
    ]);

    expect($result)->toBe(['error' => 'invalid_path'])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('rejects a symlink escape without creating a file', function () {
    $vault = guardarNotaVault();
    $outside = storage_path('framework/testing/guardar_nota_outside_'.uniqid());
    File::ensureDirectoryExists($outside);
    symlink($outside, $vault.'/link');

    $result = guardarNotaTool()->execute([
        'title' => 'Nota',
        'body' => 'cuerpo',
        'folder' => 'link/escape',
    ]);

    expect($result)->toBe(['error' => 'outside_vault'])
        ->and(File::allFiles($vault))->toBeEmpty()
        ->and(File::allFiles($outside))->toBeEmpty();
});

it('throws on a missing title argument', function () {
    guardarNotaTool()->execute(['body' => 'cuerpo']);
})->throws(InvalidArgumentException::class);