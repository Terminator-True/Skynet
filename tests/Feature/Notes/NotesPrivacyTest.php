<?php

use App\Jobs\IndexObsidianNotes;
use App\Models\NoteIndex;
use App\Models\User;
use App\Services\Notes\NotesIndexerService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeEmbeddingProvider;

it('gitignores the local Obsidian vault so personal notes never commit', function () {
    $output = [];
    $status = 0;
    exec('git check-ignore main_obsidian/main/Readme.md', $output, $status);

    expect($status)->toBe(0)
        ->and(implode("\n", $output))->toContain('main_obsidian');

    // Nothing under the vault is tracked by git.
    exec('git ls-files main_obsidian', $tracked);
    expect($tracked)->toBe([]);
});

it('runs notes:index against the configured vault', function () {
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $vault = sys_get_temp_dir().'/notes_vault_'.uniqid();
    File::ensureDirectoryExists($vault);
    File::put($vault.'/nota.md', "## Solo\nmarkdown local");
    config(['notes.vault_path' => $vault]);
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    $this->artisan('notes:index')
        ->expectsOutputToContain('Indexed 1 chunks.')
        ->assertSuccessful();

    expect(NoteIndex::count())->toBe(1);
});

it('warns and no-ops when notes:index points at a missing vault', function () {
    config(['notes.vault_path' => sys_get_temp_dir().'/no_vault_'.uniqid()]);

    $this->artisan('notes:index')
        ->expectsOutputToContain('Vault path not found')
        ->assertSuccessful();

    expect(NoteIndex::count())->toBe(0);
});

it('resolves the first user and indexes via the job', function () {
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $vault = sys_get_temp_dir().'/notes_vault_'.uniqid();
    File::ensureDirectoryExists($vault);
    File::put($vault.'/nota.md', "## Colas\nqueue content");
    config(['notes.vault_path' => $vault]);
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    (new IndexObsidianNotes)->handle(app(NotesIndexerService::class));

    expect(NoteIndex::count())->toBe(1);
});

it('registers the indexer on the 15-minute schedule', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('IndexObsidianNotes')
        ->assertSuccessful();
});
