<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How wide a shift's catchment is, and how long to wait before judging it.
     *
     * Versioned with everything else in this table: widen the window next
     * month and last month's shifts must still be explainable by the window
     * that was in force when they were evaluated.
     */
    public function up(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            // A punch this far before the scheduled start still belongs to the
            // shift — people arrive early, and a 07:30 punch for an 08:00
            // start is a clock-in, not a stray.
            $table->unsignedSmallInteger('window_before_minutes')->default(120)->after('duplicate_punch_window_minutes');

            // And this far after the scheduled end. Generous on purpose: a
            // punch that falls outside every window counts as nothing at all,
            // which turns a late clock-out into an absence.
            $table->unsignedSmallInteger('window_after_minutes')->default(240)->after('window_before_minutes');

            // Breathing room after the window closes before anything is
            // judged, so an ADMS push still in flight is not read as absence.
            $table->unsignedSmallInteger('finalise_delay_minutes')->default(60)->after('window_after_minutes');

            // How long to keep waiting on a device that has gone quiet. A
            // terminal offline for a day buffers its punches and dumps them on
            // reconnect; finalising before that arrives marks a full shift
            // absent for everybody.
            $table->unsignedSmallInteger('max_device_wait_minutes')->default(1440)->after('finalise_delay_minutes');

            // Tolerance on leaving early. Zero by default — the rule as
            // written has no grace — but versioned so it can be softened
            // without rewriting history.
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0)->after('max_device_wait_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'window_before_minutes',
                'window_after_minutes',
                'finalise_delay_minutes',
                'max_device_wait_minutes',
                'early_leave_grace_minutes',
            ]);
        });
    }
};
