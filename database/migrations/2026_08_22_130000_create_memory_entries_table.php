<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-user preference memory (Fase 6). embedding is a JSON float vector,
     * nullable so a store stays resilient when the embed provider is offline
     * (roadmap §8 local-only: vector is computed locally, never egressed).
     */
    public function up(): void
    {
        Schema::create('memory_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memory_entries');
    }
};
