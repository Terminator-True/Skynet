<?php

use App\Models\User;
use App\Services\ChatOrchestrator;
use App\Services\ConversationService;
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

it('persists both turns and prepends the prior thread on the next request', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    Http::fake(['*/api/chat' => Http::sequence([
        ['message' => ['role' => 'assistant', 'content' => 'Primera respuesta.']],
        ['message' => ['role' => 'assistant', 'content' => 'Segunda respuesta.']],
    ])]);

    app(ChatOrchestrator::class)->handle('hola', 'ses-1');
    app(ChatOrchestrator::class)->handle('sigue', 'ses-1');

    $requests = Http::recorded();
    $second = $requests[1][0];
    $messages = collect(json_decode($second->body(), true)['messages'])
        ->reject(fn (array $m): bool => $m['role'] === 'system')
        ->values();

    // Prior turn (user + assistant) is prepended before the new user message.
    expect($messages->pluck('content')->all())
        ->toBe(['hola', 'Primera respuesta.', 'sigue']);

    // The persisted thread holds user + assistant for both turns.
    $conversation = app(ConversationService::class)->resolve('ses-1', User::query()->first()->id);

    expect($conversation->messages()->count())->toBe(4);
});

it('returns the thread oldest to newest', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $service = app(ConversationService::class);
    $conversation = $service->resolve('ses-order', $user->id);

    $service->append($conversation, 'user', 'uno');
    $service->append($conversation, 'assistant', 'dos');
    $service->append($conversation, 'user', 'tres');

    expect(array_column($service->history($conversation), 'content'))
        ->toBe(['uno', 'dos', 'tres']);
});

it('uses the default session when no session id is given (backward compatible)', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    Http::fake(['*/api/chat' => Http::response([
        'message' => ['role' => 'assistant', 'content' => 'Ok.'],
    ])]);

    app(ChatOrchestrator::class)->handle('hola');
    app(ChatOrchestrator::class)->handle('adios', '');

    $conversation = app(ConversationService::class)->resolve(null, $user->id);

    expect($conversation->session_id)->toBe('default');
    expect($conversation->messages()->count())->toBe(4); // 2 turns x (user + assistant)
});
