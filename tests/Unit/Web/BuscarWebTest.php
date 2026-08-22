<?php

use App\Tools\BuscarWeb;
use Tests\Support\FakeWebKnowledgeReader;

it('returns the reader card under the tool contract on a confident answer', function () {
    $fake = new FakeWebKnowledgeReader;
    $fake->searchHandler = fn (): ?array => ['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs'];

    $result = (new BuscarWeb($fake))->execute(['consulta' => 'capital de Francia']);

    expect($result)->toBe(['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs'])
        ->and($fake->calls)->toBe(['capital de Francia']);
});

it('returns a structured no_result when the reader finds nothing', function () {
    $fake = new FakeWebKnowledgeReader;

    expect((new BuscarWeb($fake))->execute(['consulta' => 'niche query']))->toBe(['error' => 'no_result']);
});

it('trims the abstract to the configured character cap', function () {
    $fake = new FakeWebKnowledgeReader;
    $fake->searchHandler = fn (): ?array => ['title' => 'T', 'abstract' => str_repeat('a', 3000), 'url' => 'https://example.com'];

    expect(mb_strlen((new BuscarWeb($fake))->execute(['consulta' => 'q'])['abstract']))->toBe((int) config('web.abstract_char_cap'));
});

it('throws before any reader call when consulta is empty or invalid', function (mixed $consulta) {
    $fake = new FakeWebKnowledgeReader;

    (new BuscarWeb($fake))->execute(['consulta' => $consulta]);
})->with([
    'empty' => '',
    'whitespace' => " \n\t ",
    'missing' => null,
])->throws(InvalidArgumentException::class);
