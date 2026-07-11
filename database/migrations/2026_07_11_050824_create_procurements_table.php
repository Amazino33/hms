<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A procurement is the storekeeper's own goods-receipt record — buying
     * from a supplier and recording it. No status/approval gate: stock
     * applies the moment it's committed, matching the existing
     * QuickInventoryUpdate "Add Stock" precedent of exempting goods
     * receipt from the four-eyes review StockAdjustment otherwise requires.
     */
    public function up(): void
    {
        Schema::create('procurements', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('location_id')->constrained('warehouses');
            $table->string('supplier_name')->nullable();
            $table->date('purchased_at');
            $table->foreignId('recorded_by')->constrained('users');
            $table->decimal('total_cost', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurements');
    }
};
