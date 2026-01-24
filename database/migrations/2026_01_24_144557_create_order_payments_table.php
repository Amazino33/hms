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
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete(); // Links to the Order
            $table->foreignId('user_id')->constrained(); // Links to the Staff who collected money
            $table->decimal('amount', 10, 2); // How much was paid NOW
            $table->string('method')->default('cash'); // Cash, Transfer, POS
            $table->timestamp('paid_at'); // When it happened
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
