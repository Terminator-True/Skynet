<?php

namespace App\Services\Opencode;

use App\Services\Opencode\Exceptions\OpencodeConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Laravel Http adapter for the local OpenCode headless server. Http:: (not
 * apiclient) so Http::fake intercepts it in tests and the seam can be bound
 * in the container. Field names follow the OpenCode schema defensively
 * (id ?? sessionID ?? session_id) because the live OpenAPI at /doc is the
 * only source of truth — re-verify if the server schema evolves.
 */
class HttpOpencodeStatusReader implements OpencodeStatusReader
{
    /**
     * @return list<array{id: string, title: string, status: string, last_activity: mixed, summary: string}>
     *
     * @throws OpencodeConnectionException
     */
    public function status(?string $sessionId = null): array
    {
        $base = rtrim((string) config('opencode.base_url', 'http://127.0.0.1:4096'), '/');
        $path = $sessionId !== null
            ? '/session/'.rawurlencode($sessionId)
            : '/session/status';

        $request = Http::connectTimeout((int) config('opencode.connect_timeout', 3))
            ->timeout((int) config('opencode.timeout', 5));

        // Basic Auth only when a password is configured, so setups without
        // auth are never broken by a stray empty Authorization header (R-6).
        if ((string) config('opencode.basic_auth_password', '') !== '') {
            $request = $request->withBasicAuth(
                (string) config('opencode.basic_auth_user', 'opencode'),
                (string) config('opencode.basic_auth_password'),
            );
        }

        try {
            $response = $request->get($base.$path);
        } catch (\Throwable $e) {
            // Connection refused, timeout, DNS — any transport failure wraps
            // into our domain exception (R-5).
            throw new OpencodeConnectionException('OpenCode unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new OpencodeConnectionException('OpenCode non-2xx: '.$response->status());
        }

        return $this->map($response->json(), $sessionId !== null);
    }

    /**
     * Defensive mapping against the real OpenCode schema. List root may be
     * sessions|items|data; a single-session payload arrives as one object.
     *
     * @param  mixed  $payload
     * @return list<array{id: string, title: string, status: string, last_activity: mixed, summary: string}>
     */
    private function map(mixed $payload, bool $single): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if ($single) {
            return [$this->mapSession($payload)];
        }

        $sessions = $payload['sessions'] ?? $payload['items'] ?? $payload['data'] ?? [];

        if (! is_array($sessions)) {
            return [];
        }

        $mapped = [];
        foreach ($sessions as $item) {
            if (is_array($item)) {
                $mapped[] = $this->mapSession($item);
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, title: string, status: string, last_activity: mixed, summary: string}
     */
    private function mapSession(array $item): array
    {
        return [
            'id' => (string) ($item['id'] ?? $item['sessionID'] ?? $item['session_id'] ?? ''),
            'title' => (string) ($item['title'] ?? $item['name'] ?? ''),
            'status' => (string) ($item['status'] ?? 'unknown'),
            'last_activity' => $item['last_activity'] ?? null,
            'summary' => (string) ($item['summary'] ?? $item['agentSummary'] ?? ''),
        ];
    }
}
