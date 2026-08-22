<?php

use App\Models\GoogleToken;
use App\Models\User;
use App\Services\Gmail\ApiclientGmailMessagesReader;
use App\Services\Gmail\GmailMessagesReader;
use App\Tools\BuscarCorreos;
use App\Tools\LeerCorreo;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Slice A wiring acceptance: the container resolves the Gmail adapter through
 * GoogleClientFactory, both tools ship in the registry definitions, and a
 * grant without gmail.readonly short-circuits to google_not_connected BEFORE
 * any API request (construction-only — no network).
 */
it('binds the GmailMessagesReader seam to the apiclient adapter', function () {
    expect(app(GmailMessagesReader::class))
        ->toBeInstanceOf(ApiclientGmailMessagesReader::class);
});

it('exposes buscar_correos and leer_correo through the tool registry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('buscar_correos'))->toBeTrue()
        ->and($registry->has('leer_correo'))->toBeTrue();
});

it('includes both Gmail tools in the Ollama-format definitions', function () {
    $names = collect(app(ToolRegistry::class)->definitions())
        ->pluck('function.name')
        ->all();

    expect($names)->toContain('buscar_correos')->toContain('leer_correo');
});

function gmail_token(array $scopes, ?string $accessToken): void
{
    $user = User::factory()->create();

    GoogleToken::create([
        'user_id' => $user->id,
        'access_token' => $accessToken,
        'refresh_token' => 'rt',
        'expires_at' => now()->addHour(),
        'scopes' => $scopes,
    ]);
}

it('returns google_not_connected before any API request when the stored grant lacks gmail.readonly', function () {
    // Calendar-only grant: the scope guard must fire inside the adapter,
    // before factory->resolve() ever builds a client.
    gmail_token(['https://www.googleapis.com/auth/calendar.readonly'], 'at');

    $search = app(BuscarCorreos::class)->execute(['query' => 'from:amazon.com']);
    $read = app(LeerCorreo::class)->execute(['message_id' => 'abc123']);

    expect($search)->toBe(['error' => 'google_not_connected'])
        ->and($read)->toBe(['error' => 'google_not_connected']);
});

it('passes the guard when gmail.readonly is present and only then hits the dead-grant path', function () {
    // Right scope but no usable access token: the guard lets execution
    // proceed into factory->resolve(), which throws the typed exception the
    // tools map to google_not_connected (still zero network).
    gmail_token([ApiclientGmailMessagesReader::SCOPE], null);

    $result = app(BuscarCorreos::class)->execute(['query' => 'q']);

    expect($result)->toBe(['error' => 'google_not_connected']);
});
