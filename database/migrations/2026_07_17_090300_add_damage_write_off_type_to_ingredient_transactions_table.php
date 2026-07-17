<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the inventory_transactions widen — a storekeeper's damage
     * report can be against an ingredient, not just a product.
     */
    public function up(): void
    {
        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in', 'damage_write_off'])
                ->default('purchase')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in'])
                ->default('purchase')
                ->change();
        });
    }
};
