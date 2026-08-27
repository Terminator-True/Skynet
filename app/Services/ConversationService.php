<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

/**
 * Stateful conversation thread persistence (D1/D2/D3).
 *
 * resolve() maps an optional client session id to a Conversation, falling back
 * to a fixed 'default' session when the id is omitted or blank so stateless
 * single-turn behaviour is unchanged. history() returns the thread oldest to
 * newest (ORDER BY created_at,id) so the orchestrator can rebuild multi-turn
 * context. append() records one message (user or assistant) with an optional
 * JSON tool trace. Single tenant: ownership is pinned by the passed user id
 * (User::query()->first() precedent).
 */
class ConversationService
{
    public function __construct(
        private readonly Conversation $conversation,
    ) {}

    /**
     * Resolve (or create) the conversation for a session id.
     *
     * '' and null both map to the fixed 'default' session (spec: omitted id ->
     * default; backward compatible).
     */
    public function resolve(?string $sessionId, int $userId): Conversation
    {
        $key = ($sessionId === null || $sessionId === '') ? 'default' : $sessionId;

        return $this->conversation->firstOrCreate([
            'session_id' => $key,
            'user_id' => $userId,
        ]);
    }

    /**
     * The full thread for a conversation, oldest to newest.
     *
     * @return list<array{role: string, content: string, tool_trace: array<int, mixed>|null}>
     */
    public function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message): array => [
                'role' => $message->role,
                'content' => $message->content,
                'tool_trace' => $message->tool_trace,
            ])
            ->all();
    }

    /**
     * Append a message to a conversation.
     *
     * @param  array<int, mixed>|null  $trace
     */
    public function append(Conversation $conversation, string $role, string $content, ?array $trace = null): void
    {
        $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
            'tool_trace' => $trace,
        ]);
    }
}
