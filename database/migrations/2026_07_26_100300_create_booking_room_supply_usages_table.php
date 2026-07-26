<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What room supplies were used for a specific stay, at the cost that
     * applied when they were used (unit_cost_at_use is a snapshot — later
     * changes to room_supplies.cost_per_unit never retroactively change a
     * past stay's recorded cost). room_supply_transaction_id links to the
     * matching stock-deduction row for a full audit trail.
     */
    public function up(): void
    {
        Schema::create('booking_room_supply_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_supply_id')->constrained();
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_cost_at_use', 12, 2);
            $table->foreignId('room_supply_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_room_supply_usages');
    }
};
