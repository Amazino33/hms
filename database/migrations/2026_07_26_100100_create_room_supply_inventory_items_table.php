<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_supply_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_supply_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['room_supply_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_supply_inventory_items');
    }
};
