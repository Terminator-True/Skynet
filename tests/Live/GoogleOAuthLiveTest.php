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

it('verifies configured credentials are accepted by Google tokeninfo', function () {
    $clientId = config('services.google.client_id');

    $response = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', [
        'client_id' => $clientId,
    ]);

    // A valid client id answers 200 even without a token; invalid ones answer 400.
    expect($response->status())->toBe(200, "Client ID [{$clientId}] rejected by Google");
})->group('live')->skip(
    fn (): bool => ! (bool) env('GOOGLE_OAUTH_LIVE'),
    'GATE STATUS: PENDING_GCP_CHECKLIST — set GOOGLE_OAUTH_LIVE=1 only after confirming the GCP checklist (spec S12).',
);
