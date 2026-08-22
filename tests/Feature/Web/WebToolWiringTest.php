<?php

use App\Services\Web\FallbackWebKnowledgeReader;
use App\Services\Web\WebKnowledgeReader;
use App\Tools\ToolRegistry;

it('binds the seam to the composite and exposes buscar_web', function () {
    expect(app(WebKnowledgeReader::class))->toBeInstanceOf(FallbackWebKnowledgeReader::class);

    $registry = app(ToolRegistry::class);

    expect($registry->has('buscar_web'))->toBeTrue()
        ->and(collect($registry->definitions())->pluck('function.name'))->toContain('buscar_web');
});
