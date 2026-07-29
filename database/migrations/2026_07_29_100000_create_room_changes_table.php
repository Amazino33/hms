<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A record of a guest moving rooms mid-stay — most often a service
     * recovery move (a fault in the original room), sometimes a guest-
     * requested upgrade/downgrade. The booking itself just moves to the
     * new room_id (same booking, same folio, continuous stay — never a
     * new booking); this table is purely the audit trail of what moved,
     * why, and what the billing rate did as a result.
     */
    public function up(): void
    {
        Schema::create('room_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_room_id')->constrained('rooms');
            $table->foreignId('to_room_id')->constrained('rooms');
            $table->enum('reason', ['maintenance_fault', 'guest_preference', 'noise_complaint', 'upgrade', 'downgrade', 'other']);
            $table->text('note')->nullable();
            $table->decimal('old_nightly_rate', 10, 2);
            $table->decimal('new_nightly_rate', 10, 2);
            $table->unsignedInteger('remaining_nights_rebilled')->default(0);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_changes');
    }
};
