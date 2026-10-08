<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the bridge table.
     *
     * biometric_enrollments was how device names reached the app before
     * attendance_device_users existed, and a mirror observer kept the two in
     * step through Phase 1. ZKTecoController now writes the new table
     * directly, so the bridge has nothing left to carry.
     *
     * Renamed rather than dropped. It holds the 35 names typed in by hand in
     * September, and those are the only record of what the terminal called
     * people before any of this was automated — worth keeping until somebody
     * is certain the new table is complete.
     */
    public function up(): void
    {
        if (Schema::hasTable('biometric_enrollments') && ! Schema::hasTable('biometric_enrollments_legacy')) {
            Schema::rename('biometric_enrollments', 'biometric_enrollments_legacy');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('biometric_enrollments_legacy') && ! Schema::hasTable('biometric_enrollments')) {
            Schema::rename('biometric_enrollments_legacy', 'biometric_enrollments');
        }
    }
};
