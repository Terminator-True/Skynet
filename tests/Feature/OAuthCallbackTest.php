<?php

use App\Models\GoogleToken;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeGoogleOAuthClient;

uses(RefreshDatabase::class);

function oauth_callback_fake(): FakeGoogleOAuthClient
{
    $fake = new FakeGoogleOAuthClient;

    test()->swap(GoogleOAuthClient::class, $fake);

    return $fake;
}

function oauth_callback_url(string $state, string $code = 'good-code'): string
{
    return '/auth/google/callback?'.http_build_query(['state' => $state, 'code' => $code]);
}

it('creates one user and one encrypted token row on first successful connect', function () {
    oauth_callback_fake();

    $state = Str::random(40);

    $response = $this->withSession(['google_oauth_state' => $state])
        ->get(oauth_callback_url($state));

    $response->assertRedirect(route('connect'));

    expect(User::count())->toBe(1)
        ->and(GoogleToken::count())->toBe(1);

    // Fase 5 channel auth: the owner is auto-logged-in after the callback so
    // /broadcasting/auth can resolve them (Design open-question decision).
    expect(auth()->id())->toBe(User::first()->id);

    $row = DB::table('google_tokens')->first();

    expect($row->access_token)->not->toBe('at-good-code') // ciphertext at rest
        ->and(GoogleToken::first()->access_token)->toBe('at-good-code')
        ->and(GoogleToken::first()->refresh_token)->toBe('rt-secret')
        ->and(GoogleToken::first()->scopes)->toBe([
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/calendar.readonly',
            'openid',
            'email',
        ]);
});

it('is idempotent on reconnect: still one user + one row, refresh_token preserved', function () {
    $fake = oauth_callback_fake();

    $state = Str::random(40);
    $first = '/auth/google/callback?'.http_build_query(['state' => $state, 'code' => 'code-1']);

    $this->withSession(['google_oauth_state' => $state])->get($first);

    // Second grant omits refresh_token (Google only reissues it with prompt=consent).
    $fake->exchangeHandler = fn (): array => ['access_token' => 'at-code-2', 'expires_in' => 3600];

    $this->withSession(['google_oauth_state' => $state])
        ->get(oauth_callback_url($state, 'code-2'));

    expect(User::count())->toBe(1)
        ->and(GoogleToken::count())->toBe(1)
        ->and(GoogleToken::first()->access_token)->toBe('at-code-2')
        // Never nulled by an upsert that omits it.
        ->and(GoogleToken::first()->refresh_token)->toBe('rt-secret');
});

it('flashes google_error=denied and writes nothing when consent is denied', function () {
    oauth_callback_fake();

    $response = $this->get('/auth/google/callback?error=access_denied');

    $response->assertRedirect(route('connect'))
        ->assertSessionHas('google_error', 'denied');

    expect(User::count())->toBe(0)->and(GoogleToken::count())->toBe(0);
});

it('flashes google_error=state_mismatch and writes nothing on forged state', function () {
    oauth_callback_fake();

    $response = $this->withSession(['google_oauth_state' => Str::random(40)])
        ->get(oauth_callback_url('forged-state'));

    $response->assertRedirect(route('connect'))
        ->assertSessionHas('google_error', 'state_mismatch');

    expect(User::count())->toBe(0)->and(GoogleToken::count())->toBe(0);
});

it('flashes google_error=exchange_failed and writes nothing when the exchange throws', function () {
    $fake = oauth_callback_fake();
    $fake->failExchangeWith(new GoogleApiException('token endpoint exploded'));

    $state = Str::random(40);

    $response = $this->withSession(['google_oauth_state' => $state])
        ->get(oauth_callback_url($state));

    $response->assertRedirect(route('connect'))
        ->assertSessionHas('google_error', 'exchange_failed');

    expect(User::count())->toBe(0)->and(GoogleToken::count())->toBe(0);
});
