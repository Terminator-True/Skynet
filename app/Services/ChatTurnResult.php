<?php

namespace App\Services;

/**
 * Immutable outcome of one chat turn.
 *
 * @param  list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}>  $toolCalls
 */
final class ChatTurnResult
{
    public function __construct(
        public readonly string $reply,
        public readonly array $toolCalls = [],
    ) {}

    /** Contract shape for POST /chat responses. */
    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'tool_calls' => $this->toolCalls,
        ];
    }
}
