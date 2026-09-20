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
        // Dropped-then-created rather than CREATE OR REPLACE: sqlite (what
        // the test suite runs on) has no OR REPLACE for views, and this
        // pair is equivalent on MySQL.
        DB::statement('DROP VIEW IF EXISTS daily_attendances');

        DB::statement("
            CREATE VIEW daily_attendances AS
            SELECT 
                MIN(id) as id,
                user_id,
                biometric_id,
                DATE(punch_time) as date,
                MIN(punch_time) as first_punch,
                MAX(punch_time) as last_punch
            FROM attendance_logs
            GROUP BY user_id, biometric_id, DATE(punch_time)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS daily_attendances");
    }
};
