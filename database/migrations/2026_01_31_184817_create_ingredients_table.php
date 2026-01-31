<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
    $table->string('name');
            $table->string('sku')->unique();
            $table->string('unit_name'); // e.g., kg, liters
            $table->decimal('quantity', 10, 2); // Initial/current quantity
            $table->decimal('cost_per_unit', 10, 2);
            $table->string('category'); // e.g., Vegetables, Proteins
    $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
