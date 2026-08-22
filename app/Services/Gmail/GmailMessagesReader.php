<?php

namespace App\Services\Gmail;

use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleTokenRefreshException;

/**
 * Vendor seam around google/apiclient Gmail users.messages (Http::fake
 * cannot intercept google/apiclient's internal Guzzle — tests bind fakes here).
 */
interface GmailMessagesReader
{
    /**
     * @param  string  $query  Gmail search expression, e.g. "from:amazon.com"
     * @param  int  $maxResults  upper bound on returned messages (caller clamps)
     * @return list<array{subject: string, from: string, snippet: string, date: string|null}>
     *
     * @throws GoogleTokenRefreshException no connection or missing gmail.readonly scope
     * @throws GoogleApiException transport / Google API failure
     */
    public function search(string $query, int $maxResults): array;

    /**
     * @param  string  $messageId  opaque Gmail message id
     * @return array{subject: string, from: string, date: string|null, body: string}
     *
     * @throws GoogleTokenRefreshException no connection or missing gmail.readonly scope
     * @throws GoogleApiException transport / Google API failure
     */
    public function get(string $messageId): array;
}
