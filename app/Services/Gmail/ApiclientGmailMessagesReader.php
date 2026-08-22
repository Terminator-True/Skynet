<?php

namespace App\Services\Gmail;

use App\Models\GoogleToken;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleClientFactory;
use App\Services\Google\GoogleTokenRefreshException;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;

/**
 * google/apiclient adapter: asserts the stored OAuth grant carries
 * gmail.readonly BEFORE resolving a client (one guard covers every Gmail
 * tool), then queries users.messages with server-side header trimming.
 */
class ApiclientGmailMessagesReader implements GmailMessagesReader
{
    /** The single DATA scope every Gmail tool requires (spec §8 minimality). */
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';

    /** Server-side header trimming keeps message payload out of the LLM context. */
    private const METADATA_HEADERS = ['Subject', 'From', 'Date'];

    private const MIME_PLAIN = 'text/plain';

    private const MIME_HTML = 'text/html';

    public function __construct(private readonly GoogleClientFactory $factory) {}

    public function search(string $query, int $maxResults): array
    {
        $this->assertGmailReadonly();

        $service = new Gmail($this->factory->resolve());

        try {
            $stubs = $service->users_messages->listUsersMessages('me', [
                'q' => $query,
                'maxResults' => $maxResults,
            ])->getMessages() ?? [];

            $messages = [];

            foreach ($stubs as $stub) {
                $message = $service->users_messages->get('me', (string) $stub->getId(), [
                    'format' => 'metadata',
                    'metadataHeaders' => self::METADATA_HEADERS,
                ]);

                $messages[] = self::mapMessage($message);
            }
        } catch (GoogleServiceException $e) {
            throw new GoogleApiException(
                'Gmail messages search failed: '.mb_substr($e->getMessage(), 0, 300),
                previous: $e,
            );
        }

        return $messages;
    }

    public function get(string $messageId): array
    {
        $this->assertGmailReadonly();

        $service = new Gmail($this->factory->resolve());

        try {
            $message = $service->users_messages->get('me', $messageId, ['format' => 'full']);
        } catch (GoogleServiceException $e) {
            throw new GoogleApiException(
                "Gmail message [$messageId] fetch failed: ".mb_substr($e->getMessage(), 0, 300),
                previous: $e,
            );
        }

        return [
            'subject' => self::header($message->getPayload(), 'Subject') ?? '',
            'from' => self::header($message->getPayload(), 'From') ?? '',
            'date' => self::header($message->getPayload(), 'Date'),
            'body' => self::extractBody($message->getPayload()),
        ];
    }

    /**
     * Pure DTO mapping (offline unit-testable): trimmed metadata fields only.
     * `id` is the opaque Gmail message id, forwarded additively so callers can
     * re-fetch the body via get() without re-searching.
     *
     * @return array{id: string, subject: string, from: string, snippet: string, date: string|null}
     */
    public static function mapMessage(Message $message): array
    {
        return [
            'id' => (string) $message->getId(),
            'subject' => self::header($message->getPayload(), 'Subject') ?? '',
            'from' => self::header($message->getPayload(), 'From') ?? '',
            'snippet' => (string) $message->getSnippet(),
            'date' => self::header($message->getPayload(), 'Date'),
        ];
    }

    /**
     * Pure MIME mapping (offline unit-testable), mirrors mapEvent: recursively
     * walks nested multipart payloads preferring text/plain; when absent, the
     * first text/html part is stripped to readable text.
     *
     * Corrupt base64 or an empty payload yields '' — never throws.
     */
    public static function extractBody(MessagePart $payload): string
    {
        ['plain' => $plain, 'html' => $html] = self::walk($payload);

        if ($plain !== null) {
            return $plain;
        }

        return $html === null ? '' : self::htmlToText($html);
    }

    /**
     * Depth-first walk collecting the first plain and first HTML leaf bodies.
     *
     * Reads raw model data via ArrayAccess instead of vendor getters because
     * their PHPDoc claims non-nullable returns while unset fields resolve to
     * null at runtime (same trap as mapEvent).
     *
     * @return array{plain: string|null, html: string|null}
     */
    private static function walk(MessagePart $payload): array
    {
        $mime = self::mimeType($payload);

        if ($mime === self::MIME_PLAIN || $mime === self::MIME_HTML) {
            $decoded = self::decodeBase64Url(self::bodyData($payload));

            return [
                'plain' => $mime === self::MIME_PLAIN ? $decoded : null,
                'html' => $mime === self::MIME_HTML ? $decoded : null,
            ];
        }

        $plain = null;
        $html = null;

        foreach (self::childParts($payload) as $part) {
            $nested = self::walk($part);
            $plain ??= $nested['plain'];
            $html ??= $nested['html'];
        }

        return ['plain' => $plain, 'html' => $html];
    }

    /** MIME type without parameters, lowercased; '' when unset. */
    private static function mimeType(MessagePart $part): string
    {
        $raw = $part['mimeType'] ?? '';
        $base = explode(';', is_string($raw) ? $raw : '', 2)[0];

        return strtolower(trim($base));
    }

    /** Raw base64url payload of the part body; '' when absent. */
    private static function bodyData(MessagePart $part): string
    {
        $body = $part['body'] ?? null;

        if (! $body instanceof \ArrayAccess && ! is_array($body)) {
            return '';
        }

        $data = $body['data'] ?? null;

        return is_string($data) ? $data : '';
    }

    /**
     * @return list<MessagePart>
     */
    private static function childParts(MessagePart $part): array
    {
        $parts = $part['parts'] ?? [];

        if (! is_array($parts)) {
            return [];
        }

        return array_values(array_filter(
            $parts,
            fn (mixed $child): bool => $child instanceof MessagePart,
        ));
    }

    /** RFC 4648 §5 base64url → binary; padding restored, corrupt input → ''. */
    private static function decodeBase64Url(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $normalized = strtr($data, '-_', '+/');
        $padded = $normalized.str_repeat('=', (4 - strlen($normalized) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    /** Decodes entities, strips tags and collapses all whitespace runs. */
    private static function htmlToText(string $html): string
    {
        $text = strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Case-insensitive header lookup on a message payload node.
     */
    private static function header(?MessagePart $payload, string $name): ?string
    {
        $headers = $payload !== null ? $payload->getHeaders() : [];

        foreach ($headers as $header) {
            if (strcasecmp((string) $header->getName(), $name) === 0) {
                return (string) $header->getValue();
            }
        }

        return null;
    }

    /**
     * Scope guard (D1): fires BEFORE factory->resolve() so a grant without
     * gmail.readonly never reaches the API. Reuses GoogleTokenRefreshException
     * so tools keep the calendar google_not_connected catch pattern.
     *
     * @throws GoogleTokenRefreshException when no connection exists or gmail.readonly is absent
     */
    private function assertGmailReadonly(): void
    {
        $token = GoogleToken::query()->first();
        $scopes = $token !== null ? $token->scopes : [];

        if (! in_array(self::SCOPE, $scopes, true)) {
            throw new GoogleTokenRefreshException(
                'Stored Google grant lacks gmail.readonly — reconnect via /connect required.',
            );
        }
    }
}
