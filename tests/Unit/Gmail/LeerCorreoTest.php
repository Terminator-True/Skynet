<?php

use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use App\Tools\LeerCorreo;
use Tests\Support\FakeGmailMessagesReader;

function gmail_read_tool(FakeGmailMessagesReader $fake): Tool
{
    return new LeerCorreo($fake);
}

function gmail_full_message(array $overrides = []): array
{
    return [
        'subject' => 'Your order has shipped',
        'from' => 'shipment@amazon.com',
        'date' => 'Fri, 21 Aug 2026 10:00:00 -0300',
        'body' => "Hi!\n\nYour package is on the way.\n",
        ...$overrides,
    ];
}

it('returns the decoded body for the requested message id', function () {
    $message = gmail_full_message(['body' => 'decoded body text']);
    $fake = new FakeGmailMessagesReader;
    $fake->getHandler = fn (): array => $message;

    $result = gmail_read_tool($fake)->execute(['message_id' => '18f3a9c2']);

    expect($result)->toBe(['body' => 'decoded body text'])
        ->and($fake->calls)->toBe([[
            'op' => 'get',
            'query' => null,
            'max_results' => null,
            'message_id' => '18f3a9c2',
        ]]);
});

it('throws before any reader call when message_id is missing, blank or not a string', function (mixed $messageId) {
    $fake = new FakeGmailMessagesReader;

    gmail_read_tool($fake)->execute(['message_id' => $messageId]);
})->with([
    'missing' => null,
    'blank' => '   ',
    'integer' => 42,
])->throws(InvalidArgumentException::class);

it('never reaches the reader when message_id validation fails', function () {
    $fake = new FakeGmailMessagesReader;

    try {
        gmail_read_tool($fake)->execute([]);
        $this->fail('Expected InvalidArgumentException.');
    } catch (InvalidArgumentException) {
    }

    expect($fake->calls)->toBe([]);
});

it('returns google_not_connected when token refresh fails mid-read', function () {
    $fake = new FakeGmailMessagesReader;
    $fake->getHandler = fn (): array => throw new GoogleTokenRefreshException('dead grant');

    $result = gmail_read_tool($fake)->execute(['message_id' => 'abc']);

    expect($result)->toBe(['error' => 'google_not_connected']);
});
