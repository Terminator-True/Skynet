<?php

namespace App\Services;

use App\Services\Ollama\OllamaClient;
use App\Tools\ToolRegistry;

/**
 * Stateless single-pass tool-calling loop.
 *
 * Deterministic by design: NO retries here — retry ladders live in the eval
 * harness only, so Fase 0 measures raw reliability. Bounded by
 * max_tool_iterations; exhaustion raises a structured error.
 */
class ChatOrchestrator
{
    private const SYSTEM_PROMPT_BASE = 'You are a helpful personal assistant. '
        .'Use the provided tools whenever they can answer the user request. '
        .'When you call tools, wait for their results before answering. '
        .'If no tool is needed, answer directly in natural language.';

    public function __construct(
        private readonly OllamaClient $client,
        private readonly ToolRegistry $registry,
        private readonly MemoryService $memory,
    ) {}

    /**
     * @throws ChatLoopExhaustedException when the iteration cap is reached
     */
    public function handle(string $userMessage): ChatTurnResult
    {
        $maxIterations = (int) config('ollama.max_tool_iterations', 4);

        $messages = [
            ['role' => 'system', 'content' => $this->buildSystemPrompt($userMessage)],
            ['role' => 'user', 'content' => $userMessage],
        ];

        /** @var list<array{name: string, arguments: array<string, mixed>, result: array<string, mixed>}> $trace */
        $trace = [];

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $response = $this->client->chat($messages, $this->registry->definitions());

            if ($response['tool_calls'] === []) {
                return new ChatTurnResult(
                    reply: $this->finalReply($response['content']),
                    toolCalls: $trace,
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

        $prompt = self::SYSTEM_PROMPT_BASE.' Current date/time: '
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
        return [
            'role' => 'tool',
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
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
}
