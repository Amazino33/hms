<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ProductionOrder was a parallel kitchen-ticket tracker created as a
     * side effect of every order, but nothing outside its own resource ever
     * read its status — the actual kitchen queue has always been driven by
     * Order/OrderItem directly through KitchenDisplay. Removed as dead
     * weight rather than left to confuse kitchen staff with a second,
     * disconnected "done" button that did nothing.
     */
    public function up(): void
    {
        Schema::dropIfExists('production_orders');
    }

    public function down(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('menu_item_name');
            $table->integer('quantity');
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
};
