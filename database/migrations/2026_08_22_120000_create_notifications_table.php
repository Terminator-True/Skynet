<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * UNIQUE(user_id, dedupe_key) is the DB-level dedupe invariant backing
     * Notification::firstOrCreate in the rule services: repeated sweeps of
     * the same event/package never insert a second row (REQ notifications-table).
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Rule identifier, e.g. calendar_event, package_update.
            $table->string('type');
            // Deterministic content hash → guards idempotent inserts.
            $table->string('dedupe_key');
            $table->json('payload');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
