<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('fridge_par', 10, 2)->nullable()->after('cost_price');
        });

        // Deliberately NOT an InventoryTransaction — moving stock from floor
        // to fridge within the same warehouse doesn't change total warehouse
        // stock, so it must never touch the transaction ledger. This is a
        // lightweight marker only: "as of this moment, the fridge held this
        // much" — the fridge estimate service subtracts sales since to decay
        // it, and every newly reviewed count session resets reality anyway.
        Schema::create('fridge_restock_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('marked_quantity', 10, 2);
            $table->timestamp('marked_at');
            $table->foreignId('marked_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fridge_restock_marks');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('fridge_par');
        });
    }
};
