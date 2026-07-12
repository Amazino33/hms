<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A manager-ordered verification recount, PIN-signed by a counter and
     * a witness (mirrors the unwitnessed handover's witness co-sign
     * pattern) — appended as a child record, never mutating the original
     * discrepancy/snapshot line it recounts.
     */
    public function up(): void
    {
        Schema::create('handover_discrepancy_recounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('handover_discrepancy_id')->constrained()->cascadeOnDelete();
            $table->decimal('new_quantity', 10, 2);
            $table->decimal('recomputed_variance', 10, 2);
            $table->foreignId('counted_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('witnessed_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('inventory_transaction_id')->nullable();
            $table->foreign('inventory_transaction_id', 'recounts_inventory_txn_fk')
                ->references('id')->on('inventory_transactions')->nullOnDelete();
            $table->foreignId('ingredient_transaction_id')->nullable();
            $table->foreign('ingredient_transaction_id', 'recounts_ingredient_txn_fk')
                ->references('id')->on('ingredient_transactions')->nullOnDelete();
            $table->foreignId('ordered_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_discrepancy_recounts');
    }
};
