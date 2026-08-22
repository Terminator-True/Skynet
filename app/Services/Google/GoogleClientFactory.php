<?php

namespace App\Services\Google;

use App\Models\GoogleToken;
use Google\Client;

/**
 * Resolves an authorized Google\Client for the single user, lazily refreshing
 * the stored access token when it expires within a 60s buffer.
 *
 * Lazy-only by design (D6): no scheduler. Refresh happens on resolve.
 *
 * Failure contract (design ambiguity #4): the token row is KEPT, only
 * access_token is nulled — refresh_token is byte-untouched so a later
 * reconnect-upsert preserves it, and transient failures self-heal on the next
 * resolve.
 */
class GoogleClientFactory
{
    private const EXPIRY_BUFFER_SECONDS = 60;

    public function __construct(private readonly GoogleOAuthClient $oauth) {}

    /**
     * @throws GoogleTokenRefreshException when no connection exists or the refresh grant is rejected
     */
    public function resolve(): Client
    {
        $token = GoogleToken::query()->first();

        if ($token === null) {
            throw new GoogleTokenRefreshException(
                'No Google account connected — connect via /connect first.',
            );
        }

        $expiresSoon = $token->expires_at !== null
            && $token->expires_at->lessThan(now()->addSeconds(self::EXPIRY_BUFFER_SECONDS));

        if ($expiresSoon && $token->refresh_token !== null) {
            try {
                $payload = $this->oauth->refresh($token->refresh_token);
            } catch (GoogleApiException $e) {
                if (! $e instanceof GoogleTokenRefreshException) {
                    $e = new GoogleTokenRefreshException(
                        'Google token refresh failed — reconnect required. '.$e->getMessage(),
                        previous: $e,
                    );
                }

                // Dispose of the dead access_token ONLY: row + refresh_token stay.
                $token->forceFill(['access_token' => null])->save();

                throw $e;
            }

            // NEVER persist refresh_token here: Google may omit it from the
            // refresh payload; overwriting would null it.
            $token->access_token = $payload['access_token'];
            $token->expires_at = now()->addSeconds($payload['expires_in']);
            $token->save();
        }

        if ($token->access_token === null) {
            throw new GoogleTokenRefreshException(
                'Google access token unavailable — reconnect required via /connect.',
            );
        }

        $client = new Client([
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
        ]);
        $client->setAccessToken($token->access_token);

        return $client;
    }
}
