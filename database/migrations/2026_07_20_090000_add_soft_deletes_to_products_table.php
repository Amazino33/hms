<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hard DELETE on products cascades away inventory_items,
 * inventory_transactions, stock_adjustments (including theft_suspected
 * ones), count_session_items, procurement_items, and stock_transfer_items —
 * i.e. the entire accountability trail. Soft-deleting instead means
 * "deleting" a product is only ever an UPDATE, so none of that history is
 * ever actually destroyed, ProductDeletionService's cascade or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
