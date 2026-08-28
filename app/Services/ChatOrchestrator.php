<?php

namespace App\Services;

use App\Models\User;
use App\Services\Ollama\OllamaClient;
use App\Tools\ToolRegistry;

/**
 * Tool-calling loop with a single corrective retry.
 *
 * The 14B local model is occasionally nondeterministic: instead of emitting a
 * structured `tool_calls` array, it writes the tool call inline in the reply
 * text (e.g. `_icall_ {"name": ..., "arguments": ...}`). When the first pass
 * returns no structured tool calls but the reply text signals a tool intent,
 * we re-prompt ONCE with a strict instruction that the call must go in the
 * structured field. This is the production-side mirror of the eval harness
 * rung-2 retry; it makes real questions like "¿qué pone en mi último email?"
 * resolve more reliably without unbounded retries. Bounded by
 * max_tool_iterations; exhaustion raises a structured error.
 */
class ChatOrchestrator
{
    private const SYSTEM_PROMPT_BASE = 'You are a helpful personal assistant. '
        .'Use the provided tools whenever they can answer the user request. '
        .'When you call tools, wait for their results before answering. '
        .'If no tool is needed, answer directly in natural language.'
        ."\n\nFollow-up flow: after you answer a search or investigation request, "
        .'ask the user "¿Quieres saber algo más?". If the user answers negatively, '
        .'ask "¿Quieres que apunte en Obsidian lo que has aprendido?". '
        .'If the user answers positively, call guardar_nota to save a Markdown note '
        .'summarising what was learned. Only call guardar_nota after the user has '
        .'explicitly agreed to the save question — never on ordinary chat.';

    /**
     * Note-generation guidance so guardar_nota writes a well-formed note: the
     * filename derives from the title via Str::slug, the body carries YAML
     * frontmatter, and the content follows a concise, structured format.
     */
    private const NOTE_GEN_GUIDANCE = "\n\nNote format for guardar_nota: "
        .'derive the filename from the title with Str::slug (lowercase, hyphen-separated) plus ".md"; '
        .'write YAML frontmatter with title, date, and tags keys; '
        .'then a concise structured body (an intro line, then headings and bullet points) '
        .'summarising the learning.';

    /** Emitted when the model leaks a tool call into the reply text. */
    private const TOOL_RETRY_SUFFIX = "\n\nIMPORTANT: emit the tool call as a STRUCTURED tool_calls field with its real arguments — never write a tool invocation inside your reply text. If you intended to call a tool, do so now properly.";

    public function __construct(
        private readonly OllamaClient $client,
        private readonly ToolRegistry $registry,
        private readonly MemoryService $memory,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * Run one turn against a (possibly pre-existing) session thread.
     *
     * Reads the persisted thread for the session (ORDER BY created_at,id),
     * prepends it as context, appends the new user message, runs the Ollama
     * tool loop, then persists the assistant reply (with its tool trace) so the
     * follow-up flow survives across HTTP requests. buildSystemPrompt stays
     * per-request (D3); omitting $sessionId resolves the fixed 'default'
     * session, keeping single-turn behaviour backward compatible (D2).
     *
     * @throws ChatLoopExhaustedException when the iteration cap is reached
     */
    public function handle(string $userMessage, ?string $sessionId = null): ChatTurnResult
    {
        $maxIterations = (int) config('ollama.max_tool_iterations', 4);

        $user = User::query()->first();
        $conversation = $user !== null
            ? $this->conversations->resolve($sessionId, $user->id)
            : null;

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($userMessage)],
        ];

        if ($conversation !== null) {
            foreach ($this->conversations->history($conversation) as $message) {
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        if ($conversation !== null) {
            $this->conversations->append($conversation, 'user', $userMessage);
        }

        /** @var list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}> $trace */
        $trace = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $response = $this->client->chat($messages, $this->registry->definitions());

            if ($response['tool_calls'] === []) {
                // No structured tool call. If the reply text signals a leaked
                // inline tool call, retry once with a strict instruction; the
                // model sometimes writes `_icall_ {...}` instead of populating
                // tool_calls. Otherwise return the plain-text answer.
                if ($iteration === 0 && self::looksLikeInlineToolCall($response['content'])) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $response['content'],
                    ];
                    $messages[] = [
                        'role' => 'user',
                        'content' => self::TOOL_RETRY_SUFFIX,
                    ];

                    continue;
                }

                $reply = $this->finalReply($response['content']);

                if ($conversation !== null) {
                    $this->conversations->append($conversation, 'assistant', $reply, $trace ?: null);
                }

                return new ChatTurnResult(
                    reply: $reply,
                    toolCalls: $trace,
                    history: $conversation !== null ? $this->conversations->recent($conversation) : [],
                    sessionId: $conversation?->session_id,
                );
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $response['content'],
                'tool_calls' => array_map(
                    fn (array $call): array => [
                        'function' => [
                            'name' => $call['name'],
                            'arguments' => (object) $call['arguments'],
                        ],
                    ],
                    $response['tool_calls'],
                ),
            ];

            foreach ($response['tool_calls'] as $call) {
                ['name' => $name, 'arguments' => $args] = $call;
                $messages[] = $this->resolveAndRun($name, $args, $trace);
            }
        }

        throw new ChatLoopExhaustedException(
            "Model exceeded the tool-call iteration cap of {$maxIterations}.",
        );
    }

    /**
     * Builds the system prompt fresh on every request with the current
     * datetime in the user's timezone, so the model can resolve relative
     * expressions like "hoy" into concrete desde/hasta tool arguments.
     * Recalled preferences are appended when relevant so stateless turns stay
     * memory-aware. Nothing caches prompts, so the per-request build costs
     * nothing.
     */
    private function buildSystemPrompt(string $userMessage): string
    {
        $now = now(config('app.assistant_timezone'));

        $prompt = self::SYSTEM_PROMPT_BASE
            .self::NOTE_GEN_GUIDANCE
            .' Current date/time: '
            .$now->format('l, Y-m-d H:i').' ('.$now->getTimezone()->getName().'). '
            ."Use this to resolve relative dates like 'today'.";

        $recalled = $this->memory->recall(
            $userMessage,
            (int) config('ollama.memory_recall_top_k', 3),
            (int) config('ollama.memory_recall_char_cap', 200),
        );

        if ($recalled !== '') {
            $prompt .= " Remembered preferences:\n".$recalled;
        }

        return $prompt;
    }

    /**
     * Executes one call (or produces a corrective tool message) and appends
     * to the trace. Unknown tools and schema-invalid args feed an error back
     * to the model as role:'tool' so it can self-heal on the next iteration.
     *
     * @param  array<string, mixed>  $args
     * @param  list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}>  $trace
     * @return array{role: 'tool', content: string, tool_name: string}
     */
    private function resolveAndRun(string $name, array $args, array &$trace): array
    {
        if (! $this->registry->has($name)) {
            $trace[] = ['name' => $name, 'arguments' => $args, 'result' => ['error' => 'unknown_tool']];

            return $this->toolMessage($name, ['error' => "Unknown tool [{$name}]. Available tools are listed in your instructions."]);
        }

        if ($errors = $this->validateArgs($this->registry->get($name)->schema(), $args)) {
            $trace[] = ['name' => $name, 'arguments' => $args, 'result' => ['error' => 'invalid_arguments', 'details' => $errors]];

            return $this->toolMessage($name, ['error' => 'Invalid arguments.', 'validation' => $errors]);
        }

        try {
            $result = $this->registry->get($name)->execute($args);
        } catch (\Throwable $e) {
            $trace[] = ['name' => $name, 'arguments' => $args, 'result' => ['error' => 'execution_failed']];

            return $this->toolMessage($name, ['error' => 'Tool execution failed: '.$e->getMessage()]);
        }

        $trace[] = ['name' => $name, 'arguments' => $args, 'result' => $result];

        return $this->toolMessage($name, $result);
    }

    /**
     * Structural JSON-Schema subset check (required + top-level types).
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function validateArgs(array $schema, array $args): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $args) || $args[$required] === null || $args[$required] === '') {
                $errors[] = "Missing required argument [$required].";
            }
        }

        $typeCheck = [
            'string' => 'is_string',
            'number' => fn (mixed $v): bool => is_int($v) || is_float($v),
            'integer' => 'is_int',
            'boolean' => 'is_bool',
            'array' => 'is_array',
        ];

        foreach ($properties as $prop => $definition) {
            if (! array_key_exists($prop, $args)) {
                continue;
            }

            $type = $definition['type'] ?? null;
            $check = $typeCheck[$type] ?? null;

            if ($check !== null && ! $check($args[$prop])) {
                $errors[] = "Argument [$prop] must be of type {$type}.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{role: 'tool', content: string, tool_name: string}
     */
    private function toolMessage(string $name, array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Guard the model context: a single tool result (e.g. a long email body
        // from leer_correo) must never saturate num_ctx. Truncate and note the
        // cut so the model knows there was more content it didn't see.
        $cap = (int) config('ollama.tool_result_char_cap', 6000);

        // json_encode fails only on non-UTF8/recursive payloads; degrade to a
        // structured error rather than corrupting the model context.
        $json = is_string($encoded)
            ? (mb_strlen($encoded) > $cap
                ? mb_substr($encoded, 0, $cap)."\n...\u{2026} [truncated: results exceed the context limit]"
                : $encoded)
            : '{"error":"serialization_failed"}';

        return [
            'role' => 'tool',
            'content' => $json,
            'tool_name' => $name,
        ];
    }

    /** A model that stops calling tools but returns empty content still needs a usable reply. */
    private function finalReply(string $content): string
    {
        $trimmed = trim($content);

        if ($trimmed !== '') {
            return $trimmed;
        }

        // Defensive: shouldn't happen with well-behaved models, but never 500
        // on blank final content after successful tool runs.
        return '(The model returned an empty answer.)';
    }

    /**
     * Heuristic: did the model write a tool invocation inline in the reply
     * text instead of the structured tool_calls field? Matches the `_icall_`
     * marker the 14B model sometimes emits, plus a bare JSON object that names
     * one of the registered tools. A false positive only costs one retry.
     */
    private static function looksLikeInlineToolCall(string $content): bool
    {
        if (str_contains($content, '_icall_')) {
            return true;
        }

        return (bool) preg_match(
            '/\{\s*"name"\s*:\s*"[a-z_]+"/i',
            $content,
        );
    }
}
