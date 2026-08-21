<?php

use App\Services\Google\ApiclientGoogleOAuthClient;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Support\Facades\Session;
use Tests\Support\FakeGoogleOAuthClient;

it('builds the consent url with exactly the locked scopes, offline + forced consent', function () {
    config(['services.google' => [
        'client_id' => 'test-client-id',
        'client_secret' => 'test-secret',
        'redirect' => 'http://localhost:8000/auth/google/callback',
    ]]);

    $url = (new ApiclientGoogleOAuthClient)->authorizationUrl('state-value-123');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(explode(' ', $query['scope']))->toBe(ApiclientGoogleOAuthClient::SCOPES)
        ->and($query['access_type'])->toBe('offline')
        ->and($query['prompt'])->toBe('consent')
        ->and($query['state'])->toBe('state-value-123')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/auth/google/callback');
});

it('stores a 40-char csrf state in the session and redirects away to Google', function () {
    $fake = new FakeGoogleOAuthClient;
    $this->swap(GoogleOAuthClient::class, $fake);

    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();

    expect(Session::get('google_oauth_state'))->toBeString()->toHaveLength(40)
        ->and($response->headers->get('Location'))->toContain('accounts.google.test');
});
