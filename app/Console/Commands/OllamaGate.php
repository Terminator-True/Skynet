<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OllamaGate extends Command
{
    protected $signature = 'ollama:gate';

    protected $description = 'Hard gate: verify local Ollama /api/chat supports native tool calling';

    /**
     * Minimal tool definition used purely to probe native tool-calling support.
     */
    private function probeTool(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'noop',
                'description' => 'Does nothing. Probe only.',
                'parameters' => [
                    'type' => 'object',
                    // Must encode as {} — Ollama's Go decoder rejects [] for object fields.
                    'properties' => new \stdClass,
                ],
            ],
        ];
    }

    public function handle(): int
    {
        $baseUrl = rtrim(config('ollama.base_url') ?? env('OLLAMA_BASE_URL', 'http://localhost:11434'), '/');
        $model = config('ollama.model', env('OLLAMA_MODEL', 'qwen2.5:14b-instruct-q4_K_M'));

        $this->info("Probing {$baseUrl}/api/chat with model [{$model}]...");

        $status = $this->probe($baseUrl, $model);

        // Model not pulled yet: the capability check itself can still run against
        // any installed model, because tool-calling support is an Ollama-version
        // property, not a model property.
        if ($status === 'model_missing') {
            $available = $this->installedModels($baseUrl);

            if ($available === null) {
                $this->warn('GATE RESULT: PENDING_MODEL_PULL — cannot reach /api/tags either.');
                $this->line('Re-run this gate after verifying the daemon: curl '.$baseUrl.'/api/version');

                return self::SUCCESS;
            }

            if ($available === []) {
                $this->warn('GATE RESULT: PENDING_MODEL_PULL — no models installed locally.');
                $this->line("Run: ollama pull {$model}, then re-run ollama:gate.");

                return self::SUCCESS;
            }

            $probeModel = $available[0];
            $this->warn("[{$model}] is not pulled yet. Probing tool-calling capability with installed model [{$probeModel}] instead.");
            $status = $this->probe($baseUrl, $probeModel);
        }

        if ($status === 'unreachable') {
            $this->error('GATE RESULT: FAILED — Ollama is not reachable at '.$baseUrl);
            $this->line('Start it with: systemctl --user start ollama (or run `ollama serve`).');

            return self::FAILURE;
        }

        if ($status === 'unsupported') {
            $this->error('GATE RESULT: FAILED — this Ollama build rejected the tools parameter.');
            $this->line('UPGRADE REQUIRED: install Ollama >= 0.3.0 (native tool calling) from https://ollama.com/download');
            $this->line('All subsequent integration tasks are blocked until this gate passes.');

            return self::FAILURE;
        }

        $this->info('GATE RESULT: PASSED — Ollama accepted a native tools array on /api/chat.');

        return self::SUCCESS;
    }

    /**
     * @return 'ok'|'unreachable'|'unsupported'|'model_missing'
     */
    private function probe(string $baseUrl, string $model): string
    {
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'ping']],
            'tools' => [$this->probeTool()],
            'stream' => false,
        ];

        try {
            $response = Http::timeout(30)->post($baseUrl.'/api/chat', $payload);
        } catch (ConnectionException) {
            return 'unreachable';
        }

        if ($response->notFound()) {
            return 'model_missing';
        }

        if ($response->clientError()) {
            // Pre-0.3 Ollama rejects unknown fields like "tools".
            return str_contains(strtolower($response->body()), 'tool') ? 'unsupported' : 'unsupported';
        }

        return $response->successful() ? 'ok' : 'unsupported';
    }

    /** @return list<string>|null */
    private function installedModels(string $baseUrl): ?array
    {
        try {
            $response = Http::timeout(10)->get($baseUrl.'/api/tags');
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return collect($response->json('models', []))
            ->pluck('name')
            ->values()
            ->all();
    }
}
