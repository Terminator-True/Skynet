<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single indexed chunk of a local Obsidian note (read-only derived cache).
 *
 * Eloquent would pluralise NoteIndex to "note_indices", so the table name is
 * pinned explicitly. Mirrors the MemoryEntry convention: fillable attribute,
 * embedding cast to array, belongsTo User (single tenant).
 *
 * @property array<int, float>|null $embedding
 */
#[Fillable(['user_id', 'path', 'relative_path', 'chunk_index', 'content', 'embedding', 'content_hash', 'updated_at'])]
class NoteIndex extends Model
{
    protected $table = 'notes_index';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
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
