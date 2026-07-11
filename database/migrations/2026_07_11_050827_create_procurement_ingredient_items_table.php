<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ingredient-side twin of procurement_items, same shape.
     */
    public function up(): void
    {
        Schema::create('procurement_ingredient_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('entered_qty', 10, 2);
            $table->enum('entered_unit', ['purchase_unit', 'base_unit']);
            $table->unsignedInteger('units_per_purchase_unit_snapshot')->nullable();
            $table->decimal('base_qty', 10, 2);
            $table->decimal('line_total_cost', 12, 2);
            $table->decimal('unit_cost', 12, 4);
            $table->foreignId('ingredient_transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_ingredient_items');
    }
};
