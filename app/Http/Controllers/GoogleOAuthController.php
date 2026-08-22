<?php

namespace App\Http\Controllers;

use App\Models\GoogleToken;
use App\Models\User;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Single-user Google OAuth flow (first user wins — D2, no registration UI).
 *
 * Every failure path flashes a google_error key and redirects with ZERO
 * partial writes: no user or token mutation happens before the full
 * exchange + payload validation succeeds.
 */
class GoogleOAuthController extends Controller
{
    private const SESSION_STATE_KEY = 'google_oauth_state';

    public function __construct(private readonly GoogleOAuthClient $oauth)
    {
    }

    /**
     * Send the browser to Google consent (offline access, forced consent).
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE_KEY, $state);

        return redirect()->away($this->oauth->authorizationUrl($state));
    }

    /**
     * Handle the OAuth callback: validate state, exchange code, upsert the
     * single user + encrypted token row, land on /connect.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            // Consent denied or other forward error from Google.
            return $this->fail($request, 'denied');
        }

        $expectedState = $request->session()->pull(self::SESSION_STATE_KEY);

        if (! is_string($expectedState) || ! $request->filled('code') || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->fail($request, 'state_mismatch');
        }

        try {
            $payload = $this->oauth->exchangeCode((string) $request->query('code'));
        } catch (GoogleApiException) {
            return $this->fail($request, 'exchange_failed');
        }

        DB::transaction(function () use ($payload): void {
            // D2: first user wins; create the single owner row when none exists.
            $user = User::query()->first() ?? User::create([
                'name' => 'Owner',
                'email' => 'owner@localhost',
            ]);

            GoogleToken::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'access_token' => $payload['access_token'],
                    'refresh_token' => $payload['refresh_token'] ?? $this->existingRefreshToken($user->id),
                    'expires_at' => now()->addSeconds($payload['expires_in']),
                    'scopes' => array_values(array_filter(explode(' ', $payload['scope'] ?? ''))),
                ],
            );
        });

        return redirect()->route('connect');
    }

    /**
     * Connect/status UI: three states derived purely from the token row.
     */
    public function status(Request $request): Response
    {
        $token = GoogleToken::query()->first();

        return Inertia::render('Connect', [
            'status' => match (true) {
                $token === null => 'not_connected',
                $token->access_token === null => 'reconnect_required',
                default => 'connected',
            },
            'email' => $token?->user?->email,
            'scopes' => $token?->scopes ?? [],
            'expiresAt' => $token?->expires_at?->toIso8601String(),
            'googleError' => $request->session()->get('google_error'),
        ]);
    }

    /**
     * Idempotent reconnect: keep the stored refresh_token when Google omits it.
     */
    private function existingRefreshToken(int $userId): ?string
    {
        return GoogleToken::query()->where('user_id', $userId)->value('refresh_token');
    }

    /**
     * Uniform zero-write failure: flash + redirect to status UI.
     */
    private function fail(Request $request, string $error): RedirectResponse
    {
        $request->session()->flash('google_error', $error);

        return redirect()->route('connect');
    }
}
