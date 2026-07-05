<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared engine behind Bar handover, Kitchen handover, and Main Store
     * daily stocktake. Expected quantities are snapshotted at open and never
     * exposed while status = 'counting' (blind counting) — only surfaced to
     * the manager once the session reaches 'pending_review'.
     */
    public function up(): void
    {
        Schema::create('count_sessions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['bar_handover', 'kitchen_handover', 'main_store_stocktake']);
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['counting', 'pending_review', 'reviewed'])->default('counting');

            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('opened_at');

            // Handover-only: the two people whose custody the stock is
            // moving between. Null for a single-person stocktake.
            $table->foreignId('outgoing_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('incoming_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_by_outgoing_at')->nullable();
            $table->timestamp('confirmed_by_incoming_at')->nullable();

            $table->timestamp('submitted_for_review_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_sessions');
    }
};
