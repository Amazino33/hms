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
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('status')->default('reserved')->after('check_out');
            // Frozen at reservation creation — check-in and any later
            // room-rate change never re-reads Room.price_per_night for an
            // existing booking. total_price (existing column) stays as a
            // quick display total; the folio becomes the real balance
            // source of truth once room-charge lines exist (Step 3).
            $table->decimal('nightly_rate', 10, 2)->nullable()->after('total_price');
            $table->decimal('deposit', 10, 2)->nullable()->after('nightly_rate');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('deposit');
            // Nullable and unused until receptionist shifts exist (a later
            // step) — reservations created before then simply have no
            // shift attribution, not an error.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete()->after('created_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['status', 'nightly_rate', 'deposit']);
        });
    }
};
