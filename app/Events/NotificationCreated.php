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
     * @param  array<string, mixed>  $payload  denormalized notification payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('private-notifications.'.$this->userId);
    }
}
