<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A charge arising from one judged shift. Append-only; only the void
     * fields ever change.
     *
     * Every number here is a snapshot taken when the row was written — the
     * amount, the settings version, and whether it was shadow. None of it is
     * looked up again later, because the whole point is that a person can be
     * shown why they were charged what they were charged on the day, not what
     * the rules happen to say now.
     */
    public function up(): void
    {
        Schema::create('attendance_fines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shift_record_id')
                ->constrained('attendance_shift_records', indexName: 'af_shift_record_id_foreign')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('shift_date');

            // A fine is a penalty; a pay_deduction is withheld wages for a day
            // not worked. Different things that must never be summed into one
            // figure and shown to somebody as "fines".
            $table->enum('kind', ['fine', 'pay_deduction'])->default('fine');

            $table->enum('type', [
                'late',
                'late_relief',
                'early_leave',
                'no_clockout',
                'absent',
                'absence_day_pay',
            ]);

            // Whole naira. Never a float — this is money owed by a person.
            $table->unsignedInteger('amount');

            $table->foreignId('attendance_setting_id')->nullable()
                ->constrained('attendance_settings', indexName: 'af_setting_id_foreign')
                ->nullOnDelete();

            /*
             * Fixed at creation and never recomputed. Turning shadow mode off
             * must not retroactively make months of practice runs chargeable —
             * that would hand people a bill for a period they were told was a
             * trial.
             */
            $table->boolean('is_shadow')->default(true);

            $table->dateTime('voided_at')->nullable();
            // Null causer means the system voided it (a re-evaluation), as
            // opposed to a person deciding to.
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            // Unused until Phase 3. Present now so the "never void something
            // already paid" guard can be written and tested before anything
            // can actually set it.
            $table->unsignedBigInteger('payroll_run_id')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'shift_date'], 'af_user_date_index');
            $table->index(['shift_date', 'type'], 'af_date_type_index');
            $table->index('voided_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_fines');
    }
};
