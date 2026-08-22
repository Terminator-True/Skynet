<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * Google OAuth transport / API failure (code exchange, refresh, HTTP errors).
 *
 * Loud by design: callers must surface this, never fall back silently to a
 * stale or missing client.
 */
class GoogleApiException extends RuntimeException {}
