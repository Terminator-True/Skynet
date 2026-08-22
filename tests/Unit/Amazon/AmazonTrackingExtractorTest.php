<?php

use App\Services\AmazonTrackingExtractor;
use App\Services\Ollama\OllamaClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Decode-ladder unit tests: OllamaClient uses the Http facade, so Http::fake
 * intercepts every extraction call — no client seam needed.
 */
const EXTRACTED_FIELDS = ['carrier' => 'Amazon Logistics', 'tracking_number' => 'TBA123456789000', 'product_name' => 'USB-C Cable', 'status' => 'shipped'];

function ollama_reply(string $content): array
{
    return ['message' => ['content' => $content]];
}

function ollama_fields_reply(): array
{
    return ollama_reply(json_encode(EXTRACTED_FIELDS));
}

function extractor(): AmazonTrackingExtractor
{
    return new AmazonTrackingExtractor(new OllamaClient);
}

it('parses clean JSON output into the four validated fields', function () {
    Http::fake(['*/api/chat' => Http::response(ollama_fields_reply())]);

    expect(extractor()->extract('email body'))->toBe(EXTRACTED_FIELDS);
});

it('tolerates fenced ```json output', function () {
    Http::fake([
        '*/api/chat' => Http::response(ollama_reply("Here is the data:\n```json\n".json_encode(EXTRACTED_FIELDS)."\n```")),
    ]);

    expect(extractor()->extract('body'))->toBe(EXTRACTED_FIELDS);
});

it('tolerates prose-wrapped JSON via first {...} extraction', function () {
    Http::fake([
        '*/api/chat' => Http::response(ollama_reply(
            'Sure! The package details are '.json_encode(EXTRACTED_FIELDS).' — anything else?'
        )),
    ]);

    expect(extractor()->extract('body'))->toBe(EXTRACTED_FIELDS);
});

it('rejects wrong-typed field values and gives up after the single retry', function () {
    // tracking_number as int violates string|null: both attempts invalid.
    Http::fake([
        '*/api/chat' => Http::sequence()
            ->push(ollama_reply(json_encode([
                'carrier' => 'DHL',
                'tracking_number' => 12345,
                'product_name' => null,
                'status' => 'in transit',
            ])))
            ->push(ollama_reply(json_encode([
                'carrier' => 'DHL',
                'tracking_number' => true,
                'product_name' => null,
                'status' => null,
            ]))),
    ]);

    expect(extractor()->extract('body'))->toBeNull();

    Http::assertSentCount(2);
});

it('retries exactly once on unparseable output then gives up with null', function () {
    Http::fake([
        '*/api/chat' => Http::sequence()
            ->push(ollama_reply('total garbage'))
            ->push(ollama_reply('still not json')),
    ]);

    expect(extractor()->extract('body'))->toBeNull();

    Http::assertSentCount(2);
});

it('coerces empty-string fields to null so all-null means no tracking', function () {
    Http::fake([
        '*/api/chat' => Http::response(ollama_reply(json_encode([
            'carrier' => '',
            'tracking_number' => null,
            'product_name' => null,
            'status' => null,
        ]))),
    ]);

    expect(extractor()->extract('order confirmed, nothing to track'))
        ->toBe(['carrier' => null, 'tracking_number' => null, 'product_name' => null, 'status' => null]);
});

it('trims oversized bodies to the configured char cap before prompting', function (int $cap) {
    config(['ollama.extraction_char_cap' => $cap]);

    $captured = [];
    Http::fake(function (Request $request) use (&$captured): PromiseInterface {
        $captured[] = $request->data()['messages'][0]['content'];

        return Http::response(ollama_fields_reply());
    });

    $oversized = str_repeat('<html>x</html>', 5000); // ~75k chars

    expect(extractor()->extract($oversized))->toBe(EXTRACTED_FIELDS)
        // Body is capped; only the short fixed instruction rides on top.
        ->and(mb_strlen((string) end($captured)))->toBeLessThanOrEqual($cap + 300);
})->with([
    'default cap' => 6000,
    'tiny cap' => 100,
]);
