<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast whenever a proactive rule creates a notification (Fase 5).
 *
 * Delivered on a per-user private channel (privacy §8 — local Reverb only,
 * no third-party push). In tests, Event::fake([NotificationCreated::class])
 * asserts the dispatch offline with no Reverb connection (Laravel 13 has no
 * Broadcast::fake).
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  int  $userId  single-tenant owner (GoogleToken precedent)
     * @param  array{id: int, title: string, body: string, created_at: string|null}  $payload  normalized toast payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * PrivateChannel prepends `private-` itself, so the UNPREFIXED name is
     * passed here — the wire channel is `private-notifications.{userId}`,
     * which matches Echo's `.private('notifications.{userId}')` subscription
     * and the `notifications.{userId}` auth pattern (Pusher conventions strip
     * the prefix before channel-auth matching).
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('notifications.'.$this->userId);
    }

    /**
     * The exact payload the client receives — normalized for the toast:
     * `{ id, title, body, created_at }` (REQ realtime-delivery). Echo
     * delivers `data` verbatim to the `.listen('NotificationCreated')` handler.
     *
     * @return array{id: int, title: string, body: string, created_at: string|null}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
