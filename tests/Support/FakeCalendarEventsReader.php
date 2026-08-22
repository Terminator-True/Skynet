<?php

namespace Tests\Support;

use App\Services\Google\CalendarEventsReader;
use Closure;

/**
 * Seam fake for CalendarEventsReader (google/apiclient Guzzle bypasses
 * Http::fake). Mutable handler lets each test choose success vs typed
 * failure; calls records exact timeMin/timeMax/maxResults arguments.
 */
class FakeCalendarEventsReader implements CalendarEventsReader
{
    /** @var list<array{string, string, int}> */
    public array $calls = [];

    /** @var Closure(string, string, int): list<array{title:string, start:string|null, end:string|null, all_day:bool, location:string|null}> */
    public Closure $handler;

    public function __construct()
    {
        $this->handler = fn (): array => [];
    }

    public function eventsBetween(string $timeMinAtom, string $timeMaxAtom, int $maxResults): array
    {
        $this->calls[] = [$timeMinAtom, $timeMaxAtom, $maxResults];

        return ($this->handler)($timeMinAtom, $timeMaxAtom, $maxResults);
    }
}
