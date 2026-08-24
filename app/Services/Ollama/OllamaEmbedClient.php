<?php

namespace App\Services\Ollama;

use Illuminate\Http\Client\ConnectionException as HttpClientConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Live embedding transport to Ollama's native /api/embed endpoint.
 *
 * Mirrors OllamaClient: thin HTTP, typed exception on failure, never a silent
 * fallback. Emits no data beyond the local Ollama base (roadmap §8).
 */
class OllamaEmbedClient implements EmbeddingProvider
{
    /**
     * @return list<float>
     *
     * @throws OllamaConnectionException on unreachable host or non-2xx response
     */
    public function embed(string $text): array
    {
        try {
            $response = Http::timeout(120)
                ->connectTimeout(5)
                ->post(rtrim(config('ollama.base_url'), '/').'/api/embed', [
                    'model' => config('ollama.embed_model'),
                    'input' => $text,
                ]);
        } catch (HttpClientConnectionException $e) {
            throw new OllamaConnectionException(
                'Ollama is not reachable at '.config('ollama.base_url')." — {$e->getMessage()}",
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new OllamaConnectionException(
                "Ollama embed failed (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 300),
            );
        }

        $embedding = $response->json('embeddings.0');

        if (! is_array($embedding)) {
            throw new OllamaConnectionException(
                'Ollama embed response is missing embeddings[0].',
            );
        }

        return array_values(array_map('floatval', $embedding));
    }
}
