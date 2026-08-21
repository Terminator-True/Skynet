<?php

use App\Services\Ollama\OllamaClient;
use App\Services\Ollama\OllamaConnectionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('parses an assistant message containing tool calls', function () {
    config(['ollama.model' => 'test-model', 'ollama.num_ctx' => 4096]);

    Http::fake([
        '*/api/chat' => Http::response([
            'model' => 'test-model',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'function' => [
                            'name' => 'calculate_sum',
                            'arguments' => ['a' => 2, 'b' => 3],
                        ],
                    ],
                    [
                        'function' => [
                            'name' => 'string_args',
                            'arguments' => '{"city":"Rosario"}',
                        ],
                    ],
                ],
            ],
            'done' => true,
        ]),
    ]);

    $result = app(OllamaClient::class)->chat(
        [['role' => 'user', 'content' => 'What is 2 + 3?']],
        [['type' => 'function', 'function' => ['name' => 'x', 'parameters' => []]]],
    );

    expect($result['content'])->toBe('')
        ->and($result['tool_calls'])->toHaveCount(2)
        ->and($result['tool_calls'][0]['name'])->toBe('calculate_sum')
        ->and($result['tool_calls'][0]['arguments'])->toBe(['a' => 2, 'b' => 3])
        // Arguments delivered as JSON string must be decoded.
        ->and($result['tool_calls'][1]['arguments'])->toBe(['city' => 'Rosario']);
});

it('sends model, messages, tools and num_ctx in the payload', function () {
    config(['ollama.model' => 'test-model', 'ollama.base_url' => 'http://ollama.test', 'ollama.num_ctx' => 4096]);

    Http::fake(['http://ollama.test/api/chat' => Http::response([
        'message' => ['role' => 'assistant', 'content' => 'hi'],
    ])]);

    $result = app(OllamaClient::class)->chat([['role' => 'user', 'content' => 'hello']]);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'http://ollama.test/api/chat'
            && $body['model'] === 'test-model'
            && $body['stream'] === false
            && $body['options']['num_ctx'] === 4096
            && ! array_key_exists('tools', $body);
    });

    expect($result['content'])->toBe('hi')->and($result['tool_calls'])->toBe([]);
});

it('throws a typed exception when Ollama is unreachable', function () {
    config(['ollama.base_url' => 'http://localhost:59999']);

    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    app(OllamaClient::class)->chat([['role' => 'user', 'content' => 'hi']]);
})->throws(OllamaConnectionException::class);

it('throws a typed exception on 5xx responses without silent fallback', function () {
    config(['ollama.base_url' => 'http://ollama.test']);

    Http::fake(['http://ollama.test/api/chat' => Http::response(['error' => 'oom'], 500)]);

    app(OllamaClient::class)->chat([['role' => 'user', 'content' => 'hi']]);
})->throws(OllamaConnectionException::class);

it('gives an actionable message when the model is not pulled', function () {
    config(['ollama.base_url' => 'http://ollama.test', 'ollama.model' => 'missing-model']);

    Http::fake(['http://ollama.test/api/chat' => Http::response(['error' => 'not found'], 404)]);

    try {
        app(OllamaClient::class)->chat([['role' => 'user', 'content' => 'hi']]);
        $this->fail('Expected exception was not thrown.');
    } catch (OllamaConnectionException $e) {
        expect($e->getMessage())->toContain('ollama pull missing-model');
    }
});
