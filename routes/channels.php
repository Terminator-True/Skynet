<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Fase 5 realtime delivery (REQ realtime-delivery): authorize the single
| owner for their own private notifications channel. The channel NAME on the
| wire is `private-notifications.{userId}` (PrivateChannel + Reverb), but
| Laravel's Pusher channel conventions strip the `private-` prefix before auth
| matching (UsePusherChannelConventions::normalizeChannelName), so the pattern
| here is registered WITHOUT the prefix.
|
| Channel auth is resolved from the web guard (`Broadcaster::retrieveUser` →
| request->user()), so the owner must hold an authenticated session — see the
| auto-login in GoogleOAuthController::callback (single-tenant, no login UI).
| The `{userId}` placeholder is a plain route param (no model binding), so it
| arrives as a string; compare by casting.
|
*/

Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return $user !== null && (int) $userId === (int) $user->id;
});
