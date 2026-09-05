<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The signatures. Written once, never updated, never deleted — same
     * principle as FolioLine: "I have read this" only means something if
     * it cannot be revised after the fact.
     *
     * The unique index is load-bearing, not decorative. A staff member
     * double-tapping the button on a laggy kiosk must produce one
     * signature, not two, and AnnouncementService::acknowledge() leans on
     * this constraint rather than on a check-then-insert race.
     */
    public function up(): void
    {
        Schema::create('announcement_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('acknowledged_at');

            // Which guard the signature came through — 'web' (Filament
            // panel) or 'staff_pin' (kiosk / personal device). Recorded so
            // a later dispute can tell an office confirmation from one
            // tapped on the floor.
            $table->enum('context', ['admin', 'kiosk']);

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_acknowledgements');
    }
};
