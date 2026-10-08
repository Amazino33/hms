<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this badge was last used.
     *
     * Distinct from first_seen_at, and distinct again from the device's own
     * contact stamps in zkteco_devices: those say the terminal is alive, this
     * says a particular person is still punching. A badge that stops while the
     * terminal keeps reporting is somebody who left without anybody telling
     * the office — which otherwise shows up as a month of ₦3,000 absences.
     */
    public function up(): void
    {
        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_device_users', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
