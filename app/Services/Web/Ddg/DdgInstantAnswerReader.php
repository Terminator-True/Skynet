<?php

namespace App\Services\Web\Ddg;

use App\Services\Web\WebKnowledgeReader;
use Illuminate\Support\Facades\Http;

class DdgInstantAnswerReader implements WebKnowledgeReader
{
    public function search(string $query): ?array
    {
        $response = Http::timeout((int) config('web.timeout', 10))
            ->get(config('web.ddg_base_url', 'https://api.duckduckgo.com'), [
                'q' => $query, 'format' => 'json', 'no_html' => 1,
                'skip_disambig' => 1, 'kl' => config('web.ddg_lang', 'es-es'),
            ]);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        $abstract = $this->text($data['AbstractText'] ?? null) ?? $this->text($data['Answer'] ?? null);
        if ($abstract === null) {
            return null;
        }
        $url = $this->text($data['AbstractURL'] ?? null);
        if ($url === null) {
            $topics = $data['RelatedTopics'] ?? [];
            $url = is_array($topics) ? $this->firstUrl($topics) : null;
        }
        if ($url === null) {
            return null;
        }

        return [
            'title' => $this->text($data['Heading'] ?? null) ?? $url,
            'abstract' => $abstract,
            'url' => $url,
        ];
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        return $text === '' ? null : $text;
    }

    /** @param  array<int, mixed>  $topics */
    private function firstUrl(array $topics): ?string
    {
        foreach ($topics as $topic) {
            if (is_array($topic) && ($url = $this->text($topic['FirstURL'] ?? null)) !== null) {
                return $url;
            }
        }

        return null;
    }
}
