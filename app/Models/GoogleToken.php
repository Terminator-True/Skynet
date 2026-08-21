<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $access_token
 * @property string $refresh_token
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property array<int, string> $scopes
 */
#[Fillable(['user_id', 'access_token', 'refresh_token', 'expires_at', 'scopes'])]
#[Hidden(['access_token', 'refresh_token'])]
class GoogleToken extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * Encrypted casts store AES-256-GCM ciphertext under APP_KEY. Rotating
     * APP_KEY bricks every stored token (loud DecryptException) — back up the
     * key before rotation.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
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
