<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_supply_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_supply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['purchase', 'usage', 'transfer', 'adjustment', 'opening_balance'])->default('purchase');
            $table->decimal('quantity', 10, 2);
            $table->decimal('cost_per_unit', 12, 2)->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Explicit short name — the auto-generated one
            // ("room_supply_transactions_room_supply_id_warehouse_id_created_at_index")
            // exceeds MySQL's 64-character identifier limit. SQLite (the
            // test suite's driver) doesn't enforce that limit, so this only
            // surfaces against real MySQL.
            $table->index(['room_supply_id', 'warehouse_id', 'created_at'], 'room_supply_transactions_supply_wh_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_supply_transactions');
    }
};
