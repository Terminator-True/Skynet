<?php

use App\Services\ChatLoopExhaustedException;
use App\Services\ChatOrchestrator;
use App\Services\Gmail\GmailMessagesReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGmailMessagesReader;

uses(RefreshDatabase::class);

/**
 * Scripted Ollama /api/chat response helper.
 *
 * @param  array<int, array<string, mixed>>  $messages
 */
function scriptedOllama(array $messages): void
{
    Http::fake([
        '*/api/chat' => Http::sequence($messages),
    ]);
}

function assistantContent(string $content): array
{
    return ['message' => ['role' => 'assistant', 'content' => $content]];
}

function assistantToolCall(string $name, array $args): array
{
    return ['message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [['function' => ['name' => $name, 'arguments' => $args]]],
    ]];
}

afterEach(fn () => Carbon::setTestNow());

it('returns a direct reply when the model calls no tools', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([assistantContent('Hello there!')]);

    $result = app(ChatOrchestrator::class)->handle('Say hello');

    expect($result->reply)->toBe('Hello there!')
        ->and($result->toolCalls)->toBe([])
        ->and($result->toArray()['reply'])->toBe('Hello there!');
});

it('executes one tool call and feeds the result back for the final reply', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([
        assistantToolCall('calculate_sum', ['a' => 2, 'b' => 3]),
        assistantContent('2 + 3 equals 5.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('What is 2 plus 3?');

    expect($result->reply)->toBe('2 + 3 equals 5.')
        ->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolCalls[0]['name'])->toBe('calculate_sum')
        ->and($result->toolCalls[0]['arguments'])->toBe(['a' => 2, 'b' => 3])
        ->and($result->toolCalls[0]['result']['sum'])->toBe(5.0);

    // Second request must carry the assistant call + role:tool result.
    Http::assertSentInOrder([
        fn ($request) => count(data_get(json_decode($request->body(), true), 'messages', [])) === 2,
        fn ($request) => count(data_get(json_decode($request->body(), true), 'messages', [])) === 4,
    ]);
});

it('handles a two-iteration chained tool flow', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([
        assistantToolCall('get_current_time', []),
        assistantToolCall('get_weather_mock', ['city' => 'Rosario']),
        assistantContent('It is rainy and 22°C in Rosario right now.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('What is the weather in Rosario at this time?');

    expect($result->reply)->toContain('rainy')
        ->and(collect($result->toolCalls)->pluck('name')->all())
        ->toBe(['get_current_time', 'get_weather_mock']);
});

it('self-heals when the model invents an unknown tool', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([
        assistantToolCall('send_email', ['to' => 'x']),
        assistantContent('I cannot send emails, sorry.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('Send an email to x');

    expect($result->reply)->toContain('cannot send emails')
        ->and($result->toolCalls[0]['result']['error'])->toBe('unknown_tool');
});

it('feeds schema-invalid arguments back to the model once', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([
        assistantToolCall('calculate_sum', ['a' => 'two', 'b' => 3]),
        assistantToolCall('calculate_sum', ['a' => 2, 'b' => 3]),
        assistantContent('The sum is 5.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('Add two and three');

    expect($result->reply)->toBe('The sum is 5.')
        ->and($result->toolCalls[0]['result']['error'])->toBe('invalid_arguments')
        ->and($result->toolCalls[1]['result']['sum'])->toBe(5.0);
});

it('throws a structured exception when the iteration cap is exhausted', function () {
    config(['ollama.base_url' => 'http://ollama.test', 'ollama.max_tool_iterations' => 4]);
    scriptedOllama([
        assistantToolCall('get_current_time', []),
        assistantToolCall('get_current_time', []),
        assistantToolCall('get_current_time', []),
        assistantToolCall('get_current_time', []),
    ]);

    try {
        app(ChatOrchestrator::class)->handle('Time? Time? Time?');
        $this->fail('Expected ChatLoopExhaustedException.');
    } catch (ChatLoopExhaustedException $e) {
        expect($e->getMessage())->toContain('cap of 4');
    }
});

it('includes every registered tool definition in each request', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    scriptedOllama([assistantContent('ok')]);

    app(ChatOrchestrator::class)->handle('hi');

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);
        $names = collect($body['tools'] ?? [])->pluck('function.name')->all();

        return $names === ['get_current_time', 'calculate_sum', 'get_weather_mock', 'listar_eventos_calendario', 'buscar_correos', 'leer_correo', 'extraer_tracking_amazon', 'buscar_web', 'recordar_preferencia', 'buscar_notas', 'guardar_nota', 'consultar_estado_opencode'];
    });
});

it('injects the current datetime and assistant timezone into the system prompt', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-21 14:05', 'America/Argentina/Buenos_Aires'));

    scriptedOllama([assistantContent('ok')]);

    app(ChatOrchestrator::class)->handle('What do I have today?');

    // Spec "Model can compute today": the system message must carry a fresh
    // date + timezone line so relative expressions resolve to concrete args.
    Http::assertSent(function ($request): bool {
        $system = json_decode($request->body(), true)['messages'][0]['content'];

        return str_contains($system, 'Current date/time: Friday, 2026-08-21 14:05')
            && str_contains($system, '(America/Argentina/Buenos_Aires)')
            && str_contains($system, "resolve relative dates like 'today'");
    });
});

it('retries once when the model leaks a tool call inline (_icall_) instead of structured tool_calls', function () {
    config(['ollama.base_url' => 'http://ollama.test']);

    // Rung 0: model writes the call as text. Rung 1 (after the corrective
    // prompt): model emits the proper structured tool call.
    scriptedOllama([
        assistantContent('_icall_ {"name": "calculate_sum", "arguments": {"a": 2, "b": 3}}'),
        assistantToolCall('calculate_sum', ['a' => 2, 'b' => 3]),
        assistantContent('2 + 3 equals 5.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('What is 2 plus 3?');

    expect($result->reply)->toBe('2 + 3 equals 5.')
        ->and($result->toolCalls)->toHaveCount(1)
        ->and($result->toolCalls[0]['name'])->toBe('calculate_sum')
        ->and($result->toolCalls[0]['result']['sum'])->toBe(5.0);
});

it('does not retry a plain-text answer that merely mentions a tool name', function () {
    config(['ollama.base_url' => 'http://ollama.test']);

    // Content has no inline tool-call JSON, so the retry heuristic must not fire.
    scriptedOllama([assistantContent('I can help with that.')]);

    $result = app(ChatOrchestrator::class)->handle('hello');

    expect($result->reply)->toBe('I can help with that.')
        ->and($result->toolCalls)->toBe([]);
});

it('truncates oversized tool results before feeding them back to the model', function () {
    config(['ollama.base_url' => 'http://ollama.test']);
    config(['ollama.tool_result_char_cap' => 200]);

    // Bind a fake reader returning a body well over the 200-char cap.
    $fake = new FakeGmailMessagesReader;
    $fake->getHandler = fn (): array => ['body' => str_repeat('x', 5000)];
    app()->instance(GmailMessagesReader::class, $fake);

    scriptedOllama([
        assistantToolCall('leer_correo', ['message_id' => 'abc123']),
        assistantContent('Here is the email summary.'),
    ]);

    $result = app(ChatOrchestrator::class)->handle('what does the email say');

    expect($result->reply)->toBe('Here is the email summary.')
        ->and($result->toolCalls)->toHaveCount(1);

    // The tool result message sent back to the model must be truncated.
    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);
        foreach ($body['messages'] as $message) {
            if (($message['role'] ?? '') === 'tool' && str_contains($message['content'], 'truncated')) {
                return true;
            }
        }

        return false;
    });
});
