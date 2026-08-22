<?php

use App\Models\GoogleToken;
use App\Models\TrackedPackage;
use App\Models\User;
use App\Services\Gmail\GmailMessagesReader;
use App\Tools\ExtraerTrackingAmazon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGmailMessagesReader;

uses(RefreshDatabase::class);

const SHIPPED_FIELDS = [
    'carrier' => 'Amazon Logistics',
    'tracking_number' => 'TBA301042955117',
    'product_name' => 'Kindle Paperwhite',
    'status' => 'shipped',
];

/**
 * Slice B acceptance: the tool runs fully offline — Gmail behind the seam
 * fake, Ollama behind Http::fake — and persists exactly one tracked_packages
 * row per (user, source email), refreshed on re-extraction.
 */
function amazon_tool(): ExtraerTrackingAmazon
{
    return app(ExtraerTrackingAmazon::class);
}

function fake_amazon_email(string $body): FakeGmailMessagesReader
{
    $fake = new FakeGmailMessagesReader;
    $fake->getHandler = fn (): array => [
        'subject' => 'Your package has been shipped!',
        'from' => 'shipment-tracking@amazon.com',
        'date' => 'Fri, 21 Aug 2026 10:00:00 -0300',
        'body' => $body,
    ];

    app()->bind(GmailMessagesReader::class, fn (): FakeGmailMessagesReader => $fake);

    return $fake;
}

function connected_user(): User
{
    $user = User::factory()->create();

    GoogleToken::create([
        'user_id' => $user->id,
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
    ]);

    return $user;
}

it('extracts a shipped email into the structured payload and persists one row', function () {
    $user = connected_user();
    fake_amazon_email('Your Kindle Paperwhite shipped via Amazon Logistics, tracking TBA301042955117.');
    Http::fake(['*/api/chat' => Http::response(['message' => ['content' => json_encode(SHIPPED_FIELDS)]])]);

    $result = amazon_tool()->execute(['message_id' => 'msg-shipped-1']);

    expect($result)->toBe(SHIPPED_FIELDS)
        ->and(TrackedPackage::count())->toBe(1);

    $row = TrackedPackage::first();
    expect($row->user_id)->toBe($user->id)
        ->and($row->source_email_id)->toBe('msg-shipped-1')
        ->and($row->product_name)->toBe('Kindle Paperwhite')
        ->and($row->tracking_number)->toBe('TBA301042955117')
        ->and($row->carrier)->toBe('Amazon Logistics')
        ->and($row->status)->toBe('shipped')
        ->and($row->last_checked_at)->not->toBeNull();
});

it('is idempotent on re-extraction: one row kept, values refreshed', function () {
    connected_user();
    fake_amazon_email('shipped body');
    Http::fake([
        '*/api/chat' => Http::sequence()
            ->push(['message' => ['content' => json_encode(SHIPPED_FIELDS)]])
            ->push(['message' => ['content' => json_encode([...SHIPPED_FIELDS, 'status' => 'out for delivery'])]]),
    ]);

    amazon_tool()->execute(['message_id' => 'msg-x']);
    amazon_tool()->execute(['message_id' => 'msg-x']);

    expect(TrackedPackage::count())->toBe(1)
        ->and(TrackedPackage::first()->status)->toBe('out for delivery');
});

it('persists nothing for a confirmation email without tracking', function () {
    connected_user();
    fake_amazon_email('"Thanks, your order is confirmed." No carrier, no tracking number.');
    // Parseable JSON but every field null -> nothing to track, nothing saved.
    $allNull = json_encode(['carrier' => null, 'tracking_number' => null, 'product_name' => null, 'status' => null]);
    Http::fake(['*/api/chat' => Http::response(['message' => ['content' => $allNull]])]);

    expect(amazon_tool()->execute(['message_id' => 'msg-confirm']))
        ->toBe(['error' => 'no_tracking_found'])
        ->and(TrackedPackage::count())->toBe(0);
});

it('returns google_not_connected without touching the reader when no grant exists', function () {
    $fake = fake_amazon_email('body');

    expect(amazon_tool()->execute(['message_id' => 'msg-y']))
        ->toBe(['error' => 'google_not_connected'])
        ->and($fake->calls)->toBe([])
        ->and(TrackedPackage::count())->toBe(0);
});

it('maps double parse failure to extraction_failed persisting nothing', function () {
    connected_user();
    fake_amazon_email('garbage body');
    Http::fake([
        '*/api/chat' => Http::sequence()
            ->push(['message' => ['content' => 'not json']])
            ->push(['message' => ['content' => 'still not']]),
    ]);

    expect(amazon_tool()->execute(['message_id' => 'msg-z']))
        ->toBe(['error' => 'extraction_failed'])
        ->and(TrackedPackage::count())->toBe(0);
});

it('throws before any reader call when message_id is missing or blank', function (mixed $messageId) {
    $fake = fake_amazon_email('body');

    amazon_tool()->execute(['message_id' => $messageId]);
})->with([
    'missing' => null,
    'blank' => '  ',
])->throws(InvalidArgumentException::class);
