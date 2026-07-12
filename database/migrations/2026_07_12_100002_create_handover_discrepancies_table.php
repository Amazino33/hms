<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per count_session_item with a shortage (variance < 0) at
     * seal time — mirrors the transfer_discrepancies pattern (open ->
     * manager-resolved terminal states), but with two non-terminal states
     * of its own: pending_resolution (fresh, or returned here after a
     * recount) and pending_investigation (manager explicitly parked it).
     * shortfall_quantity/unit_price/naira_value are snapshotted from the
     * item at creation time — never recomputed from a live price.
     */
    public function up(): void
    {
        Schema::create('handover_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('shortfall_quantity', 10, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('naira_value', 12, 2);
            $table->enum('status', ['pending_resolution', 'pending_investigation', 'debited', 'written_off'])
                ->default('pending_resolution');
            $table->text('investigation_note')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('staff_debt_id')->nullable()->constrained('staff_debts')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_discrepancies');
    }
};
