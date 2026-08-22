<?php

namespace Tests\Live;

use App\Services\Google\ApiclientCalendarEventsReader;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenRefreshException;

/**
 * Live Google Calendar round-trip (spec Fase-2 acceptance: "¿qué tengo hoy?"
 * against a connected account).
 *
 * HARD-GATED like GOOGLE_OAUTH_LIVE: skipped unless GOOGLE_CALENDAR_LIVE=1,
 * which the user sets ONLY after the Fase-1 GCP debt is cleared (project +
 * Calendar API enabled + consent screen published + OAuth client with the
 * exact redirect URI + credentials in .env) AND a real account is connected
 * via /connect. Plain `pest` runs NEVER touch Google.
 */
it('lists real primary-calendar events through the apiclient adapter', function () {
    $factory = app(GoogleClientFactory::class);

    // Factory resolution proves the stored grant is alive (lazy refresh path).
    try {
        $client = $factory->resolve();
    } catch (GoogleTokenRefreshException $e) {
        test()->markTestSkipped('No live Google connection: connect via /connect first. '.$e->getMessage());
    }

    expect($client->isAccessTokenExpired())->toBeFalse();

    // Real events.list round-trip over yesterday→tomorrow, capped at 10.
    $now = now();
    $events = (new ApiclientCalendarEventsReader($factory))->eventsBetween(
        $now->subDay()->startOfDay()->format(DATE_ATOM),
        $now->addDays(2)->endOfDay()->format(DATE_ATOM),
        10,
    );

    expect($events)->toBeArray()
        ->and(count($events))->toBeLessThanOrEqual(10)
        ->and(collect($events)->every(
            fn (array $event): bool => isset($event['title'], $event['all_day'])
                && is_string($event['title'])
                && is_bool($event['all_day'])
                && array_key_exists('start', $event)
                && array_key_exists('end', $event)
                && array_key_exists('location', $event),
        ))->toBeTrue();
})->group('live')->skip(
    fn (): bool => ! (bool) env('GOOGLE_CALENDAR_LIVE'),
    'GATE STATUS: PENDING_GCP_CHECKLIST — set GOOGLE_CALENDAR_LIVE=1 only after the Fase-1 GCP debt is cleared and an account is connected.',
);
