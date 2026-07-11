<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per line entered against a product (the ingredient
     * equivalent is procurement_ingredient_items). The same product can
     * appear on multiple lines in one procurement — e.g. 2 crates + 5
     * loose bottles — since loose-bottle pricing often differs from crate
     * pricing; base_qty/unit_cost are always the authoritative converted
     * figures, entered_qty/entered_unit/the snapshot are only for display
     * and historical readability if pack sizes change later.
     */
    public function up(): void
    {
        Schema::create('procurement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('entered_qty', 10, 2);
            $table->enum('entered_unit', ['purchase_unit', 'base_unit']);
            $table->unsignedInteger('units_per_purchase_unit_snapshot')->nullable();
            $table->decimal('base_qty', 10, 2);
            $table->decimal('line_total_cost', 12, 2);
            $table->decimal('unit_cost', 12, 4);
            $table->foreignId('inventory_transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_items');
    }
};
