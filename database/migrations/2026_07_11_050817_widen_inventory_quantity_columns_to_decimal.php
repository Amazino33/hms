<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both columns were integer-only, which silently truncates a fractional
     * base-unit conversion (e.g. a future non-whole pack size). Widening to
     * decimal matches what the ingredient side already does
     * (ingredient_inventory_items / ingredient_transactions are both
     * decimal(10,2)) and costs nothing for the common whole-bottle case.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(0)->change();
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }
};
