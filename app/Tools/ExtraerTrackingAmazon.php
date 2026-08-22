<?php

namespace App\Tools;

use App\Models\GoogleToken;
use App\Models\TrackedPackage;
use App\Services\AmazonTrackingExtractor;
use App\Services\Gmail\GmailMessagesReader;
use App\Services\Google\GoogleTokenRefreshException;
use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Amazon tracking extraction (spec: extraer_tracking_amazon): the model passes
 * only the opaque message_id — the body is re-fetched internally through the
 * shared reader seam. A successful extraction upserts one TrackedPackage keyed
 * by (user_id, source_email_id), so re-running on the same email refreshes the
 * row instead of duplicating it.
 */
class ExtraerTrackingAmazon implements Tool
{
    public function __construct(
        private readonly GmailMessagesReader $reader,
        private readonly AmazonTrackingExtractor $extractor,
    ) {}

    public function name(): string
    {
        return 'extraer_tracking_amazon';
    }

    public function description(): string
    {
        return 'Extracts carrier, tracking number, product name and status from an Amazon order or shipping email and saves the package for tracking. Call buscar_correos first to obtain the message id.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => [
                    'type' => 'string',
                    'description' => 'Opaque Gmail message id returned by buscar_correos',
                ],
            ],
            'required' => ['message_id'],
        ];
    }

    /**
     * Error contracts (spec): google_not_connected before any work when no
     * grant exists; extraction_failed when parsing fails after one retry;
     * no_tracking_found for non-Amazon emails / confirmations without a
     * tracking — all three persist nothing.
     *
     * @param  array<string, mixed>  $args
     * @return array{carrier?: string|null, tracking_number?: string|null, product_name?: string|null, status?: string|null, error?: string}
     */
    public function execute(array $args): array
    {
        if (! isset($args['message_id']) || ! is_string($args['message_id']) || trim($args['message_id']) === '') {
            throw new InvalidArgumentException('extraer_tracking_amazon requires a non-empty string message_id argument.');
        }

        // Single-tenant resolution, same precedent as GoogleToken::first() in
        // the adapters: the acting user owns the stored grant.
        $userId = GoogleToken::query()->value('user_id');

        if ($userId === null) {
            return ['error' => 'google_not_connected'];
        }

        try {
            $message = $this->reader->get($args['message_id']);
        } catch (GoogleTokenRefreshException) {
            return ['error' => 'google_not_connected'];
        }

        $fields = $this->extractor->extract($message['body']);

        if ($fields === null) {
            return ['error' => 'extraction_failed'];
        }

        if ($fields === ['carrier' => null, 'tracking_number' => null, 'product_name' => null, 'status' => null]) {
            return ['error' => 'no_tracking_found'];
        }

        TrackedPackage::updateOrCreate(
            ['user_id' => $userId, 'source_email_id' => $args['message_id']],
            [...$fields, 'last_checked_at' => now()],
        );

        return $fields;
    }
}
