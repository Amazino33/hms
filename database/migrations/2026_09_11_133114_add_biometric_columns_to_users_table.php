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
        Schema::table('users', function (Blueprint $table) {
            $table->string('biometric_id')->nullable()->after('id')->comment('Device user ID from ZKTeco');
            $table->time('shift_start_time')->nullable()->after('biometric_id')->comment('Expected arrival time, e.g. 08:00:00');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['biometric_id', 'shift_start_time']);
        });
    }
};
