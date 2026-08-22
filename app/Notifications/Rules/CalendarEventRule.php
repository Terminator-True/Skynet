<?php

namespace App\Notifications\Rules;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use App\Services\Google\CalendarEventsReader;
use Carbon\CarbonImmutable;

/**
 * Fase 5 calendar rule (REQ calendar-event-rule): notify once per event
 * whose start falls within [now, now+1h]. Reuses the CalendarEventsReader
 * vendor seam, so tests bind FakeCalendarEventsReader instead of hitting the
 * apiclient (Http::fake cannot intercept google/apiclient's internal Guzzle).
 *
 * Dedupe key = sha256 of normalized title + start atom, because Google Calendar
 * events expose no stable id. firstOrCreate + UNIQUE(user_id, dedupe_key) make
 * repeated sweeps idempotent; a notification only broadcasts on first insert.
 */
class CalendarEventRule
{
    private const MAX_EVENTS = 50;

    public function __construct(private readonly CalendarEventsReader $reader) {}

    public function run(User $user): void
    {
        $timezone = config('app.assistant_timezone');
        $now = CarbonImmutable::now($timezone);

        $events = $this->reader->eventsBetween(
            $now->format(DATE_ATOM),
            $now->addHour()->format(DATE_ATOM),
            self::MAX_EVENTS,
        );

        foreach ($events as $event) {
            $start = CarbonImmutable::parse($event['start'], $timezone);

            // Skip events already started before this sweep.
            if ($start->lessThan($now)) {
                continue;
            }

            $dedupeKey = hash('sha256', mb_strtolower(trim($event['title'])).'|'.$event['start']);

            $notification = Notification::firstOrCreate(
                ['user_id' => $user->id, 'dedupe_key' => $dedupeKey],
                [
                    'type' => 'calendar_event',
                    'payload' => [
                        'title' => $event['title'],
                        'start' => $event['start'],
                        'end' => $event['end'],
                        'location' => $event['location'],
                        'all_day' => $event['all_day'],
                    ],
                ],
            );

            if ($notification->wasRecentlyCreated) {
                NotificationCreated::dispatch($user->id, $notification->payload);
            }
        }
    }
}
