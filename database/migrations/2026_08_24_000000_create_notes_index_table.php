<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived read-only cache of the local Obsidian vault (roadmap §8 local-only).
     * One row per chunk; a note with multiple chunks shares a path but differs on
     * chunk_index, so uniqueness is composite (D1). embedding is nullable so an
     * offline embed provider does not block storage (MemoryEntry precedent).
     */
    public function up(): void
    {
        Schema::create('notes_index', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('relative_path');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->string('content_hash');
            $table->timestamps();

            $table->unique(['path', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_index');
    }
};
