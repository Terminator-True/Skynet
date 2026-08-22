<?php

namespace Tests\Live;

use App\Models\GoogleToken;
use App\Services\Google\ApiclientGoogleOAuthClient;
use App\Services\Google\GoogleClientFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Live Google round-trip (spec S13 — Fase-1 acceptance: login OK, tokens
 * persisted AND renewed without manual intervention).
 *
 * HARD-GATED like Fase-0's OLLAMA_EVAL pattern: skipped unless
 * GOOGLE_OAUTH_LIVE=1, which the user sets ONLY after confirming the GCP
 * checklist (project + Gmail/Calendar APIs enabled + consent screen published
 * to Production UNVERIFIED + OAuth Web client with redirect URI exactly
 * http://localhost:8000/auth/google/callback + credentials in .env + MySQL up).
 *
 * Plain `pest` runs NEVER touch Google.
 */
it('runs the full consent → callback → encrypted persist → lazy refresh round-trip', function () {
    expect(config('services.google.client_id'))->not->toBeEmpty('GOOGLE_CLIENT_ID missing from .env');

    // 1. Consent redirect against the REAL adapter (no network needed).
    $state = Str::random(40);
    $consentUrl = (new ApiclientGoogleOAuthClient)->authorizationUrl($state);
    expect($consentUrl)->toContain('accounts.google.com');

    // 2. Exchange a real authorization code (requires the browser leg above).
    test()->markTestIncomplete(
        'GCP checklist pending: complete the consent-screen + OAuth-client setup, '
        .'open the consent URL in a browser, then paste the returned ?code here '
        .'(or wire this into an interactive harness).',
    );

    // 3. Post-conditions once the code exchange is wired (kept as executable spec):
    $token = GoogleToken::first();
    expect($token->access_token)->not->toBeNull()
        ->and($token->refresh_token)->not->toBeNull()
        ->and(app(GoogleClientFactory::class)->resolve()->isAccessTokenExpired())->toBeFalse();
})->group('live')->skip(
    fn (): bool => ! (bool) env('GOOGLE_OAUTH_LIVE'),
    'GATE STATUS: PENDING_GCP_CHECKLIST — set GOOGLE_OAUTH_LIVE=1 only after confirming the GCP checklist (spec S12).',
);

it('verifies configured credentials against the Google authorization endpoint', function () {
    $clientId = config('services.google.client_id');
    $redirect = config('services.google.redirect');

    // Google's authorization endpoint classifies credential problems in its
    // redirect target: unknown clients land on /signin/oauth/error with
    // authError=invalid_client, unregistered redirect URIs with
    // redirect_uri_mismatch. A healthy config redirects into the sign-in flow.
    // (tokeninfo cannot validate a bare client_id — it requires a token.)
    $response = Http::timeout(10)
        ->withOptions(['allow_redirects' => false])
        ->get('https://accounts.google.com/o/oauth2/v2/auth', [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'openid',
        ]);

    expect($response->status())->toBe(302)
        ->and($response->header('Location'))
        ->not->toContain('/signin/oauth/error');
})->group('live')->skip(
    fn (): bool => ! (bool) env('GOOGLE_OAUTH_LIVE'),
    'GATE STATUS: PENDING_GCP_CHECKLIST — set GOOGLE_OAUTH_LIVE=1 only after confirming the GCP checklist (spec S12).',
);
