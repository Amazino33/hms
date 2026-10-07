<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cleaners, laundry and other attendance-only staff need a full staff
     * record without any way into the app.
     *
     * password becomes nullable because such a person has no credentials at
     * all. A placeholder hash would be a real password that somebody could in
     * principle come to know; NULL cannot be authenticated against by any
     * code path.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('staff_code');

            // Owner and CEO are never judged on attendance. Exempt users are
            // skipped by the resolver entirely, so they can never be fined
            // and never appear in the "no schedule" warning list.
            $table->boolean('attendance_exempt')->default(false)->after('job_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['job_title', 'attendance_exempt']);
        });

        // password is deliberately NOT restored to NOT NULL: by the time this
        // runs there may be attendance-only users with no password, and the
        // change would fail on them rather than invent one.
    }
};
