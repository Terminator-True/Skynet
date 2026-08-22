<?php

use App\Events\NotificationCreated;
use App\Jobs\CheckForNotifications;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\Rules\AmazonStatusChangeRule;
use App\Notifications\Rules\CalendarEventRule;
use App\Services\Google\CalendarEventsReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCalendarEventsReader;

uses(RefreshDatabase::class);

/**
 * Slice A acceptance: the calendar rule runs fully offline — CalendarEventsReader
 * behind the seam fake (google/apiclient Guzzle bypasses Http::fake), frozen
 * clock via Carbon::setTestNow, and delivery asserted via Event::fake with no
 * Reverb connection (REQ scheduled-dispatch, offline-testability).
 *
 * Laravel 13 removed Broadcast::fake(); ShouldBroadcast dispatch is faked the
 * same way as any event (Event::fake + assertDispatched) with zero network.
 */
function frozen_now(string $atom): void
{
    Carbon::setTestNow($atom);
}

function bind_calendar_reader(array $events): FakeCalendarEventsReader
{
    $fake = new FakeCalendarEventsReader;
    $fake->handler = fn (): array => $events;

    app()->bind(CalendarEventsReader::class, fn (): FakeCalendarEventsReader => $fake);

    return $fake;
}

function upcoming_event(string $startAtom): array
{
    return [
        'title' => 'Team Sync',
        'start' => $startAtom,
        'end' => '2026-08-22T12:45:00-03:00',
        'all_day' => false,
        'location' => 'Room 1',
    ];
}

it('notifies exactly once for an event starting within the next hour', function () {
    frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    bind_calendar_reader([upcoming_event('2026-08-22T12:30:00-03:00')]);

    Event::fake([NotificationCreated::class]);

    app(CheckForNotifications::class)->handle(
        app(CalendarEventRule::class),
        app(AmazonStatusChangeRule::class),
    );

    $notification = Notification::query()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->user_id)->toBe($user->id)
        ->and($notification->type)->toBe('calendar_event')
        ->and($notification->payload['title'])->toBe('Team Sync');

    Event::assertDispatched(
        NotificationCreated::class,
        fn (NotificationCreated $event): bool => $event->userId === $user->id,
    );
});

it('is idempotent across repeated sweeps (no second row for the same key)', function () {
    frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    bind_calendar_reader([upcoming_event('2026-08-22T12:30:00-03:00')]);

    Event::fake([NotificationCreated::class]);

    $rule = app(CalendarEventRule::class);
    $rule->run($user);
    $rule->run($user); // second sweep, same frozen window

    expect(Notification::query()->count())->toBe(1);

    Event::assertDispatchedTimes(NotificationCreated::class, 1);
});

it('skips an event already started before the sweep', function () {
    frozen_now('2026-08-22T12:00:00-03:00');
    $user = User::factory()->create();
    bind_calendar_reader([upcoming_event('2026-08-22T11:30:00-03:00')]); // in the past

    Event::fake([NotificationCreated::class]);

    app(CalendarEventRule::class)->run($user);

    expect(Notification::query()->count())->toBe(0);

    Event::assertNotDispatched(NotificationCreated::class);
});

it('does not run any rule when no owner user exists', function () {
    frozen_now('2026-08-22T12:00:00-03:00');
    bind_calendar_reader([upcoming_event('2026-08-22T12:30:00-03:00')]);

    Event::fake([NotificationCreated::class]);

    app(CheckForNotifications::class)->handle(
        app(CalendarEventRule::class),
        app(AmazonStatusChangeRule::class),
    );

    expect(Notification::query()->count())->toBe(0);
    Event::assertNotDispatched(NotificationCreated::class);
});

it('pushes CheckForNotifications onto the queue as a ShouldQueue job', function () {
    Queue::fake();

    CheckForNotifications::dispatch();

    Queue::assertPushed(CheckForNotifications::class);
});

it('wires the sweep job on a 15-minute schedule in bootstrap/app.php', function () {
    // withSchedule() registers via Artisan::starting — boot Artisan first so
    // the schedule callback is applied, then inspect the registered events.
    $this->artisan('list');

    $schedule = app(Schedule::class);

    $expressions = collect($schedule->events())
        ->map(fn ($event): string => (string) (new ReflectionProperty($event, 'expression'))->getValue($event));

    expect($expressions)->toContain('*/15 * * * *');
});
