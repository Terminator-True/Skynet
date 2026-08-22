<?php

namespace Tests\Support;

use App\Services\Gmail\GmailMessagesReader;
use Closure;

/**
 * Seam fake for GmailMessagesReader (google/apiclient Guzzle bypasses
 * Http::fake). Mutable handlers let each test choose success vs typed
 * failure; calls records the exact query/maxResults/messageId arguments.
 */
class FakeGmailMessagesReader implements GmailMessagesReader
{
    /** @var list<array{op: string, query: string|null, max_results: int|null, message_id: string|null}> */
    public array $calls = [];

    /** @var Closure(string, int): list<array{subject: string, from: string, snippet: string, date: string|null}> */
    public Closure $searchHandler;

    /** @var Closure(string): array{subject: string, from: string, date: string|null, body: string} */
    public Closure $getHandler;

    public function __construct()
    {
        $this->searchHandler = fn (): array => [];
        $this->getHandler = fn (): array => ['subject' => '', 'from' => '', 'date' => null, 'body' => ''];
    }

    public function search(string $query, int $maxResults): array
    {
        $this->calls[] = [
            'op' => 'search',
            'query' => $query,
            'max_results' => $maxResults,
            'message_id' => null,
        ];

        return ($this->searchHandler)($query, $maxResults);
    }

    /** @return array{subject: string, from: string, date: string|null, body: string} */
    public function get(string $messageId): array
    {
        $this->calls[] = [
            'op' => 'get',
            'query' => null,
            'max_results' => null,
            'message_id' => $messageId,
        ];

        return ($this->getHandler)($messageId);
    }
}
