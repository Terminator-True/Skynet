<?php

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\TrackedPackage;
use App\Models\User;
use App\Notifications\Rules\AmazonStatusChangeRule;
use App\Services\Gmail\GmailMessagesReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeGmailMessagesReader;

uses(RefreshDatabase::class);

/**
 * Slice B acceptance: the Amazon status-change rule runs fully offline — Gmail
 * behind the seam fake (google/apiclient Guzzle bypasses Http::fake), Ollama
 * behind Http::fake, frozen clock via Carbon::setTestNow, and delivery asserted
 * via Event::fake with no Reverb connection (REQ amazon-status-rule, offline
 * testability).
 */
const RULE_FIELDS = [
    'carrier' => 'Amazon Logistics',
    'tracking_number' => 'TBA301042955117',
    'product_name' => 'Kindle Paperwhite',
    'status' => 'shipped',
];

function rule_frozen_now(string $atom): void
{
    Carbon::setTestNow($atom);
}

/**
 * @param  list<array{id: string}>  $messages
 * @param  array<string, string>  $bodies  body keyed by message id
 */
function bind_amazon_reader(array $messages, array $bodies): FakeGmailMessagesReader
{
    $fake = new FakeGmailMessagesReader;
    $fake->searchHandler = fn (): array => $messages;
    $fake->getHandler = fn (string $id): array => [
        'subject' => 'Your package has shipped!',
        'from' => 'shipment-tracking@amazon.com',
        'date' => 'Fri, 21 Aug 2026 10:00:00 -0300',
        'body' => $bodies[$id] ?? '',
    ];

    app()->bind(GmailMessagesReader::class, fn (): FakeGmailMessagesReader => $fake);

    return $fake;
}

function tracked_package(User $user, string $status): TrackedPackage
{
    return TrackedPackage::create([
        'user_id' => $user->id,
        'source_email_id' => 'msg-1',
        'carrier' => 'Amazon Logistics',
        'tracking_number' => 'TBA301042955117',
        'product_name' => 'Kindle Paperwhite',
        'status' => $status,
        'last_checked_at' => Carbon::parse('2026-08-21T10:00:00-03:00'),
    ]);
}

function ollama_status(string $status): array
{
    return ['message' => ['content' => json_encode([...RULE_FIELDS, 'status' => $status])]];
}

it('notifies once and updates status + last_checked_at on a status change', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    tracked_package($user, 'shipped');

    bind_amazon_reader(
        [['id' => 'msg-1', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null]],
        ['msg-1' => 'your package is out for delivery'],
    );
    Http::fake(['*/api/chat' => Http::response(ollama_status('out for delivery'))]);

    Event::fake([NotificationCreated::class]);

    app(AmazonStatusChangeRule::class)->run($user);

    $package = TrackedPackage::first();
    expect($package->status)->toBe('out for delivery')
        ->and($package->last_checked_at->equalTo(Carbon::parse('2026-08-22T12:00:00-03:00')))->toBeTrue();

    $notification = Notification::first();
    expect($notification)->not->toBeNull()
        ->and($notification->user_id)->toBe($user->id)
        ->and($notification->type)->toBe('package_update')
        ->and($notification->dedupe_key)->toBe('package_update:TBA301042955117:out for delivery')
        ->and($notification->payload['status'])->toBe('out for delivery');

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $event): bool => $event->userId === $user->id,
    );
});

it('does not notify and leaves the row unchanged when status is identical', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    tracked_package($user, 'shipped');

    bind_amazon_reader(
        [['id' => 'msg-1', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null]],
        ['msg-1' => 'still shipped'],
    );
    Http::fake(['*/api/chat' => Http::response(ollama_status('shipped'))]);

    Event::fake([NotificationCreated::class]);

    app(AmazonStatusChangeRule::class)->run($user);

    expect(Notification::query()->count())->toBe(0)
        ->and(TrackedPackage::first()->status)->toBe('shipped');

    Event::assertNotDispatched(NotificationCreated::class);
});

it('dedupes: two emails with the same package status yield a single notification', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    tracked_package($user, 'shipped');

    bind_amazon_reader(
        [
            ['id' => 'msg-1', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null],
            ['id' => 'msg-2', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null],
        ],
        ['msg-1' => 'delivered body 1', 'msg-2' => 'delivered body 2'],
    );
    Http::fake([
        '*/api/chat' => Http::sequence()
            ->push(ollama_status('delivered'))
            ->push(ollama_status('delivered')),
    ]);

    Event::fake([NotificationCreated::class]);

    app(AmazonStatusChangeRule::class)->run($user);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::first()->dedupe_key)->toBe('package_update:TBA301042955117:delivered');

    Event::assertDispatchedTimes(NotificationCreated::class, 1);
});

it('is idempotent across repeated sweeps: one notification for one change', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    tracked_package($user, 'shipped');

    bind_amazon_reader(
        [['id' => 'msg-1', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null]],
        ['msg-1' => 'out for delivery'],
    );
    Http::fake(['*/api/chat' => Http::response(ollama_status('out for delivery'))]);

    Event::fake([NotificationCreated::class]);

    $rule = app(AmazonStatusChangeRule::class);
    $rule->run($user);
    $rule->run($user); // second sweep: stored status now matches extracted

    expect(Notification::query()->count())->toBe(1);

    Event::assertDispatchedTimes(NotificationCreated::class, 1);
});

it('surfaces the message id in the search DTO so bodies can be re-fetched', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    tracked_package($user, 'shipped');

    $fake = bind_amazon_reader(
        [['id' => 'msg-id-42', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null]],
        ['msg-id-42' => 'out for delivery'],
    );
    Http::fake(['*/api/chat' => Http::response(ollama_status('out for delivery'))]);

    app(AmazonStatusChangeRule::class)->run($user);

    expect($fake->calls[0]['op'])->toBe('search')
        ->and($fake->calls[0]['query'])->toBe('from:amazon.com');

    // The rule consumed the id from the search DTO to fetch the body.
    expect($fake->calls)->toContain([
        'op' => 'get',
        'query' => null,
        'max_results' => null,
        'message_id' => 'msg-id-42',
    ]);
});

it('does not run the extraction path when no tracked packages exist', function () {
    rule_frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();

    $fake = bind_amazon_reader(
        [['id' => 'msg-1', 'subject' => 's', 'from' => 'f', 'snippet' => '', 'date' => null]],
        ['msg-1' => 'body'],
    );
    Http::fake(['*/api/chat' => Http::response(ollama_status('shipped'))]);

    Event::fake([NotificationCreated::class]);

    app(AmazonStatusChangeRule::class)->run($user);

    // No packages → rule returns before searching/extracting.
    expect($fake->calls)->toBe([])
        ->and(Notification::query()->count())->toBe(0);

    Event::assertNotDispatched(NotificationCreated::class);
});
