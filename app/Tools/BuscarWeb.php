<?php

namespace App\Tools;

use App\Services\Web\WebKnowledgeReader;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

class BuscarWeb implements Tool
{
    public const MAX_QUERY_LENGTH = 200;

    public const ABSTRACT_CHAR_CAP = 1500;

    public function __construct(private readonly WebKnowledgeReader $reader) {}

    public function name(): string
    {
        return 'buscar_web';
    }

    public function description(): string
    {
        return 'Answers general factual or encyclopedic questions from an external knowledge source, returning one {title, abstract, url} card. Do NOT use for email, calendar, or Amazon — use the dedicated tools for those. Results may be empty for very recent or niche topics — if so, say you found nothing.';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => ['consulta' => ['type' => 'string', 'description' => 'Factual query, e.g. "capital de Francia"']], 'required' => ['consulta']];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{title?: string, abstract?: string, url?: string, error?: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['consulta']) || ! is_string($args['consulta']) || trim($args['consulta']) === '') {
            throw new InvalidArgumentException('buscar_web requires a non-empty consulta argument.');
        }

        // Strip control/NUL bytes BEFORE capping so junk cannot eat the budget.
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $args['consulta']) ?? '';
        $query = mb_substr(trim($stripped), 0, (int) config('web.query_max_length', self::MAX_QUERY_LENGTH));

        if ($query === '') {
            throw new InvalidArgumentException('buscar_web requires a non-empty consulta argument.');
        }

        $card = $this->reader->search($query);

        if ($card === null) {
            return ['error' => 'no_result'];
        }

        return [
            'title' => $card['title'],
            'abstract' => mb_substr($card['abstract'], 0, (int) config('web.abstract_char_cap', self::ABSTRACT_CHAR_CAP)),
            'url' => $card['url'],
        ];
    }
}
