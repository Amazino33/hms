<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waiter shifts only — a receptionist's cash/POS confirmation stays exactly
 * as it was (one combined figure, no bar/kitchen concept). A waiter's cash
 * is instead confirmed once per (destination, channel) that actually had
 * activity this shift, e.g. bar-cash, bar-pos, kitchen-cash, kitchen-pos.
 * The aggregate is then rolled up onto shifts.cashier_counted_cash /
 * pos_machine_confirmed_amount so every existing consumer (CEO reports,
 * Waiter Ledger, the Shift Management resource) keeps reading one number
 * without needing to know this table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_channel_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->enum('destination', ['bar', 'kitchen']);
            $table->enum('channel', ['cash', 'pos']);
            $table->decimal('expected_amount', 10, 2)->default(0);
            $table->decimal('confirmed_amount', 10, 2)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            // POS-only: mirrors shifts.pos_flagged/pos_ruling, scoped to
            // just this destination's channel row instead of the whole shift.
            $table->boolean('flagged')->default(false);
            $table->enum('ruling', ['late_verify', 'charge', 'void'])->nullable();
            $table->text('ruling_note')->nullable();
            $table->foreignId('ruled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ruled_at')->nullable();

            $table->timestamps();

            $table->unique(['shift_id', 'destination', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_channel_confirmations');
    }
};
