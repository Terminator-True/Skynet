<?php

use App\Models\GoogleToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function connect_ui_token(?string $accessToken = 'at-live'): void
{
    $user = User::create(['name' => 'Owner', 'email' => 'owner@localhost']);

    GoogleToken::create([
        'user_id' => $user->id,
        'access_token' => $accessToken,
        'refresh_token' => 'rt-plaintext',
        'expires_at' => now()->addHour(),
        'scopes' => ['gmail.readonly', 'calendar.readonly'],
    ]);
}

it('renders the not_connected state with no token row', function () {
    $this->get('/connect')->assertInertia(fn ($page) => $page
        ->component('Connect')
        ->where('status', 'not_connected')
        ->where('googleError', null));
});

it('renders the connected state with email, scopes and expiry', function () {
    connect_ui_token();

    $this->get('/connect')->assertInertia(fn ($page) => $page
        ->component('Connect')
        ->where('status', 'connected')
        ->where('email', 'owner@localhost')
        ->where('scopes', ['gmail.readonly', 'calendar.readonly'])
        ->where('expiresAt', fn (?string $value) => $value !== null && now()->parse($value)->isFuture()));
});

it('renders reconnect_required after a disposed access token', function () {
    connect_ui_token(accessToken: null);

    $this->get('/connect')->assertInertia(fn ($page) => $page
        ->component('Connect')
        ->where('status', 'reconnect_required'));
});

it('surfaces each google_error flash as the banner prop', function () {
    foreach (['denied', 'state_mismatch', 'exchange_failed'] as $error) {
        $this->withSession(['google_error' => $error])
            ->get('/connect')
            ->assertInertia(fn ($page) => $page->where('googleError', $error));
    }
});
