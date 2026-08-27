<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A session-scoped conversation thread (D1).
 *
 * One conversation owns the messages of one client session id, so a follow-up
 * exchange survives across HTTP requests. Mirrors the MemoryEntry / NoteIndex
 * convention: #[Fillable] attribute and belongsTo/hasMany relations.
 *
 * @property int $id
 * @property string $session_id
 * @property int $user_id
 */
#[Fillable(['session_id', 'user_id'])]
class Conversation extends Model
{
    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
