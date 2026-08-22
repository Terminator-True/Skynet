<?php

namespace App\Services\Google;

/**
 * The stored Google refresh token was rejected (revoked or expired grant).
 *
 * Recovery is user-driven: reconnect Google from the status UI
 * (/connect shows "reconnect_required" when access_token is nulled).
 */
class GoogleTokenRefreshException extends GoogleApiException {}
