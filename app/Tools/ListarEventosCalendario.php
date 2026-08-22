<?php

namespace App\Tools;

use App\Services\Google\CalendarEventsReader;
use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * First real tool (spec §6 contract {desde, hasta}): reads Google Calendar
 * through the CalendarEventsReader seam. A failed token refresh becomes a
 * structured google_not_connected error so the model can ask for a reconnect.
 */
class ListarEventosCalendario implements Tool
{
    private const MAX_EVENTS = 10;

    public function __construct(private readonly CalendarEventsReader $reader) {}

    public function name(): string
    {
        return 'listar_eventos_calendario';
    }

    public function description(): string
    {
        return 'Lists Google Calendar events between two datetimes. Use whenever the user asks about events, meetings or agenda for any period.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'desde' => ['type' => 'string', 'description' => 'Range start, ISO8601 datetime'],
                'hasta' => ['type' => 'string', 'description' => 'Range end, ISO8601 datetime'],
            ],
            'required' => ['desde', 'hasta'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{events?: list<array{title: string, start: string|null, end: string|null, all_day: bool, location: string|null}>, error?: string}
     */
    public function execute(array $args): array
    {
        foreach (['desde', 'hasta'] as $key) {
            if (! isset($args[$key]) || ! is_string($args[$key])) {
                throw new InvalidArgumentException('listar_eventos_calendario requires datetime arguments desde and hasta.');
            }
        }

        $timezone = config('app.assistant_timezone');
        $from = CarbonImmutable::parse($args['desde'], $timezone);
        $to = CarbonImmutable::parse($args['hasta'], $timezone);

        try {
            $events = $this->reader->eventsBetween(
                $from->format(DATE_ATOM),
                $to->format(DATE_ATOM),
                self::MAX_EVENTS,
            );
        } catch (GoogleTokenRefreshException) {
            return ['error' => 'google_not_connected'];
        }

        return ['events' => array_slice($events, 0, self::MAX_EVENTS)];
    }
}
