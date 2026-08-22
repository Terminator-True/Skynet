<?php

namespace App\Services\Google;

use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleServiceException;

/**
 * google/apiclient adapter: resolves an authorized client via
 * GoogleClientFactory and queries the primary calendar with trimmed fields.
 */
class ApiclientCalendarEventsReader implements CalendarEventsReader
{
    /** Server-side field trimming keeps event payload out of the LLM context. */
    public const FIELDS = 'items(summary,start,end,location)';

    public function __construct(private readonly GoogleClientFactory $factory) {}

    public function eventsBetween(string $timeMinAtom, string $timeMaxAtom, int $maxResults): array
    {
        $service = new Calendar($this->factory->resolve());

        try {
            $items = $service->events->listEvents('primary', [
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'timeMin' => $timeMinAtom,
                'timeMax' => $timeMaxAtom,
                'maxResults' => $maxResults,
                'fields' => self::FIELDS,
            ])->getItems();
        } catch (GoogleServiceException $e) {
            throw new GoogleApiException(
                'Google Calendar listEvents failed: '.mb_substr($e->getMessage(), 0, 300),
                previous: $e,
            );
        }

        return array_values(array_map(self::mapEvent(...), $items));
    }

    /**
     * Pure DTO mapping (offline unit-testable): date-only events → all_day.
     *
     * Reads raw model data instead of vendor getters because their PHPDoc
     * claims non-nullable returns while unset fields resolve to null at
     * runtime; offsetGet yields mixed and is narrowed explicitly below.
     *
     * @return array{title: string, start: string|null, end: string|null, all_day: bool, location: string|null}
     */
    public static function mapEvent(Event $event): array
    {
        $start = self::node($event, 'start');
        $end = self::node($event, 'end');
        $startDateTime = self::text($start, 'dateTime');

        return [
            'title' => self::text($event, 'summary') ?? '',
            'start' => $startDateTime ?? self::text($start, 'date'),
            'end' => self::text($end, 'dateTime') ?? self::text($end, 'date'),
            'all_day' => $startDateTime === null,
            'location' => self::text($event, 'location'),
        ];
    }

    /**
     * Null-safe string leaf from a Google model node (or the event itself).
     * Handles both raw decoded arrays and hydrated Model objects.
     *
     * @param  \ArrayAccess<array-key, mixed>|array<array-key, mixed>  $source
     */
    private static function text(\ArrayAccess|array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Raw start/end node as an addressable map ([] when absent).
     *
     * @return \ArrayAccess<array-key, mixed>|array<array-key, mixed>
     */
    private static function node(Event $event, string $key): \ArrayAccess|array
    {
        $node = $event[$key];

        if ($node instanceof \ArrayAccess || is_array($node)) {
            return $node;
        }

        return [];
    }
}
