<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatLoopExhaustedException;
use App\Services\Ollama\EmbeddingProvider;
use App\Services\Ollama\OllamaConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

function fakeChatEndpoint(array $sequence): void
{
    Http::fake(['*/api/chat' => Http::sequence($sequence)]);
}

it('answers a plain question with a reply and empty tool_calls', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    fakeChatEndpoint([
        ['message' => ['role' => 'assistant', 'content' => 'Hello! How can I help?']],
    ]);

    $response = $this->postJson('/chat', ['message' => 'Say hello']);

    $response->assertOk()
        ->assertJson([
            'reply' => 'Hello! How can I help?',
            'tool_calls' => [],
        ]);
});

it('echoes the session id and returns the persisted history for that session', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    fakeChatEndpoint([
        ['message' => ['role' => 'assistant', 'content' => 'Entendido.']],
    ]);

    $response = $this->postJson('/chat', ['message' => 'Hola', 'session_id' => 'ses-abc']);

    $response->assertOk()
        ->assertJsonPath('session_id', 'ses-abc')
        ->assertJsonCount(2, 'history')
        ->assertJsonPath('history.0.content', 'Hola')
        ->assertJsonPath('history.0.role', 'user')
        ->assertJsonPath('history.1.role', 'assistant');
});

it('returns the full contract shape for tool-mediated answers', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    fakeChatEndpoint([
        ['message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [['function' => ['name' => 'calculate_sum', 'arguments' => ['a' => 2, 'b' => 3]]]],
        ]],
        ['message' => ['role' => 'assistant', 'content' => 'The sum of 2 and 3 is 5.']],
    ]);

    $response = $this->postJson('/chat', ['message' => 'What is 2 plus 3?']);

    $response->assertOk()
        ->assertJsonPath('reply', 'The sum of 2 and 3 is 5.')
        ->assertJsonCount(1, 'tool_calls')
        ->assertJsonPath('tool_calls.0.name', 'calculate_sum')
        ->assertJsonPath('tool_calls.0.arguments', ['a' => 2, 'b' => 3]);

    // JSON round-trips make int/float identity unreliable across the wire.
    expect($response->json('tool_calls.0.result.sum'))->toEqual(5.0);
});

it('rejects an empty message with 422', function () {
    $this->postJson('/chat', ['message' => ''])->assertStatus(422);
    $this->postJson('/chat', [])->assertStatus(422);
});

it('maps OllamaConnectionException to a 502 response', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    fakeChatEndpoint([
        fn () => throw new OllamaConnectionException('Ollama is not reachable at http://ollama.test'),
    ]);

    $this->postJson('/chat', ['message' => 'hi'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'ollama_unreachable');
});

it('maps loop exhaustion to a structured 500 response', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    fakeChatEndpoint([
        fn () => throw new ChatLoopExhaustedException('Model exceeded the tool-call iteration cap of 4.'),
    ]);

    $this->postJson('/chat', ['message' => 'hi'])
        ->assertStatus(500)
        ->assertJsonPath('error', 'max_iterations');
});

function seedSessionMessages(string $sessionId, int $count): Conversation
{
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $conversation = Conversation::create([
        'session_id' => $sessionId,
        'user_id' => $user->id,
    ]);

    for ($i = 1; $i <= $count; $i++) {
        $conversation->messages()->create([
            'role' => $i % 2 === 0 ? 'assistant' : 'user',
            'content' => "message {$i}",
        ]);
    }

    return $conversation;
}

it('returns the last ten messages oldest to newest for an existing session', function () {
    seedSessionMessages('ses-15', 15);

    $this->getJson('/chat/history?session_id=ses-15')
        ->assertOk()
        ->assertJsonPath('session_id', 'ses-15')
        ->assertJsonCount(10, 'history')
        ->assertJsonPath('history.0.content', 'message 6')
        ->assertJsonPath('history.9.content', 'message 15');
});

it('returns an empty history for a fresh or default session', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);

    $this->getJson('/chat/history?session_id=brand-new')
        ->assertOk()
        ->assertJsonPath('session_id', 'brand-new')
        ->assertJsonCount(0, 'history');

    $this->getJson('/chat/history')
        ->assertOk()
        ->assertJsonCount(0, 'history');
});

it('caps the POST payload history at the last ten while preserving tool_trace', function () {
    $this->app->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
    config(['ollama.base_url' => 'http://ollama.test']);
    fakeChatEndpoint([
        ['message' => ['role' => 'assistant', 'content' => 'Entendido.']],
    ]);

    $conversation = seedSessionMessages('ses-many', 12);
    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'traced reply',
        'tool_trace' => ['name' => 'calculate_sum', 'result' => ['sum' => 5]],
    ]);

    $response = $this->postJson('/chat', ['message' => 'Hola', 'session_id' => 'ses-many']);

    $response->assertOk()
        ->assertJsonCount(10, 'history')
        ->assertJsonPath('history.0.content', 'message 6')
        ->assertJsonPath('history.9.content', 'Entendido.');

    // The traced reply and the new user message sit just before the reply.
    expect($response->json('history.7.role'))->toBe('assistant')
        ->and($response->json('history.7.content'))->toBe('traced reply')
        ->and($response->json('history.7.tool_trace.name'))->toBe('calculate_sum')
        ->and($response->json('history.7.tool_trace.result.sum'))->toEqual(5.0)
        ->and($response->json('history.8.role'))->toBe('user')
        ->and($response->json('history.8.content'))->toBe('Hola');
});
