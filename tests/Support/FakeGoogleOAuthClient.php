<?php

namespace Tests\Support;

use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleOAuthClient;
use Closure;

/**
 * Seam fake replacing google/apiclient in tests: Http::fake() cannot
 * intercept apiclient/Guzzle, so every flow test binds this instead.
 *
 * Handlers are mutable so individual tests decide success vs typed failure;
 * refresh/exchange invocations are recorded for exact call-count asserts.
 */
class FakeGoogleOAuthClient implements GoogleOAuthClient
{
    /** @var list<string> */
    public array $exchangeCodes = [];

    /** @var list<string> */
    public array $refreshTokens = [];

    /** @var Closure(string): array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}|null */
    public ?Closure $exchangeHandler = null;

    /** @var Closure(string): array{access_token: string, expires_in: int} */
    public Closure $refreshHandler;

    public function __construct()
    {
        $this->refreshHandler = fn (): array => ['access_token' => 'refreshed-access-token', 'expires_in' => 3600];
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.test/o/oauth2/auth?state='.$state;
    }

    public function exchangeCode(string $code): array
    {
        $this->exchangeCodes[] = $code;

        if ($this->exchangeHandler !== null) {
            return ($this->exchangeHandler)($code);
        }

        return [
            'access_token' => 'at-'.$code,
            'refresh_token' => 'rt-secret',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/calendar.readonly openid email',
        ];
    }

    public function refresh(string $refreshToken): array
    {
        $this->refreshTokens[] = $refreshToken;

        return ($this->refreshHandler)($refreshToken);
    }

    /** Convenience: make every exchange throw (transport/API failure). */
    public function failExchangeWith(GoogleApiException $e): void
    {
        $this->exchangeHandler = fn (): array => throw $e;
    }
}
