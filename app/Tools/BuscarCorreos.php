<?php

namespace App\Tools;

use App\Services\Gmail\GmailMessagesReader;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Gmail search tool (spec contract {query, max_results}): returns trimmed
 * metadata only — never bodies or raw payloads. The query is validated, not
 * rewritten (D3): wrapping in quotes would break from:/newer_than: operators,
 * and the API call is read-only so there is no injection write-vector.
 */
class BuscarCorreos implements Tool
{
    public const DEFAULT_MAX_RESULTS = 10;

    public const MAX_RESULTS = 50;

    /** Query cap keeps oversized model output out of the Gmail API request. */
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(private readonly GmailMessagesReader $reader) {}

    public function name(): string
    {
        return 'buscar_correos';
    }

    public function description(): string
    {
        return "Searches the user's Gmail messages (read-only). Use when asked about emails, orders or correspondence. Query supports Gmail operators like from:amazon.com or newer_than:7d.";
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Gmail search query, e.g. "from:amazon.com"',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum messages to return (1-50, default 10)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{messages?: list<array{id: string, subject: string, from: string, snippet: string, date: string|null}>, error?: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['query']) || ! is_string($args['query']) || trim($args['query']) === '') {
            throw new InvalidArgumentException('buscar_correos requires a non-empty query argument.');
        }

        // Strip control/NUL bytes (D3) BEFORE capping so invisible junk cannot
        // eat into the 200-char budget.
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $args['query']) ?? '';
        $query = mb_substr(trim($stripped), 0, self::MAX_QUERY_LENGTH);

        if ($query === '') {
            throw new InvalidArgumentException('buscar_correos requires a non-empty query argument.');
        }

        $maxResults = isset($args['max_results']) && is_int($args['max_results'])
            ? min(max($args['max_results'], 1), self::MAX_RESULTS)
            : self::DEFAULT_MAX_RESULTS;

        try {
            $messages = $this->reader->search($query, $maxResults);
        } catch (GoogleTokenRefreshException) {
            return ['error' => 'google_not_connected'];
        } catch (GoogleApiException $e) {
            return ['error' => 'google_api_error', 'detail' => mb_substr($e->getMessage(), 0, 300)];
        }

        return ['messages' => $messages];
    }
}
