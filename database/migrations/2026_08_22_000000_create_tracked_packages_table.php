<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * UNIQUE(user_id, source_email_id) is a DB-level invariant: repeated
     * extraction of the same email updates the existing row instead of
     * duplicating (backs TrackedPackage::updateOrCreate in ExtraerTrackingAmazon).
     */
    public function up(): void
    {
        Schema::create('tracked_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Gmail message id the package was extracted from.
            $table->string('source_email_id');
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('product_name')->nullable();
            $table->string('status')->nullable();
            // Rarely present in shipped emails; stays null until Fase 5 parsing.
            $table->date('estimated_delivery')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source_email_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tracked_packages');
    }
};
