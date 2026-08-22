<?php

use App\Services\Web\Ddg\DdgInstantAnswerReader;
use Illuminate\Support\Facades\Http;

it('maps a DDG Instant Answer card from Heading/AbstractText/AbstractURL', function () {
    Http::fake(['api.duckduckgo.com*' => Http::response(['Heading' => 'París', 'AbstractText' => 'París es la capital de Francia.', 'AbstractURL' => 'https://es.wikipedia.org/wiki/Par%C3%ADs'])]);

    expect((new DdgInstantAnswerReader)->search('capital de Francia'))->toBe(['title' => 'París', 'abstract' => 'París es la capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']);
});

it('falls back to Answer and RelatedTopics FirstURL when AbstractText is empty', function () {
    Http::fake(['api.duckduckgo.com*' => Http::response(['Heading' => 'París', 'AbstractText' => '', 'Answer' => 'La capital de Francia.', 'AbstractURL' => '', 'RelatedTopics' => [['FirstURL' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']]])]);

    expect((new DdgInstantAnswerReader)->search('capital de Francia'))->toBe(['title' => 'París', 'abstract' => 'La capital de Francia.', 'url' => 'https://es.wikipedia.org/wiki/Par%C3%ADs']);
});

it('returns null when the DDG card is empty or has no usable url', function () {
    Http::fake(['api.duckduckgo.com*' => Http::response(['Heading' => '', 'AbstractText' => '', 'Answer' => '', 'AbstractURL' => '', 'RelatedTopics' => []])]);

    expect((new DdgInstantAnswerReader)->search('niche query'))->toBeNull();
});
