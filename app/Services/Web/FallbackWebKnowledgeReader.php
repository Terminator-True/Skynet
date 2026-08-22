<?php

namespace App\Services\Web;

class FallbackWebKnowledgeReader implements WebKnowledgeReader
{
    /** @var array<string, array{title: string, abstract: string, url: string}|null> */
    private array $cache = [];

    public function __construct(
        private readonly WebKnowledgeReader $ddg,
        private readonly WebKnowledgeReader $wikipedia,
    ) {}

    public function search(string $query): ?array
    {
        $key = mb_strtolower(trim($query));

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->ddg->search($query) ?? $this->wikipedia->search($query);
    }
}
