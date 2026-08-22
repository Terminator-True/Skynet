<?php

use App\Models\GoogleToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('exposes plaintext via casts while storing AES ciphertext at rest', function () {
    $user = User::create(['name' => 'Owner', 'email' => 'owner@localhost']);

    GoogleToken::create([
        'user_id' => $user->id,
        'access_token' => 'at-plaintext',
        'refresh_token' => 'rt-plaintext',
        'expires_at' => now()->addHour(),
        'scopes' => ['gmail.readonly', 'calendar.readonly'],
    ]);

    $row = DB::table('google_tokens')->first();

    // Raw columns are ciphertext under APP_KEY, never the plaintext values.
    expect($row->access_token)->not->toBe('at-plaintext')
        ->and($row->refresh_token)->not->toBe('rt-plaintext');

    $token = GoogleToken::query()->first();

    expect($token->access_token)->toBe('at-plaintext')
        ->and($token->refresh_token)->toBe('rt-plaintext')
        // Scopes round-trip as a PHP array through the JSON column.
        ->and($token->scopes)->toBe(['gmail.readonly', 'calendar.readonly']);
});
