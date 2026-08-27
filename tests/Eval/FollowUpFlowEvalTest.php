<?php

namespace Tests\Eval;

use App\Models\User;
use App\Services\ChatOrchestrator;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

/**
 * Slice 3 (Prompt) eval group: the follow-up offer -> consent -> save flow
 * (spec req 6/7). Unlike ToolCallingEvalTest these are DETERMINISTIC offline
 * cases (scripted Ollama via Http::fake), so the eval group gate runs them
 * without hardware. They assert the prompt carries the flow, that a decline
 * writes no note, and that an explicit accept drives guardar_nota with a
 * well-formed Markdown note (Str::slug filename + YAML frontmatter).
 */

/** Scripted Ollama /api/chat responses. */
function followUpScriptedOllama(array $messages): void
{
    Http::fake(['*/api/chat' => Http::sequence($messages)]);
}

function followUpAssistantContent(string $content): array
{
    return ['message' => ['role' => 'assistant', 'content' => $content]];
}

function followUpAssistantToolCall(string $name, array $args): array
{
    return ['message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [['function' => ['name' => $name, 'arguments' => $args]]],
    ]];
}

/** Point the notes config at an isolated temp vault and return its root. */
function followUpVault(): string
{
    $vault = storage_path('framework/testing/follow_up_flow_'.uniqid());
    File::ensureDirectoryExists($vault);
    config(['notes.vault_path' => $vault]);

    return $vault;
}

/** Offline embedding seam so memory recall + indexFile never touch Ollama. */
function followUpOfflineEmbed(): void
{
    app()->bind(EmbeddingProvider::class, fn (): FakeEmbeddingProvider => new FakeEmbeddingProvider);
}

it('injects the follow-up flow and note-generation guidance into the system prompt', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    followUpOfflineEmbed();
    followUpScriptedOllama([followUpAssistantContent('ok')]);

    app(ChatOrchestrator::class)->handle('what is the weather?');

    Http::assertSent(function ($request): bool {
        $system = json_decode($request->body(), true)['messages'][0]['content'];

        return str_contains($system, '¿Quieres saber algo más?')
            && str_contains($system, '¿Quieres que apunte en Obsidian lo que has aprendido?')
            && str_contains($system, 'guardar_nota')
            && str_contains($system, 'Str::slug')
            && str_contains($system, 'frontmatter')
            && str_contains($system, 'explicitly agreed to the save question');
    });
});

it('writes no note when the user declines the save question', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    followUpOfflineEmbed();
    $vault = followUpVault();
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    // The model answers "no" in plain text and never calls guardar_nota.
    followUpScriptedOllama([followUpAssistantContent('No hay problema, no lo guardo.')]);

    $result = app(ChatOrchestrator::class)->handle('no, gracias');

    expect($result->toolCalls)->toBe([])
        ->and(File::allFiles($vault))->toBeEmpty();
});

it('calls guardar_nota with a well-formed note when the user accepts the save question', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    followUpOfflineEmbed();
    $vault = followUpVault();
    User::create(['name' => 'Tester', 'email' => 't@example.com']);

    $body = "---\ntitle: Aprendizaje Laravel\ndate: 2026-08-27\ntags: [laravel, colas]\n---\n\n## Colas de Laravel\nLas colas usan Redis de forma asíncrona.";

    followUpScriptedOllama([
        followUpAssistantToolCall('guardar_nota', ['title' => 'Aprendizaje Laravel', 'body' => $body, 'folder' => '']),
        followUpAssistantContent('Listo, lo guardé en tus notas.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('sí, apuntalo');

    $save = collect($result->toolCalls)->firstWhere('name', 'guardar_nota');
    expect($save)->not->toBeNull()
        ->and($save['arguments']['title'])->toBe('Aprendizaje Laravel')
        ->and($save['arguments']['body'])->toContain('---')
        ->and($save['arguments']['body'])->toContain('title:')
        ->and($save['arguments']['body'])->toContain('date:')
        ->and($save['arguments']['body'])->toContain('tags:')
        ->and(File::exists($vault.'/aprendizaje-laravel.md'))->toBeTrue();
});
