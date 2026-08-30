<?php

namespace App\Tools;

use App\Services\Opencode\Exceptions\OpencodeConnectionException;
use App\Services\Opencode\OpencodeStatusReader;
use App\Tools\Contracts\Tool;

/**
 * consultar_estado_opencode: reports the status of the user's local OpenCode
 * sessions/agent jobs in natural language (Spanish) for the model to summarize.
 * A down or non-2xx server translates to a structured 'opencode_unavailable'
 * so the conversation never breaks (R-5).
 */
final class ConsultarEstadoOpencode implements Tool
{
    public function __construct(private readonly OpencodeStatusReader $reader) {}

    public function name(): string
    {
        return 'consultar_estado_opencode';
    }

    public function description(): string
    {
        return 'Consulta el estado de tus sesiones/agentes de OpenCode en el servidor local y lo resume en lenguaje natural. Preguntas como "¿cómo van mis agentes de OpenCode?" o "¿qué está haciendo OpenCode?".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session_id' => [
                    'type' => 'string',
                    'description' => 'Opcode local session id para reportar una sola sesión en lugar de todas. Opcional.',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status?: string, sessions?: list<array<string, mixed>>, message?: string, error?: string}
     */
    public function execute(array $args): array
    {
        $sessionId = is_string($args['session_id'] ?? null) ? $args['session_id'] : null;

        try {
            $sessions = $this->reader->status($sessionId);
        } catch (OpencodeConnectionException) {
            return ['error' => 'opencode_unavailable'];
        }

        if ($sessions === []) {
            return [
                'status' => 'ok',
                'sessions' => [],
                'message' => 'No hay sesiones activas de OpenCode.',
            ];
        }

        $capped = array_slice($sessions, 0, (int) config('opencode.max_sessions', 20));
        $capped = array_map(
            fn (array $s): array => array_merge($s, [
                'summary' => mb_substr((string) $s['summary'], 0, (int) config('opencode.session_summary_char_cap', 500)),
            ]),
            $capped,
        );

        return ['status' => 'ok', 'sessions' => $capped];
    }
}
