<?php

namespace App\Services\Google;

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Thin wrapper around google/apiclient's Google\Client.
 *
 * Zero orchestration logic: builds the client from config('services.google'),
 * produces consent URLs and token payloads. Typed exception on any failure —
 * never a silent fallback.
 */
class ApiclientGoogleOAuthClient implements GoogleOAuthClient
{
    /**
     * The two DATA scopes locked by spec §8 minimality rule, plus openid+email
     * (feeds the "connected email" UI; non-sensitive).
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/calendar.readonly',
        'openid',
        'email',
    ];

    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
        ]);
    }

    /**
     * access_type=offline + prompt=consent guarantee refresh-token issuance.
     */
    public function authorizationUrl(string $state): string
    {
        // Setters rather than createAuthUrl params: passing 'prompt' there
        // collides with the client's internal approval_prompt.
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setState($state);

        return $this->client->createAuthUrl(self::SCOPES);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException
     */
    public function exchangeCode(string $code): array
    {
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            throw new GoogleApiException(
                'Google code exchange failed: '.mb_substr($e->getMessage(), 0, 300),
                previous: $e,
            );
        }

        return $this->assertPayload($token);
    }

    /**
     * @return array{access_token: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException
     */
    public function refresh(string $refreshToken): array
    {
        try {
            $token = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (GoogleServiceException $e) {
            // 400/401 from the token endpoint = revoked or expired grant.
            throw new GoogleTokenRefreshException(
                "Google rejected the refresh grant — reconnect required. {$e->getCode()}: "
                    .mb_substr($e->getMessage(), 0, 300),
                previous: $e,
            );
        }

        /** @var array{access_token: string, expires_in: int, scope?: string} $payload */
        $payload = $this->assertPayload($token);

        return $payload;
    }

    /**
     * @param  mixed  $token
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException when Google returns an error payload instead of tokens
     */
    private function assertPayload($token): array
    {
        if (! is_array($token) || isset($token['error'])) {
            $reason = is_array($token) ? ($token['error_description'] ?? $token['error'] ?? 'unknown error') : 'non-array payload';

            throw new GoogleApiException('Google OAuth payload error: '.$reason);
        }

        /** @var array{access_token: string, refresh_token?: string, expires_in: int, scope?: string} $token */

        return $token;
    }
}
