<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only — rows are never deleted, and never edited after
     * resolution (a mistaken ruling gets a compensating record, not a
     * mutation). Exactly one of product_id/ingredient_id is set, the same
     * two-nullable-FK pattern already used by CountSessionItem and
     * HandoverDiscrepancy for "this item is either a product or an
     * ingredient" rather than a polymorphic column.
     *
     * warehouse_id is the "location" — auto-set from the reporter's own
     * context (bar warehouse for a bartender, store warehouse for a
     * storekeeper), never a user-picked value, since this system has no
     * multi-location concept to pick from in the first place.
     */
    public function up(): void
    {
        Schema::create('damage_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->text('note');
            $table->string('photo')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // Set only on approval — the InventoryTransaction or
            // IngredientTransaction the write-off was recorded as, exactly
            // one of the two, mirroring the product/ingredient split above.
            $table->foreignId('inventory_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ingredient_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_reports');
    }
};
