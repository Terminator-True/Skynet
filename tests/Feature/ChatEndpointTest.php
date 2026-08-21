<?php

use App\Services\ChatLoopExhaustedException;
use App\Services\Ollama\OllamaConnectionException;
use Illuminate\Support\Facades\Http;

function fakeChatEndpoint(array $sequence): void
{
    Http::fake(['*/api/chat' => Http::sequence($sequence)]);
}

it('answers a plain question with a reply and empty tool_calls', function () {
    fakeChatEndpoint([
        ['message' => ['role' => 'assistant', 'content' => 'Hello! How can I help?']],
    ]);

    $response = $this->postJson('/chat', ['message' => 'Say hello']);

    $response->assertOk()
        ->assertExactJson([
            'reply' => 'Hello! How can I help?',
            'tool_calls' => [],
        ]);
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
