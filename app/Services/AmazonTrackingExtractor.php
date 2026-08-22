<?php

namespace App\Services;

use App\Services\Ollama\OllamaClient;

/**
 * Focused single-purpose LLM extraction (design D5): turns an Amazon email
 * body into the four tracking fields via ONE local OllamaClient call.
 *
 * Model output drifts JSON, so decoding walks a tolerant ladder:
 * raw json_decode -> fenced ```json strip -> first {...} block. Each candidate
 * must validate as {carrier,tracking_number,product_name,status} where every
 * field is a string or null. Unparseable after exactly one retry -> null
 * (the caller maps null to extraction_failed; never an exception).
 */
class AmazonTrackingExtractor
{
    private const INSTRUCTIONS = <<<'TXT'
        Extract package tracking data from the email below.
        Return ONLY a JSON object with keys carrier, tracking_number,
        product_name, status (each a string or null). No prose, no fences.

        EMAIL:
        TXT;

    public function __construct(private readonly OllamaClient $client) {}

    /**
     * @return array{carrier: string|null, tracking_number: string|null, product_name: string|null, status: string|null}|null
     */
    public function extract(string $body): ?array
    {
        // Trim BEFORE prompting so oversized HTML cannot blow past num_ctx.
        $trimmed = mb_substr(trim($body), 0, (int) config('ollama.extraction_char_cap', 6000));

        foreach ([1, 2] as $attempt) {
            $response = $this->client->chat([
                ['role' => 'user', 'content' => self::INSTRUCTIONS."\n".$trimmed],
            ]);

            $fields = $this->decode($response['content']);

            if ($fields !== null) {
                return $fields;
            }
        }

        return null;
    }

    /**
     * @return array{carrier: string|null, tracking_number: string|null, product_name: string|null, status: string|null}|null
     */
    private function decode(string $raw): ?array
    {
        foreach ($this->candidates($raw) as $candidate) {
            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                $fields = $this->validated($decoded);

                if ($fields !== null) {
                    return $fields;
                }
            }
        }

        return null;
    }

    /** @return list<string> raw output plus fence-stripped and {...}-extracted variants */
    private function candidates(string $raw): array
    {
        $candidates = [$raw];

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $fenced) === 1) {
            $candidates[] = $fenced[1];
        }

        if (preg_match('/\{.*\}/s', $raw, $braced) === 1) {
            $candidates[] = $braced[0];
        }

        return $candidates;
    }

    /**
     * Accepts only the four known keys; missing keys coerce to null, anything
     * that is neither string nor null invalidates the whole payload. Empty
     * strings are meaningless data — normalized to null.
     *
     * @param  array<string, mixed>  $decoded
     * @return array{carrier: string|null, tracking_number: string|null, product_name: string|null, status: string|null}|null
     */
    private function validated(array $decoded): ?array
    {
        $fields = [];

        foreach (['carrier', 'tracking_number', 'product_name', 'status'] as $key) {
            $value = $decoded[$key] ?? null;

            if (! is_string($value) && $value !== null) {
                return null;
            }

            $normalized = $value === null ? null : trim($value);
            $fields[$key] = $normalized === '' ? null : $normalized;
        }

        return $fields;
    }
}
