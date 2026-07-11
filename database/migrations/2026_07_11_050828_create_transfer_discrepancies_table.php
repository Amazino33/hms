<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Created when a transfer line is received short or rejected. Exactly
     * one of stock_transfer_item_id / ingredient_transfer_item_id is set,
     * matching the existing product/ingredient split pattern used
     * elsewhere (e.g. CountSessionItem's product_id/ingredient_id) rather
     * than a polymorphic column. Two reversal-transaction FKs for the same
     * reason — the reversal lands on whichever ledger (product or
     * ingredient) the original shortfall came from.
     */
    public function up(): void
    {
        Schema::create('transfer_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_transfer_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('missing_base_qty', 10, 2);
            $table->enum('status', ['open', 'reversed_to_store', 'written_off_missing'])->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('reversal_inventory_transaction_id')->nullable();
            $table->foreign('reversal_inventory_transaction_id', 'discrepancies_reversal_inv_txn_fk')
                ->references('id')->on('inventory_transactions')->nullOnDelete();
            $table->foreignId('reversal_ingredient_transaction_id')->nullable();
            $table->foreign('reversal_ingredient_transaction_id', 'discrepancies_reversal_ing_txn_fk')
                ->references('id')->on('ingredient_transactions')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_discrepancies');
    }
};
