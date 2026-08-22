<?php

use App\Events\NotificationCreated;
use App\Models\User;
use App\Notifications\Rules\CalendarEventRule;
use App\Services\Google\CalendarEventsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeCalendarEventsReader;

uses(RefreshDatabase::class);

/**
 * Slice C acceptance: realtime delivery over the private channel (REQ
 * realtime-delivery) — `/broadcasting/auth` resolves the single owner, and
 * NotificationCreated broadcasts a normalized toast payload on
 * `private-notifications.{userId}`. Fully offline (Event::fake +
 * FakeCalendarEventsReader; no Reverb connection).
 *
 * Channel auth verification only runs on a real broadcaster (the `null` driver
 * skips it), so these tests pin the default to `reverb`, which uses the Pusher
 * broadcaster under the hood.
 */
function realtime_calendar_reader(array $events): FakeCalendarEventsReader
{
    $fake = new FakeCalendarEventsReader;
    $fake->handler = fn (): array => $events;

    app()->bind(CalendarEventsReader::class, fn (): FakeCalendarEventsReader => $fake);

    return $fake;
}

it('authorizes the single owner on their own private-notifications channel', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-notifications.{$user->id}",
        ])
        ->assertOk();
});

it('denies a private-notifications channel for another user id', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-notifications.{$other->id}",
        ])
        ->assertForbidden();
});

it('broadcasts NotificationCreated on the private channel with a normalized toast payload', function () {
    Carbon::setTestNow('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    realtime_calendar_reader([
        [
            'title' => 'Team Sync',
            'start' => '2026-08-22T12:30:00-03:00',
            'end' => '2026-08-22T12:45:00-03:00',
            'all_day' => false,
            'location' => 'Room 1',
        ],
    ]);

    Event::fake([NotificationCreated::class]);

    app(CalendarEventRule::class)->run($user);

    $event = Event::dispatched(NotificationCreated::class)->first()[0];

    expect($event)->not->toBeNull()
        ->and($event->userId)->toBe($user->id)
        ->and($event->broadcastOn()->name)->toBe("private-notifications.{$user->id}");

    // broadcastWith() is the exact wire payload the Echo toast receives.
    $payload = $event->broadcastWith();

    expect($payload)->toHaveKeys(['id', 'title', 'body', 'created_at'])
        ->and($payload['id'])->toBeInt()
        ->and($payload['title'])->toBe('Team Sync')
        ->and($payload['body'])->toBe('Starts at 2026-08-22T12:30:00-03:00 — Room 1')
        ->and($payload['created_at'])->not->toBeNull();
});
