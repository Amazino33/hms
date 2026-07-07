<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicitly NOT a return — no stock ever reverses for these. This is a
     * manager decision that an already-consumed/undeliverable item should
     * stop counting against the waiter's expected remittance, with a
     * mandatory reason code kept for comp/loss reporting.
     */
    public function up(): void
    {
        Schema::create('unreturnable_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $table->enum('reason_code', ['comp', 'complaint', 'loss', 'other']);
            $table->text('notes')->nullable();
            $table->integer('quantity');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unreturnable_voids');
    }
};
