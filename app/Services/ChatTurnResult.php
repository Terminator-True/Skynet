<?php

namespace App\Services;

/**
 * Immutable outcome of one chat turn.
 *
 * @param  list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}>  $toolCalls
 * @param  list<array{role: string, content: string, tool_trace: array<int, mixed>|null}>  $history
 */
final class ChatTurnResult
{
    public function __construct(
        public readonly string $reply,
        public readonly array $toolCalls = [],
        public readonly array $history = [],
        public readonly ?string $sessionId = null,
    ) {}

    /** Contract shape for POST /chat responses. */
    public function toArray(): array
    {
        return [
            'reply' => $this->reply,
            'tool_calls' => $this->toolCalls,
            'session_id' => $this->sessionId,
            'history' => $this->history,
        ];
    }
}
