<?php

use App\Services\Google\ApiclientCalendarEventsReader;
use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use App\Tools\ListarEventosCalendario;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Tests\Support\FakeCalendarEventsReader;

function calendar_fake(): FakeCalendarEventsReader
{
    return new FakeCalendarEventsReader;
}

function calendar_tool(FakeCalendarEventsReader $fake): Tool
{
    return new ListarEventosCalendario($fake);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{title: string, start: string|null, end: string|null, all_day: bool, location: string|null}
 */
function calendar_event(array $overrides = []): array
{
    return [
        'title' => 'Standup',
        'start' => '2026-08-21T09:00:00-03:00',
        'end' => '2026-08-21T09:15:00-03:00',
        'all_day' => false,
        'location' => null,
        ...$overrides,
    ];
}

it('returns exactly the faked events with the five contract fields', function () {
    $events = [
        calendar_event(['title' => 'A']),
        calendar_event(['title' => 'B', 'location' => 'Room 1']),
        calendar_event(['title' => 'C']),
    ];
    $fake = calendar_fake();
    $fake->handler = fn (): array => $events;

    $result = calendar_tool($fake)->execute([
        'desde' => '2026-08-21',
        'hasta' => '2026-08-22',
    ]);

    expect($result)->toBe(['events' => $events])->and($fake->calls)->toHaveCount(1);
});

it('converts local day boundaries into offset-aware ATOM strings and includes a 23:00 local event', function () {
    $lateEvent = [calendar_event(['start' => '2026-08-21T23:00:00-03:00'])];
    $fake = calendar_fake();
    $fake->handler = fn (): array => $lateEvent;

    $result = calendar_tool($fake)->execute([
        'desde' => '2026-08-21',
        'hasta' => '2026-08-22',
    ]);

    expect($fake->calls[0])->toBe([
        '2026-08-21T00:00:00-03:00',
        '2026-08-22T00:00:00-03:00',
        10,
    ])->and($result['events'])->toBe($lateEvent);
});

it('maps date-only Google events to all_day true and timed ones to false', function () {
    $timed = new Event;
    $timed->setSummary('Standup');
    $timedStart = new EventDateTime;
    $timedStart->setDateTime('2026-08-21T09:00:00-03:00');
    $timedEnd = new EventDateTime;
    $timedEnd->setDateTime('2026-08-21T09:15:00-03:00');
    $timed->setStart($timedStart);
    $timed->setEnd($timedEnd);

    $allDay = new Event;
    $allDay->setSummary('Holiday');
    $start = new EventDateTime;
    $start->setDate('2026-08-21');
    $end = new EventDateTime;
    $end->setDate('2026-08-22');
    $allDay->setStart($start);
    $allDay->setEnd($end);

    expect(ApiclientCalendarEventsReader::mapEvent($timed))->toBe(calendar_event())
        ->and(ApiclientCalendarEventsReader::mapEvent($allDay))->toBe([
            'title' => 'Holiday',
            'start' => '2026-08-21',
            'end' => '2026-08-22',
            'all_day' => true,
            'location' => null,
        ]);
});

it('caps results at 10 events when the range holds more', function () {
    $twentyFive = array_map(fn (int $i): array => calendar_event(["E$i"]), range(1, 25));
    $fake = calendar_fake();
    $fake->handler = fn (): array => $twentyFive;

    $result = calendar_tool($fake)->execute([
        'desde' => '2026-08-21',
        'hasta' => '2026-08-28',
    ]);

    expect($result['events'])->toHaveCount(10)
        ->and(count($twentyFive))->toBe(25);
});

it('returns google_not_connected when token refresh fails', function () {
    $fake = calendar_fake();
    $fake->handler = fn (): array => throw new GoogleTokenRefreshException('dead grant');

    $result = calendar_tool($fake)->execute([
        'desde' => '2026-08-21',
        'hasta' => '2026-08-22',
    ]);

    expect($result)->toBe(['error' => 'google_not_connected']);
});
