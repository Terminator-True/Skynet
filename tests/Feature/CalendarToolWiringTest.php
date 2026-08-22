<?php

use App\Services\Google\ApiclientCalendarEventsReader;
use App\Services\Google\CalendarEventsReader;
use App\Services\Google\GoogleClientFactory;
use App\Tools\ToolRegistry;

/**
 * Phase-3 wiring acceptance (tasks 3.3): the container resolves the calendar
 * adapter through GoogleClientFactory, and the shipped registry exposes
 * listar_eventos_calendario. Resolution is construction-only — no network.
 */
it('binds the CalendarEventsReader seam to the apiclient adapter', function () {
    expect(app(CalendarEventsReader::class))
        ->toBeInstanceOf(ApiclientCalendarEventsReader::class);
});

it('resolves the adapter through the GoogleClientFactory dependency chain', function () {
    $adapter = app(ApiclientCalendarEventsReader::class);

    $factory = (new ReflectionProperty(
        ApiclientCalendarEventsReader::class,
        'factory',
    ))->getValue($adapter);

    expect($factory)->toBeInstanceOf(GoogleClientFactory::class);
});

it('exposes listar_eventos_calendario through the tool registry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('listar_eventos_calendario'))->toBeTrue()
        ->and($registry->get('listar_eventos_calendario')->name())
        ->toBe('listar_eventos_calendario');
});
