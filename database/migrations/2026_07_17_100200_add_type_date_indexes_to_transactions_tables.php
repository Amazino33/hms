<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner reporting's product analytics (fast/slow movers, days-of-stock)
 * aggregate in SQL across every product for a date range filtered by
 * type=sale — the existing (product_id, warehouse_id, created_at) index
 * leads with product_id, which doesn't help a query that groups across
 * products. This is the (type, created_at) companion for that access
 * pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'inventory_transactions_type_created_idx');
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'ingredient_transactions_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex('inventory_transactions_type_created_idx');
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->dropIndex('ingredient_transactions_type_created_idx');
        });
    }
};
