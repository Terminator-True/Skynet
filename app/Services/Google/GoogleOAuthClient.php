<?php

namespace App\Services\Google;

/**
 * Transport seam over google/apiclient.
 *
 * All vendor specifics live behind this interface (mirrors the OllamaClient
 * convention). Tests bind fakes here: Http::fake() cannot intercept
 * google/apiclient because it uses Guzzle internally.
 */
interface GoogleOAuthClient
{
    /**
     * Build the Google consent URL carrying the CSRF state parameter.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Exchange an OAuth authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException on transport or Google API failure
     */
    public function exchangeCode(string $code): array;

    /**
     * Exchange a refresh token for a fresh access token.
     *
     * @return array{access_token: string, expires_in: int, scope?: string}
     *
     * @throws GoogleApiException on transport or Google API failure
     */
    public function refresh(string $refreshToken): array;
}
