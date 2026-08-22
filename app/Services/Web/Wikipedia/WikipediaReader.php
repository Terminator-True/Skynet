<?php

namespace App\Services\Web\Wikipedia;

use App\Services\Web\WebKnowledgeReader;
use Illuminate\Support\Facades\Http;

class WikipediaReader implements WebKnowledgeReader
{
    public function search(string $query): ?array
    {
        $base = rtrim(config('web.wiki_base_url', 'https://es.wikipedia.org'), '/');

        $opensearch = Http::timeout((int) config('web.timeout', 10))
            ->get($base.'/w/api.php', [
                'action' => 'opensearch', 'search' => $query,
                'limit' => 1, 'namespace' => 0, 'format' => 'json',
            ]);

        if ($opensearch->failed()) {
            return null;
        }

        $data = $opensearch->json();
        $title = is_array($data) ? $this->title($data[1] ?? null) : null;
        if ($title === null) {
            return null;
        }

        $summary = Http::timeout((int) config('web.timeout', 10))
            ->get($base.'/api/rest_v1/page/summary/'.rawurlencode($title));

        if ($summary->failed()) {
            return null;
        }

        $info = $summary->json();
        if (! is_array($info)) {
            return null;
        }

        $abstract = $this->text($info['extract'] ?? null);
        if ($abstract === null) {
            return null;
        }

        $titles = $info['titles'] ?? null;
        $normalized = $this->text(is_array($titles) ? ($titles['normalized'] ?? null) : null);

        $contentUrls = $info['content_urls'] ?? null;
        $desktop = is_array($contentUrls) ? ($contentUrls['desktop'] ?? null) : null;
        $url = $this->text(is_array($desktop) ? ($desktop['page'] ?? null) : null);

        if ($url === null) {
            return null;
        }

        return ['title' => $normalized ?? $title, 'abstract' => $abstract, 'url' => $url];
    }

    private function title(mixed $titles): ?string
    {
        return $this->text(is_array($titles) && isset($titles[0]) ? $titles[0] : null);
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }
}
