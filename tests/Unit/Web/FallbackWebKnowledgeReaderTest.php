<?php

use App\Services\Web\Ddg\DdgInstantAnswerReader;
use App\Services\Web\FallbackWebKnowledgeReader;
use App\Services\Web\Wikipedia\WikipediaReader;
use Illuminate\Support\Facades\Http;

function fallback_reader(): FallbackWebKnowledgeReader
{
    return new FallbackWebKnowledgeReader(new DdgInstantAnswerReader, new WikipediaReader);
}

it('returns the DDG card when the primary source is confident', function () {
    Http::fake(['api.duckduckgo.com*' => Http::response(['Heading' => 'París', 'AbstractText' => 'París es la capital de Francia.', 'AbstractURL' => 'https://es.wikipedia.org/wiki/Par%C3%ADs'])]);

    expect(fallback_reader()->search('capital de Francia'))->toBe(['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']);
});

it('falls through to Wikipedia when DDG is empty', function () {
    Http::fake([
        'api.duckduckgo.com*' => Http::response(['Heading' => '', 'AbstractText' => '', 'Answer' => '', 'AbstractURL' => '']),
        'es.wikipedia.org/w/api.php*' => Http::response(['capital de Francia', ['París'], [''], ['https://es.wikipedia.org/wiki/Par%C3%ADs']]),
        'es.wikipedia.org/api/rest_v1/page/summary/*' => Http::response(['title' => 'París', 'titles' => ['normalized' => 'París'], 'extract' => 'París es la capital de Francia.', 'content_urls' => ['desktop' => ['page' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']]]),
    ]);

    expect(fallback_reader()->search('capital de Francia'))->toBe(['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']);
});

it('returns null when both sources are empty', function () {
    Http::fake([
        'api.duckduckgo.com*' => Http::response(['Heading' => '', 'AbstractText' => '', 'Answer' => '', 'AbstractURL' => '']),
        'es.wikipedia.org/w/api.php*' => Http::response(['x', [], [], []]),
    ]);

    expect(fallback_reader()->search('niche'))->toBeNull();
});
