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

    public function __construct(private readonly GoogleClientFactory $factory)
    {
    }

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
     * @return array{title: string, start: string|null, end: string|null, all_day: bool, location: string|null}
     */
    public static function mapEvent(Event $event): array
    {
        $startDateTime = $event->getStart()?->getDateTime();
        $endDateTime = $event->getEnd()?->getDateTime();

        return [
            'title' => $event->getSummary() ?? '',
            'start' => $startDateTime ?? $event->getStart()?->getDate(),
            'end' => $endDateTime ?? $event->getEnd()?->getDate(),
            'all_day' => $startDateTime === null,
            'location' => $event->getLocation(),
        ];
    }
}
