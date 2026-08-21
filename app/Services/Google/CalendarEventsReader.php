<?php

namespace App\Services\Google;

/**
 * Vendor seam around Google Calendar events.listEvents (Http::fake cannot
 * intercept google/apiclient's internal Guzzle — tests bind fakes here).
 */
interface CalendarEventsReader
{
    /**
     * @param  string  $timeMinAtom  Offset-aware ATOM lower bound
     * @param  string  $timeMaxAtom  Offset-aware ATOM upper bound
     * @return list<array{title: string, start: string, end: string|null, all_day: bool, location: string|null}>
     *
     * @throws GoogleTokenRefreshException no connection or dead grant
     * @throws GoogleApiException          transport / Google API failure
     */
    public function eventsBetween(string $timeMinAtom, string $timeMaxAtom, int $maxResults): array;
}
