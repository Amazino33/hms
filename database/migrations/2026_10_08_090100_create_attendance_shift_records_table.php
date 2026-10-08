<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One judged shift: what somebody was due to work, what the device
     * recorded, and what that adds up to.
     *
     * Never edited after insert. Re-evaluating writes a new row and marks the
     * old one superseded, so the reasoning behind a fine raised in March is
     * still readable in June even after the schedule, the settings or the
     * punch attribution have all changed since.
     */
    public function up(): void
    {
        Schema::create('attendance_shift_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()
                ->constrained('attendance_shift_assignments', indexName: 'asr_assignment_id_foreign')
                ->nullOnDelete();
            $table->foreignId('template_id')->nullable()
                ->constrained('attendance_shift_templates', indexName: 'asr_template_id_foreign')
                ->nullOnDelete();

            // The exact settings version used. Without it a fine cannot be
            // re-derived: the amounts and the grace period may both have moved
            // on by the time anybody disputes it.
            $table->foreignId('attendance_setting_id')->nullable()
                ->constrained('attendance_settings', indexName: 'asr_setting_id_foreign')
                ->nullOnDelete();

            // Lagos date of the scheduled START. An overnight shift belongs to
            // the day it began, so Tuesday night is Tuesday.
            $table->date('shift_date');
            $table->boolean('is_handover')->default(false);

            // All UTC, like every other instant in this app.
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->dateTime('window_start_at');
            $table->dateTime('window_end_at');

            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->foreignId('clock_in_log_id')->nullable()
                ->constrained('attendance_logs', indexName: 'asr_clock_in_log_foreign')
                ->nullOnDelete();
            $table->foreignId('clock_out_log_id')->nullable()
                ->constrained('attendance_logs', indexName: 'asr_clock_out_log_foreign')
                ->nullOnDelete();

            // Raw vs collapsed, kept separately so "they punched four times"
            // and "that counted as one" are both visible on the detail view.
            $table->unsignedSmallInteger('raw_punch_count')->default(0);
            $table->unsignedSmallInteger('collapsed_punch_count')->default(0);

            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);

            $table->enum('outcome', [
                'present',
                'late',
                'late_relief',
                'early_leave',
                'late_and_early_leave',
                'no_clockout',
                'late_no_clockout',
                'absent',
                'unlinked',
            ]);

            // Things a person should look at but that carry no fine.
            $table->json('review_flags')->nullable();

            $table->dateTime('finalised_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('attendance_shift_records', indexName: 'asr_superseded_by_foreign')
                ->nullOnDelete();
            $table->string('supersede_reason')->nullable();

            $table->timestamps();

            /*
             * "One current record per person per scheduled shift" — but only
             * among non-superseded rows, and MySQL has no partial unique
             * index. So the uniqueness lives in a column: set while current,
             * NULL once superseded, and NULLs do not collide in a unique
             * index on either MySQL or sqlite.
             *
             * This is what makes the finaliser safe to run every 15 minutes:
             * a concurrent second run loses the insert rather than writing a
             * duplicate judgement.
             */
            $table->string('current_key')->nullable()->unique();

            $table->index(['shift_date', 'outcome'], 'asr_date_outcome_index');
            $table->index(['user_id', 'shift_date'], 'asr_user_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_records');
    }
};
