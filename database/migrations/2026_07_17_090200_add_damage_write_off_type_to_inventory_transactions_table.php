<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Appended at the end, not inserted mid-list — the same safe MySQL
     * enum-widen pattern already used for 'return', 'opening_balance', and
     * 'transfer_reversal_in'.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in', 'damage_write_off'])
                ->default('purchase')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in'])
                ->default('purchase')
                ->change();
        });
    }
};
