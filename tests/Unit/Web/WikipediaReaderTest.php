<?php

use App\Services\Web\Wikipedia\WikipediaReader;
use Illuminate\Support\Facades\Http;

it('maps opensearch + REST summary into a card', function () {
    Http::fake([
        'es.wikipedia.org/w/api.php*' => Http::response(['capital de Francia', ['París'], [''], ['https://es.wikipedia.org/wiki/Par%C3%ADs']]),
        'es.wikipedia.org/api/rest_v1/page/summary/*' => Http::response(['title' => 'París', 'titles' => ['normalized' => 'París'], 'extract' => 'París es la capital de Francia.', 'content_urls' => ['desktop' => ['page' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']]]),
    ]);

    expect((new WikipediaReader)->search('capital de Francia'))->toBe(['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']);
});

it('returns null when opensearch yields no title', function () {
    Http::fake(['es.wikipedia.org/w/api.php*' => Http::response(['zzz', [], [], []])]);

    expect((new WikipediaReader)->search('zzz'))->toBeNull();
});

it('returns null on a summary 404', function () {
    Http::fake([
        'es.wikipedia.org/w/api.php*' => Http::response(['x', ['París'], [''], ['u']]),
        'es.wikipedia.org/api/rest_v1/*' => Http::response([], 404),
    ]);

    expect((new WikipediaReader)->search('capital de Francia'))->toBeNull();
});

it('returns null when the summary extract is empty (disambiguation or no-content)', function () {
    Http::fake([
        'es.wikipedia.org/w/api.php*' => Http::response(['x', ['París'], [''], ['u']]),
        'es.wikipedia.org/api/rest_v1/*' => Http::response(['title' => 'París', 'titles' => ['normalized' => 'París'], 'extract' => '', 'content_urls' => ['desktop' => ['page' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']]]),
    ]);

    expect((new WikipediaReader)->search('capital de Francia'))->toBeNull();
});
