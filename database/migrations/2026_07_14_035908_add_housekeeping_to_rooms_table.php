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
        Schema::table('rooms', function (Blueprint $table) {
            // Occupancy (Vacant/Occupied/Due Out/Arriving) is derived live
            // from bookings, never stored here — this column is the one
            // piece of genuine room state that isn't derivable: whether
            // housekeeping has actually cleaned the room since the last
            // guest left. Checkout sets this to 'dirty'; check-in into a
            // dirty room is blocked server-side.
            $table->string('housekeeping')->default('clean')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('housekeeping');
        });
    }
};
