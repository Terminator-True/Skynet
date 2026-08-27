<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single persisted chat message within a conversation (D1).
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array<int, mixed>|null $tool_trace
 */
#[Fillable(['conversation_id', 'role', 'content', 'tool_trace'])]
class Message extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_trace' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
