<?php

use App\Models\User;
use App\Services\ChatOrchestrator;
use App\Services\MemoryService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

/**
 * Prompt-injection feature test: a remembered preference from a prior session
 * must appear in a later turn's system prompt. OllamaClient is mocked via
 * Http::fake so we can capture the system message sent on the wire.
 */
beforeEach(function () {
    $this->app->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
    config(['ollama.base_url' => 'http://ollama.test']);
});

it('injects a recalled preference into the system prompt', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    app(MemoryService::class)->remember('prefiere café');

    Http::fake([
        '*/api/chat' => Http::response([
            'message' => ['role' => 'assistant', 'content' => 'Perfecto.'],
        ]),
    ]);

    app(ChatOrchestrator::class)->handle('prefiero café');

    Http::assertSent(function ($request) {
        $system = json_decode($request->body(), true)['messages'][0]['content'];

        return str_contains($system, 'Remembered preferences:')
            && str_contains($system, '- prefiere café');
    });
});

it('does not add a remembered section when no memories exist', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    Http::fake([
        '*/api/chat' => Http::response([
            'message' => ['role' => 'assistant', 'content' => 'Entendido.'],
        ]),
    ]);

    app(ChatOrchestrator::class)->handle('¿qué hora es?');

    Http::assertSent(function ($request) {
        $system = json_decode($request->body(), true)['messages'][0]['content'];

        return ! str_contains($system, 'Remembered preferences:');
    });
});
