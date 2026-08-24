<?php

use App\Services\Notes\NotesIndexerService;

it('splits a note on ## headers and folds the H1 preamble into the first section', function () {
    $content = "# Título\n\n## Colas\ncontenido a\n\n## Caché\ncontenido b\n";

    $chunks = NotesIndexerService::chunk($content, 1500);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0])->toContain('# Título')
        ->and($chunks[0])->toContain('## Colas')
        ->and($chunks[0])->not->toContain('## Caché')
        ->and($chunks[1])->toContain('## Caché');
});

it('falls back to char-bounded slices for a headerless note', function () {
    $content = str_repeat('a', 5000);

    $chunks = NotesIndexerService::chunk($content, 1500);

    expect($chunks)->toHaveCount(4)
        ->and(array_map('mb_strlen', $chunks))->each->toBeLessThanOrEqual(1500);
});

it('splits an oversized ## section into char-bounded slices', function () {
    $content = "## Grande\n".str_repeat('b', 4000);

    $chunks = NotesIndexerService::chunk($content, 1500);

    expect($chunks)->toHaveCount(3)
        ->and(array_map('mb_strlen', $chunks))->each->toBeLessThanOrEqual(1500);
});

it('returns an empty list for blank content', function () {
    expect(NotesIndexerService::chunk('   ', 1500))->toBe([]);
});

it('computes a deterministic sha256 content hash', function () {
    $first = NotesIndexerService::contentHash('contenido de prueba');
    $second = NotesIndexerService::contentHash('contenido de prueba');

    expect($first)->toBe($second)
        ->and($first)->not->toBe(NotesIndexerService::contentHash('otro contenido'));
});
