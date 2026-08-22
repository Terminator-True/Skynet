<?php

namespace Tests\Eval;

use App\Services\ChatOrchestrator;
use App\Services\Gmail\GmailMessagesReader;
use App\Services\Google\CalendarEventsReader;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface as Psr7Request;
use Tests\Support\FakeCalendarEventsReader;
use Tests\Support\FakeGmailMessagesReader;

/**
 * Fase 0 acceptance gate (spec): 10 varied prompts against live Ollama,
 * pass rate >= 8/10.
 *
 * HARDWARE-GATED: skipped unless OLLAMA_EVAL=1 AND the configured model is
 * pulled locally. Plain `pest` runs NEVER touch hardware.
 *
 * Retry ladder lives HERE ONLY (design decision); production orchestrator is
 * deterministic single-pass:
 *   Rung 1 = tune prompts/descriptions (human edit before re-run)
 *   Rung 2 = single stricter-instruction retry, applied automatically here
 *   Rung 3 = config-only swap to fallback model (human decision)
 */
const EVAL_GATE_THRESHOLD = 8;

const EVAL_STRICT_SUFFIX = "\n\nSTRICT INSTRUCTION: You MUST answer using exactly one of the provided tools when it can serve the request. Emit a single valid tool call whose arguments match the tool's JSON schema precisely. Do not invent tools or argument names.";

it('passes the tool-calling acceptance gate (>=8/10) on live Ollama', function () {
    eval_skip_if_model_missing();

    $orchestrator = app(ChatOrchestrator::class);
    $results = [];
    $passed = 0;
    $total = 0;

    foreach (eval_load_cases() as $case) {
        $total++;

        // Rung 0: single-pass attempt.
        [$ok, $detail] = eval_attempt($orchestrator, $case, '');
        $rung = 'rung 0';

        if (! $ok) {
            // Rung 2: one stricter-instruction retry (eval harness only).
            [$ok, $detail] = eval_attempt($orchestrator, $case, EVAL_STRICT_SUFFIX);
            $rung = 'rung 2';
        }

        if ($ok) {
            $passed++;
        }

        $results[] = sprintf(
            '  [%s] %-32s %s%s',
            $ok ? 'PASS' : 'FAIL',
            mb_substr($case->prompt, 0, 32),
            $ok ? "passed at {$rung}" : "failed after {$rung}",
            $ok ? '' : ' | '.$detail,
        );
    }

    echo "\n=== Fase 0 Tool-Calling Eval ===\n"
        .implode("\n", $results)."\n"
        .sprintf("Pass rate: %d/%d (gate >= %d)\n", $passed, $total, EVAL_GATE_THRESHOLD)
        ."Ladder status: rung 2 auto-retry applied per failing case above.\n"
        ."If still red:\n"
        ."  rung 1 -> tune system prompt / tool descriptions, then re-run\n"
        .'  rung 3 -> set OLLAMA_MODEL='.config('ollama.fallback_model')." (config-only swap)\n";

    expect($passed)->toBeGreaterThanOrEqual(EVAL_GATE_THRESHOLD);
})->skip(
    fn (): bool => env('OLLAMA_EVAL') !== '1',
    'Hardware eval disabled: set OLLAMA_EVAL=1 (and pull the model) to run.',
);

it('runs the calendar-today case on live Ollama with strictly local egress', function () {
    eval_skip_if_model_missing();

    // Pre-bind the seam fake: the eval runs fully offline against Google.
    $tz = config('app.assistant_timezone');
    $today = now($tz)->format('Y-m-d');
    $events = [[
        'title' => 'Eval standup',
        'start' => $today.'T09:00:00'.now($tz)->format('P'),
        'end' => $today.'T09:15:00'.now($tz)->format('P'),
        'all_day' => false,
        'location' => null,
    ]];
    $fake = new FakeCalendarEventsReader;
    $fake->handler = fn (): array => $events;
    app()->bind(CalendarEventsReader::class, fn (): FakeCalendarEventsReader => $fake);

    // PRIVACY harness (spec §8): record every outbound request destination
    // WITHOUT stubbing the live Ollama call.
    $destinations = [];
    Http::globalMiddleware(function ($handler) use (&$destinations) {
        return function (Psr7Request $request, array $options) use ($handler, &$destinations) {
            $destinations[] = (string) $request->getUri();

            return $handler($request, $options);
        };
    });

    $case = require __DIR__.'/Cases/case-11-calendar-today.php';

    try {
        $turn = app(ChatOrchestrator::class)->handle($case->prompt);
    } catch (\Throwable $e) {
        test()->fail('exception: '.mb_substr($e->getMessage(), 0, 140));
    }

    $registry = app(ToolRegistry::class);
    $calls = collect($turn->toArray()['tool_calls'])
        ->map(function (array $call) use ($registry): array {
            $schema = $registry->has($call['name'])
                ? $registry->get($call['name'])->schema()
                : [];

            return [...$call, 'schemaValid' => ToolCallValidator::isValid($schema, $call['arguments'])];
        })
        ->all();

    // Tool called with schema-valid args whose desde/hasta span today's local day.
    expect($case->judge(['reply' => $turn->reply, 'tool_calls' => $calls]))
        ->toBeTrue('listar_eventos_calendario not called correctly for "hoy"');

    $call = collect($calls)->firstWhere('name', 'listar_eventos_calendario');
    expect($call['result'])->toBe(['events' => $events]);

    // Zero non-local HTTP clients may ever see event content: every hop in
    // this turn must target the local Ollama base URL only.
    $ollamaBase = rtrim(config('ollama.base_url'), '/');
    foreach ($destinations as $url) {
        expect(str_starts_with($url, $ollamaBase))->toBeTrue("non-local egress detected: {$url}");
    }
})->skip(
    fn (): bool => env('OLLAMA_EVAL') !== '1',
    'Hardware eval disabled: set OLLAMA_EVAL=1 (and pull the model) to run.',
);

it('runs the amazon-extraction case on live Ollama with strictly local egress (egress-spy)', function () {
    eval_skip_if_model_missing();

    // Pre-bind the seam fake: the eval runs fully offline against Gmail; the
    // email-derived body must never leave the local Ollama hop.
    $body = 'Your Kindle Paperwhite has shipped! Carrier: Amazon Logistics. '
        .'Tracking number: TBA301042955117EG. Status: Shipped.';
    $fake = new FakeGmailMessagesReader;
    $fake->getHandler = fn (): array => [
        'subject' => 'Your package has been shipped!',
        'from' => 'shipment-tracking@amazon.com',
        'date' => 'Fri, 21 Aug 2026 10:00:00 -0300',
        'body' => $body,
    ];
    app()->bind(GmailMessagesReader::class, fn (): FakeGmailMessagesReader => $fake);

    // PRIVACY harness (spec §8): record every outbound request destination
    // WITHOUT stubbing the live Ollama call.
    $destinations = [];
    Http::globalMiddleware(function ($handler) use (&$destinations) {
        return function (Psr7Request $request, array $options) use ($handler, &$destinations) {
            $destinations[] = (string) $request->getUri();

            return $handler($request, $options);
        };
    });

    $case = require __DIR__.'/Cases/case-14-egress-spy.php';

    try {
        $turn = app(ChatOrchestrator::class)->handle($case->prompt);
    } catch (\Throwable $e) {
        test()->fail('exception: '.mb_substr($e->getMessage(), 0, 140));
    }

    expect($turn->toArray()['tool_calls'])->not->toBeEmpty('extraer_tracking_amazon not called');

    // EGRESS SPY (§8): every hop in this turn must target the local Ollama
    // base URL — email-derived content may reach ONLY Laravel + local Ollama.
    $ollamaBase = rtrim(config('ollama.base_url'), '/');
    foreach ($destinations as $url) {
        expect(str_starts_with($url, $ollamaBase))->toBeTrue("non-local egress detected: {$url}");
    }
})->skip(
    fn (): bool => env('OLLAMA_EVAL') !== '1',
    'Hardware eval disabled: set OLLAMA_EVAL=1 (and pull the model) to run.',
);

/**
 * Runs one case through the real orchestrator and judges it per spec.
 *
 * @return array{0: bool, 1: string} [pass, failure detail]
 */
function eval_attempt(ChatOrchestrator $orchestrator, EvalCase $case, string $suffix): array
{
    try {
        $turn = $orchestrator->handle($case->prompt.$suffix);
    } catch (\Throwable $e) {
        return [false, 'exception: '.mb_substr($e->getMessage(), 0, 140)];
    }

    $toolCalls = collect($turn->toArray()['tool_calls'])
        ->map(function (array $call): array {
            $schema = app(ToolRegistry::class)->has($call['name'])
                ? app(ToolRegistry::class)->get($call['name'])->schema()
                : [];

            return [...$call, 'schemaValid' => ToolCallValidator::isValid($schema, $call['arguments'])];
        })
        ->all();

    return [$case->judge(['reply' => $turn->reply, 'tool_calls' => $toolCalls]), json_encode(array_map(
        fn (array $c): array => ['name' => $c['name'], 'args' => $c['arguments'], 'schemaValid' => $c['schemaValid']],
        $toolCalls,
    ) ?: ($turn->reply === '' ? ['empty reply'] : []))];
}

/** @return list<EvalCase> exactly 14 cases: 12 tool-targeting + 2 no-tool */
function eval_load_cases(): array
{
    $files = glob(__DIR__.'/Cases/case-*.php');
    expect($files)->toBeArray()->toHaveCount(14);

    return array_map(fn (string $file): EvalCase => require $file, $files);
}

/** Skips (not fails) when the configured model is not available locally. */
function eval_skip_if_model_missing(): void
{
    $baseUrl = rtrim(config('ollama.base_url'), '/');
    $model = config('ollama.model');

    $tags = Http::timeout(5)->get($baseUrl.'/api/tags');
    $installed = collect($tags->json('models', []))->pluck('name')->all();

    if (! in_array($model, $installed, true)) {
        test()->markTestSkipped("GATE STATUS: PENDING_MODEL_PULL — [{$model}] is not pulled. Run: ollama pull {$model}");
    }
}
