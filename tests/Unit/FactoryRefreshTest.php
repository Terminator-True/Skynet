<?php

use App\Models\GoogleToken;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleOAuthClient;
use App\Services\Google\GoogleTokenRefreshException;
use Google\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGoogleOAuthClient;

uses(RefreshDatabase::class);

function factory_seed_token(string $accessToken, $expiresAt): void
{
    $user = User::create(['name' => 'Owner', 'email' => 'owner@localhost']);

    GoogleToken::create([
        'user_id' => $user->id,
        'access_token' => $accessToken,
        'refresh_token' => 'rt-original',
        'expires_at' => $expiresAt,
        'scopes' => ['gmail.readonly'],
    ]);
}

it('refreshes exactly once and persists the new access token when inside the 60s buffer', function () {
    factory_seed_token('at-expiring', now()->addSeconds(30));

    $fake = new FakeGoogleOAuthClient;
    $this->swap(GoogleOAuthClient::class, $fake);

    $client = app(GoogleClientFactory::class)->resolve();

    $clientToken = $client->getAccessToken();

    expect($fake->refreshTokens)->toBe(['rt-original']) // exactly one refresh call
        ->and($client)->toBeInstanceOf(Client::class)
        // Google\Client normalizes the token to an array internally.
        ->and(is_array($clientToken) ? $clientToken['access_token'] : $clientToken)
        ->toBe('refreshed-access-token');

    $token = GoogleToken::first();

    expect($token->access_token)->toBe('refreshed-access-token')
        ->and($token->expires_at->greaterThan(now()->addMinutes(59)))->toBeTrue()
        // refresh_token byte-identical: never touched by the refresh path.
        ->and($token->refresh_token)->toBe('rt-original');
});

it('makes zero refresh calls while the token is valid beyond the buffer', function () {
    factory_seed_token('at-fresh', now()->addHours(2));

    $fake = new FakeGoogleOAuthClient;
    $this->swap(GoogleOAuthClient::class, $fake);

    app(GoogleClientFactory::class)->resolve();

    expect($fake->refreshTokens)->toBe([])
        ->and(GoogleToken::first()->access_token)->toBe('at-fresh');
});

it('throws a typed reconnect exception on a revoked grant, nulls only the access token, and stays loud on next resolve', function () {
    factory_seed_token('at-dying', now()->addSeconds(10));

    $fake = new FakeGoogleOAuthClient;
    $fake->refreshHandler = fn (): array => throw new GoogleApiException('invalid_grant');
    $this->swap(GoogleOAuthClient::class, $fake);

    try {
        app(GoogleClientFactory::class)->resolve();
        $this->fail('Expected GoogleTokenRefreshException');
    } catch (GoogleTokenRefreshException $e) {
        expect($e->getMessage())->toContain('reconnect required');
    }

    $row = GoogleToken::first();

    expect($row->refresh()->access_token)->toBeNull() // disposed
        ->and($row->refresh_token)->toBe('rt-original'); // INTACT

    // Next resolve self-heals into a LOUD reconnect signal — never silent fallback.
    expect(fn () => app(GoogleClientFactory::class)->resolve())
        ->toThrow(GoogleTokenRefreshException::class);
});
