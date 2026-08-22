<?php

namespace App\Tools;

use App\Services\MemoryService;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Long-term preference memory tool (Fase 6, spec {preferencia}): stores the
 * given preference with its locally-computed embedding via MemoryService and
 * returns a confirmation. The description is deliberately scoped to explicit
 * memory-recording intent ("recordá que...", "remember that...") so the model
 * does not over-trigger it on ordinary conversation.
 */
class RecordarPreferencia implements Tool
{
    public function __construct(private readonly MemoryService $memory) {}

    public function name(): string
    {
        return 'recordar_preferencia';
    }

    public function description(): string
    {
        return 'Stores a user preference in long-term memory so it is recalled in future turns. '
            .'Use ONLY when the user explicitly asks you to remember or record a preference '
            .'(e.g. "recordá que prefiero...", "remember that I like..."). Do not use for normal conversation.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'preferencia' => [
                    'type' => 'string',
                    'description' => 'The preference to remember, as a full natural-language statement',
                ],
            ],
            'required' => ['preferencia'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status: string, preferencia: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['preferencia']) || ! is_string($args['preferencia']) || trim($args['preferencia']) === '') {
            throw new InvalidArgumentException('recordar_preferencia requires a non-empty string preferencia argument.');
        }

        $this->memory->remember(trim($args['preferencia']));

        return [
            'status' => 'saved',
            'preferencia' => trim($args['preferencia']),
        ];
    }
}
