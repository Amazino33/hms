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
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // e.g., "Table 1", "Booth A"
            $table->integer('capacity')->default(4); // Optional: How many seats
            $table->enum('status', ['available', 'occupied', 'reserved'])->default('available');
            $table->string('location')->nullable(); // e.g., "Indoor", "Outdoor"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
