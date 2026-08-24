<?php

namespace App\Tools;

use App\Services\BuscarNotas as BuscarNotasService;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Read-only knowledge-base recall tool (REQ buscar_notas): embeds a {tema}
 * query and returns cosine top-k excerpts from the user's local Obsidian vault
 * index. The description is deliberately scoped to explicit knowledge-base
 * recall intent so the model does not over-trigger it on calendar/gmail/web/
 * memory conversation (D6).
 */
class BuscarNotas implements Tool
{
    public function __construct(private readonly BuscarNotasService $buscarNotas) {}

    public function name(): string
    {
        return 'buscar_notas';
    }

    public function description(): string
    {
        return 'Searches the user\'s local Obsidian knowledge base and returns the most relevant note excerpts. '
            .'Use ONLY when the user asks to find or recall something in their own notes / knowledge base '
            .'(e.g. "buscá en mis notas...", "look up my note about..."). '
            .'Do not use for calendar, email, web, or long-term preference memory recall.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tema' => [
                    'type' => 'string',
                    'description' => 'The topic or subject to search for in the user\'s notes',
                ],
            ],
            'required' => ['tema'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{results: list<array{path: string, snippet: string, similarity: float}>}
     */
    public function execute(array $args): array
    {
        if (! isset($args['tema']) || ! is_string($args['tema']) || trim($args['tema']) === '') {
            throw new InvalidArgumentException('buscar_notas requires a non-empty string tema argument.');
        }

        $results = $this->buscarNotas->search(
            trim($args['tema']),
            (int) config('notes.top_k'),
            (int) config('notes.char_cap'),
        );

        return ['results' => $results];
    }
}
