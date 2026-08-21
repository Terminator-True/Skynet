<?php

namespace App\Services\Ollama;

use Illuminate\Http\Client\ConnectionException as HttpClientConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin transport to Ollama's native tool-calling chat API.
 *
 * Zero orchestration logic: sends messages + tools, returns the parsed
 * assistant message. Typed exception on connect-timeout / 5xx — never a
 * silent fallback.
 */
class OllamaClient
{
    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<int, array<string, mixed>>  $tools  JSON-Schema tool definitions (Ollama format)
     * @return array{content: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}
     *
     * @throws OllamaConnectionException on unreachable host or 5xx response
     */
    public function chat(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => config('ollama.model'),
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'num_ctx' => (int) config('ollama.num_ctx', 4096),
            ],
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        try {
            $response = Http::timeout(120)
                ->connectTimeout(5)
                ->post(rtrim(config('ollama.base_url'), '/').'/api/chat', $payload);
        } catch (HttpClientConnectionException $e) {
            throw new OllamaConnectionException(
                'Ollama is not reachable at '.config('ollama.base_url')." — {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($response->serverError()) {
            throw new OllamaConnectionException(
                "Ollama returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300),
            );
        }

        if ($response->notFound()) {
            throw new OllamaConnectionException(
                'Ollama model ['.config('ollama.model').'] is not pulled. Run: ollama pull '.config('ollama.model'),
            );
        }

        if (! $response->successful()) {
            throw new OllamaConnectionException(
                "Ollama rejected the request (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300),
            );
        }

        return $this->parseMessage($response->json('message', []));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array{content: string, tool_calls: list<array{name: string, arguments: array<string, mixed>}>}
     */
    private function parseMessage(array $message): array
    {
        $toolCalls = [];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $function = (array) ($call['function'] ?? []);

            $toolCalls[] = [
                'name' => (string) ($function['name'] ?? ''),
                // Ollama may deliver arguments as an object or as a JSON string.
                'arguments' => $this->decodeArguments($function['arguments'] ?? []),
            ];
        }

        return [
            'content' => (string) ($message['content'] ?? ''),
            'tool_calls' => $toolCalls,
        ];
    }

    private function decodeArguments(mixed $arguments): array
    {
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($arguments) ? $arguments : [];
    }
}
