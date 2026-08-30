<?php

namespace App\Services\Opencode;

use App\Services\Opencode\Exceptions\OpencodeConnectionException;

/**
 * Seam around the local OpenCode headless server status endpoints. The
 * concrete HTTP adapter is intercepted by Http::fake in tests, so tools
 * depend on this interface (mirrors WebKnowledgeReader/CalendarEventsReader).
 */
interface OpencodeStatusReader
{
    /**
     * Reports the current OpenCode sessions (all, or a single one when a
     * session id is given).
     *
     * @return list<array{id: string, title: string, status: string, last_activity: mixed, summary: string}>
     *
     * @throws OpencodeConnectionException connection refused, timeout or non-2xx
     */
    public function status(?string $sessionId = null): array;
}
