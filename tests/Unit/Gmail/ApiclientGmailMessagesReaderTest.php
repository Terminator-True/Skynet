<?php

use App\Services\Gmail\ApiclientGmailMessagesReader;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartBody;

/**
 * Task 1.3 — extractBody matrix (D2): pure static MIME walk, no network.
 * Fixtures are vendor MessagePart objects built offline, mirroring the real
 * google/apiclient payload shapes.
 */

/**
 * @param  array<int, MessagePart>  $parts
 */
function gmail_part(string $mimeType, ?string $data = null, array $parts = []): MessagePart
{
    $part = new MessagePart;
    $part->setMimeType($mimeType);

    if ($data !== null) {
        $body = new MessagePartBody;
        $body->setData($data);
        $part->setBody($body);
    }

    if ($parts !== []) {
        $part->setParts($parts);
    }

    return $part;
}

function gmail_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('returns the text/plain body verbatim after base64url decoding', function () {
    $payload = gmail_part('text/plain', gmail_b64url("Your order shipped!\nTracking: T123"));

    expect(ApiclientGmailMessagesReader::extractBody($payload))
        ->toBe("Your order shipped!\nTracking: T123");
});

it('decodes unpadded base64url containing - and _ characters', function () {
    // Raw bytes whose standard-base64 form contains '+' and '/'.
    $raw = "subjects? \xFB\xFF\xEF\xBF>??";
    $payload = gmail_part('text/plain', rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

    expect(ApiclientGmailMessagesReader::extractBody($payload))->toBe($raw);
});

it('strips an HTML-only message to readable text with entities decoded and whitespace collapsed', function () {
    $html = '<html><body><p>Hola&nbsp;Juan,</p>   <b>Your&#39;s&nbsp;shipped</b></body></html>';
    $payload = gmail_part('text/html', gmail_b64url($html));

    expect(ApiclientGmailMessagesReader::extractBody($payload))
        ->toBe("Hola Juan, Your's shipped");
});

it('prefers text/plain over text/html inside a nested multipart structure', function () {
    $payload = gmail_part('multipart/mixed', null, [
        gmail_part('multipart/alternative', null, [
            gmail_part('text/plain', gmail_b64url('plain wins')),
            gmail_part('text/html', gmail_b64url('<i>html loses</i>')),
        ]),
        gmail_part('application/pdf'),
    ]);

    expect(ApiclientGmailMessagesReader::extractBody($payload))->toBe('plain wins');
});

it('falls back to stripped HTML in a nested multipart when no plain part exists anywhere', function () {
    $payload = gmail_part('multipart/mixed', null, [
        gmail_part('multipart/related', null, [
            gmail_part('text/html', gmail_b64url("<div>Order <b>confirmed</b>\t today</div>")),
        ]),
    ]);

    expect(ApiclientGmailMessagesReader::extractBody($payload))
        ->toBe('Order confirmed today');
});

it('walks deeply nested multipart levels without a depth limit failure', function () {
    $leaf = gmail_part('text/plain', gmail_b64url('deepest leaf'));
    $payload = gmail_part('multipart/mixed', null, [
        gmail_part('multipart/mixed', null, [
            gmail_part('multipart/mixed', null, [
                gmail_part('multipart/mixed', null, [$leaf]),
            ]),
        ]),
    ]);

    expect(ApiclientGmailMessagesReader::extractBody($payload))->toBe('deepest leaf');
});

it('yields an empty string for corrupt base64 data instead of throwing', function () {
    $payload = gmail_part('text/plain', '!!!!not-base64url!!!!');

    expect(ApiclientGmailMessagesReader::extractBody($payload))->toBe('');
});

it('yields an empty string for an empty payload with neither parts nor data', function () {
    expect(ApiclientGmailMessagesReader::extractBody(new MessagePart))->toBe('');
});
