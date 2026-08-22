<?php

namespace Tests\Support;

use App\Services\Web\WebKnowledgeReader;
use Closure;

class FakeWebKnowledgeReader implements WebKnowledgeReader
{
    /** @var list<string> */
    public array $calls = [];

    /** @var Closure(string): array{title: string, abstract: string, url: string}|null */
    public Closure $searchHandler;

    public function __construct()
    {
        $this->searchHandler = fn (): ?array => null;
    }

    public function search(string $query): ?array
    {
        $this->calls[] = $query;

        return ($this->searchHandler)($query);
    }
}
