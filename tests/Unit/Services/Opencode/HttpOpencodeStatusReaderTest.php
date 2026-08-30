<?php

use App\Services\Opencode\Exceptions\OpencodeConnectionException;
use App\Services\Opencode\HttpOpencodeStatusReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('maps active sessions from the status endpoint', function () {
    Http::fake([
        '127.0.0.1:4096*' => Http::response([
            'sessions' => [
                ['id' => 'sess-1', 'title' => 'Refactor auth', 'status' => 'running', 'last_activity' => 1234, 'summary' => 'Working on JWT middleware'],
                ['id' => 'sess-2', 'title' => 'Fix bug', 'status' => 'idle', 'last_activity' => 5678, 'summary' => 'Waiting for input'],
            ],
        ]),
    ]);

    expect((new HttpOpencodeStatusReader)->status())->toBe([
        ['id' => 'sess-1', 'title' => 'Refactor auth', 'status' => 'running', 'last_activity' => 1234, 'summary' => 'Working on JWT middleware'],
        ['id' => 'sess-2', 'title' => 'Fix bug', 'status' => 'idle', 'last_activity' => 5678, 'summary' => 'Waiting for input'],
    ]);
});

it('returns an empty list when there are no sessions', function () {
    Http::fake(['127.0.0.1:4096*' => Http::response(['sessions' => []])]);

    expect((new HttpOpencodeStatusReader)->status())->toBe([]);
});

it('applies defensive field mapping for non-standard payloads', function () {
    Http::fake([
        '127.0.0.1:4096*' => Http::response([
            'items' => [
                ['sessionID' => 'sess-a', 'name' => 'Agent Alpha', 'agentSummary' => 'Focused on CVE review'],
            ],
        ]),
    ]);

    expect((new HttpOpencodeStatusReader)->status())->toBe([
        ['id' => 'sess-a', 'title' => 'Agent Alpha', 'status' => 'unknown', 'last_activity' => null, 'summary' => 'Focused on CVE review'],
    ]);
});

it('wraps connection failures as OpencodeConnectionException', function () {
    Http::fake([
        '127.0.0.1:4096*' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    (new HttpOpencodeStatusReader)->status();
})->throws(OpencodeConnectionException::class);

it('wraps non-2xx responses as OpencodeConnectionException', function () {
    Http::fake(['127.0.0.1:4096*' => Http::response('', 500)]);

    (new HttpOpencodeStatusReader)->status();
})->throws(OpencodeConnectionException::class);

it('requests a single session when session_id is provided', function () {
    Http::fake([
        '127.0.0.1:4096*' => Http::response(['id' => 'sess-9', 'title' => 'Defense', 'status' => 'done', 'last_activity' => 999, 'summary' => 'Complete']),
    ]);

    $result = (new HttpOpencodeStatusReader)->status('sess-9');

    expect($result)->toBe([
        ['id' => 'sess-9', 'title' => 'Defense', 'status' => 'done', 'last_activity' => 999, 'summary' => 'Complete'],
    ])->and(Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:4096/session/sess-9'));
});

it('sends basic auth only when a password is configured', function () {
    config()->set('opencode.basic_auth_user', 'opencode');
    config()->set('opencode.basic_auth_password', 'secret');

    Http::fake(['127.0.0.1:4096*' => Http::response(['sessions' => []])]);

    (new HttpOpencodeStatusReader)->status();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('opencode:secret')));
});
