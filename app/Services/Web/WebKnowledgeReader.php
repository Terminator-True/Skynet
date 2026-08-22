<?php

namespace App\Services\Web;

interface WebKnowledgeReader
{
    /**
     * @param  string  $query  natural-language factual query
     * @return array{title: string, abstract: string, url: string}|null null = no confident answer
     */
    public function search(string $query): ?array;
}
