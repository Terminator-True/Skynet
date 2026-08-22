<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Application-side in-app notification (Fase 5).
 *
 * NOTE: this is a custom table for our proactive rules and deliberately does
 * NOT extend Laravel's Illuminate\Notifications\Notification base (that class
 * is the transport concern used by the Notifiable trait). No collision: we are
 * a plain Eloquent Model in the App\Models namespace.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $dedupe_key
 * @property array<string, mixed> $payload
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'type', 'payload', 'dedupe_key', 'read_at'])]
class Notification extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
