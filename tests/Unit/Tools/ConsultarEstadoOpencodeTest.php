<?php

use App\Services\Opencode\Exceptions\OpencodeConnectionException;
use App\Services\Opencode\OpencodeStatusReader;
use App\Tools\ConsultarEstadoOpencode;

function opencodeReaderHarness(callable $behavior): OpencodeStatusReader
{
    return new class($behavior) implements OpencodeStatusReader
    {
        public function __construct(private readonly Closure $behavior) {}

        public function status(?string $sessionId = null): array
        {
            return ($this->behavior)($sessionId);
        }
    };
}

it('returns a friendly message when there are no active sessions (R-4)', function () {
    $tool = new ConsultarEstadoOpencode(opencodeReaderHarness(fn () => []));

    expect($tool->execute([]))->toBe([
        'status' => 'ok',
        'sessions' => [],
        'message' => 'No hay sesiones activas de OpenCode.',
    ]);
});

it('maps a reader connection failure to opencode_unavailable (R-5)', function () {
    $tool = new ConsultarEstadoOpencode(
        opencodeReaderHarness(fn () => throw new OpencodeConnectionException('server down')),
    );

    expect($tool->execute([]))->toBe(['error' => 'opencode_unavailable']);
});

it('normalizes and caps the session list', function () {
    config()->set('opencode.max_sessions', 3);
    config()->set('opencode.session_summary_char_cap', 10);

    $sessions = collect(range(1, 30))->map(fn (int $i): array => [
        'id' => "sess-{$i}",
        'title' => "Job {$i}",
        'status' => 'running',
        'last_activity' => 1000 + $i,
        'summary' => str_repeat('x', 900),
    ])->all();

    $tool = new ConsultarEstadoOpencode(opencodeReaderHarness(fn () => $sessions));

    $result = $tool->execute([]);

    expect($result['status'])->toBe('ok')
        ->and(count($result['sessions']))->toBe(3)
        ->and(mb_strlen($result['sessions'][0]['summary']))->toBe(10);
});

it('forwards the optional session_id to the reader', function () {
    $tool = new ConsultarEstadoOpencode(
        opencodeReaderHarness(fn (?string $id) => [['id' => $id ?? '', 'title' => 'T', 'status' => 'running', 'last_activity' => 1, 'summary' => 'S']]),
    );

    $result = $tool->execute(['session_id' => 'sess-42']);

    expect($result['sessions'][0]['id'])->toBe('sess-42');
});
