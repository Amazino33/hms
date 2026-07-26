<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Housekeeping consumables (tissue, soap, etc.) — a third stock track,
     * deliberately parallel to Product (bar) and Ingredient (kitchen)
     * rather than reusing either, so room-supply stock stays structurally
     * independent from sellable bar stock.
     */
    public function up(): void
    {
        Schema::create('room_supplies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('unit')->default('unit');
            $table->decimal('cost_per_unit', 12, 2)->default(0);
            $table->string('purchase_unit_name')->nullable();
            $table->unsignedInteger('units_per_purchase_unit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_supplies');
    }
};
