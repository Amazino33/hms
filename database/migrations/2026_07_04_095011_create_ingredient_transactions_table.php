<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment'])->default('purchase');
            $table->decimal('quantity', 10, 2);
            $table->decimal('cost_per_unit', 10, 2)->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['ingredient_id', 'warehouse_id', 'created_at'], 'ingredient_transactions_ing_wh_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_transactions');
    }
};
