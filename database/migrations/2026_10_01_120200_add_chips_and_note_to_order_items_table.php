<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A — what the guest asked for on this line: a snapshot of chip
     * LABELS (e.g. ["Cold"]), never chip ids, plus a short free-text note.
     * Shown on the bar display and KDS; filled in by later phases.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('chips')->nullable();
            $table->string('note', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['chips', 'note']);
        });
    }
};
