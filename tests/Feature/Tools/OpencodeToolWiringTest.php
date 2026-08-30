<?php

use App\Services\Opencode\HttpOpencodeStatusReader;
use App\Services\Opencode\OpencodeStatusReader;
use App\Tools\ToolRegistry;

/**
 * Phase-3/4 wiring acceptance (R-1, R-7): the container binds the OpenCode
 * seam to the Http adapter, and the shipped registry exposes
 * consultar_estado_opencode. Resolution is construction-only — no network.
 */
it('binds the OpencodeStatusReader seam to the http adapter', function () {
    expect(app(OpencodeStatusReader::class))
        ->toBeInstanceOf(HttpOpencodeStatusReader::class);
});

it('exposes consultar_estado_opencode through the tool registry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('consultar_estado_opencode'))->toBeTrue()
        ->and($registry->get('consultar_estado_opencode')->name())
        ->toBe('consultar_estado_opencode');
});

it('includes consultar_estado_opencode in the tool definitions', function () {
    $registry = app(ToolRegistry::class);

    expect(collect($registry->definitions())->pluck('function.name'))
        ->toContain('consultar_estado_opencode');
});
