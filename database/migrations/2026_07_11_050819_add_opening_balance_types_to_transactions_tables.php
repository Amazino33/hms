<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * opening_balance: the one-time seeded starting stock. transfer_reversal_in:
     * a manager reversing a transfer discrepancy back onto main store. Both
     * appended at the end, not inserted mid-list — the established safe
     * pattern for a MySQL enum widen (see the 'return' type migration).
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in'])
                ->default('purchase')
                ->change();
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'return', 'opening_balance', 'transfer_reversal_in'])
                ->default('purchase')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment', 'return'])
                ->default('purchase')
                ->change();
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'return'])
                ->default('purchase')
                ->change();
        });
    }
};
