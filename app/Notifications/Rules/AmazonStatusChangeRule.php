<?php

namespace App\Notifications\Rules;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\TrackedPackage;
use App\Models\User;
use App\Services\AmazonTrackingExtractor;
use App\Services\Gmail\GmailMessagesReader;
use Illuminate\Support\Collection;

/**
 * Fase 5 Amazon rule (REQ amazon-status-rule): sweep the latest Amazon emails,
 * re-extract package status via the Gmail seam + AmazonTrackingExtractor, and
 * diff it against the stored TrackedPackage.status.
 *
 * The stored status is PRE-READ explicitly and never written via updateOrCreate
 * (which hides the prior value) — the spec requires an explicit read+diff so a
 * change can be detected and notified. On change, one notification is created
 * (dedupe_key `package_update:{tracking_number}:{status}`) and the row's status
 * + last_checked_at are updated. No change → no notification, row unchanged.
 */
class AmazonStatusChangeRule
{
    private const MAX_MESSAGES = 20;

    public function __construct(
        private readonly GmailMessagesReader $reader,
        private readonly AmazonTrackingExtractor $extractor,
    ) {}

    public function run(User $user): void
    {
        $packages = $this->trackedPackages($user);

        if ($packages->isEmpty()) {
            return;
        }

        foreach ($this->reader->search('from:amazon.com', self::MAX_MESSAGES) as $message) {
            $body = $this->reader->get($message['id'])['body'];
            $fields = $this->extractor->extract($body);

            $trackingNumber = $fields['tracking_number'] ?? null;
            $status = $fields['status'] ?? null;

            if ($trackingNumber === null || $status === null || ! $packages->has($trackingNumber)) {
                continue;
            }

            $package = $packages[$trackingNumber];

            // Explicit read+diff: identical stored status means no change.
            if ($package->status === $status) {
                continue;
            }

            $package->status = $status;
            $package->last_checked_at = now();
            $package->save();

            $notification = Notification::firstOrCreate(
                ['user_id' => $user->id, 'dedupe_key' => "package_update:{$trackingNumber}:{$status}"],
                [
                    'type' => 'package_update',
                    'payload' => [
                        'tracking_number' => $trackingNumber,
                        'carrier' => $fields['carrier'],
                        'product_name' => $fields['product_name'],
                        'status' => $status,
                    ],
                ],
            );

            if ($notification->wasRecentlyCreated) {
                NotificationCreated::dispatch($user->id, $notification->payload);
            }
        }
    }

    /**
     * @return Collection<string, TrackedPackage> keyed by tracking_number
     */
    private function trackedPackages(User $user): Collection
    {
        return TrackedPackage::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('tracking_number');
    }
}
