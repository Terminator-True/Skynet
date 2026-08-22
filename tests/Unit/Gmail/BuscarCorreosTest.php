<?php

use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\BuscarCorreos;
use App\Tools\Contracts\Tool;
use Tests\Support\FakeGmailMessagesReader;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{subject: string, from: string, snippet: string, date: string|null}
 */
function gmail_message(array $overrides = []): array
{
    return [
        'subject' => 'Your order has shipped',
        'from' => 'shipment@amazon.com',
        'snippet' => 'Arriving Thursday...',
        'date' => 'Fri, 21 Aug 2026 10:00:00 -0300',
        ...$overrides,
    ];
}

function gmail_search_tool(FakeGmailMessagesReader $fake): Tool
{
    return new BuscarCorreos($fake);
}

it('returns exactly the faked messages under the messages contract field', function () {
    $messages = [
        gmail_message(),
        gmail_message(['subject' => 'Second', 'from' => 'no-reply@other.com', 'date' => null]),
    ];
    $fake = new FakeGmailMessagesReader;
    $fake->searchHandler = fn (): array => $messages;

    $result = gmail_search_tool($fake)->execute([
        'query' => 'from:amazon.com',
        'max_results' => 5,
    ]);

    expect($result)->toBe(['messages' => $messages])
        ->and($result['messages'])->toHaveCount(2)
        ->and($fake->calls)->toBe([[
            'op' => 'search',
            'query' => 'from:amazon.com',
            'max_results' => 5,
            'message_id' => null,
        ]]);
});

it('defaults max_results to 10 when the argument is absent', function () {
    $fake = new FakeGmailMessagesReader;

    gmail_search_tool($fake)->execute(['query' => 'hello']);

    expect($fake->calls[0]['max_results'])->toBe(BuscarCorreos::DEFAULT_MAX_RESULTS);
});

it('clamps oversized max_results 500 down to the hard cap of 50 without erroring', function () {
    $fake = new FakeGmailMessagesReader;

    $result = gmail_search_tool($fake)->execute(['query' => 'q', 'max_results' => 500]);

    expect($result)->not->toHaveKey('error')
        ->and($fake->calls[0]['max_results'])->toBe(BuscarCorreos::MAX_RESULTS);
});

it('clamps sub-one max_results up to 1', function () {
    $fake = new FakeGmailMessagesReader;

    gmail_search_tool($fake)->execute(['query' => 'q', 'max_results' => -7]);

    expect($fake->calls[0]['max_results'])->toBe(1);
});

it('throws before any reader call when query is empty or whitespace-only', function (mixed $query) {
    $fake = new FakeGmailMessagesReader;

    gmail_search_tool($fake)->execute(['query' => $query]);
})->with([
    'empty' => '',
    'whitespace' => "   \n\t ",
    'missing' => null,
])->throws(InvalidArgumentException::class);

it('never reaches the reader on an invalid query', function () {
    $fake = new FakeGmailMessagesReader;

    try {
        gmail_search_tool($fake)->execute(['query' => '']);
        $this->fail('Expected InvalidArgumentException.');
    } catch (InvalidArgumentException) {
    }

    expect($fake->calls)->toBe([]);
});

it('sanitizes control and NUL bytes and caps the query at 200 characters', function () {
    $fake = new FakeGmailMessagesReader;
    $longQuery = str_repeat('a', 300);

    gmail_search_tool($fake)->execute([
        'query' => "from:\x00amazon.com\x1B\n".$longQuery,
    ]);

    $sent = $fake->calls[0]['query'];

    expect($sent)->not->toContain("\x00")
        ->and($sent)->not->toContain("\x1B")
        ->and(mb_strlen((string) $sent))->toBe(200)
        ->and($sent)->toStartWith('from:amazon.com');
});

it('returns google_not_connected when token refresh fails mid-search', function () {
    $fake = new FakeGmailMessagesReader;
    $fake->searchHandler = fn (): array => throw new GoogleTokenRefreshException('dead grant');

    $result = gmail_search_tool($fake)->execute(['query' => 'from:amazon.com']);

    expect($result)->toBe(['error' => 'google_not_connected']);
});
