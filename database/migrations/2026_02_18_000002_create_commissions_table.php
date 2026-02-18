<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();   // waiter
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();  // the paid order
            $table->decimal('amount', 10, 2);                         // total commission for this order
            $table->timestamp('created_at')->useCurrent();

            // Prevent duplicate commission records for the same order
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
