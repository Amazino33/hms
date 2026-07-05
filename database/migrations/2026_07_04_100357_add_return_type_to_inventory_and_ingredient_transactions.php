<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Order-return restocking currently gets folded into 'adjustment', which
     * would make it indistinguishable from a manager-approved Stock
     * Adjustment in the audit trail. Appending 'return' at the end (not
     * inserted mid-list) keeps this a safe, metadata-only change on MySQL.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment', 'return'])->default('purchase')->change();
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'return'])->default('purchase')->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'sale', 'transfer', 'adjustment'])->default('purchase')->change();
        });

        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment'])->default('purchase')->change();
        });
    }
};
