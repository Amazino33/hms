<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

return new class extends Migration
{
    /**
     * Effective-dated base salary history — append-only. A raise is a new
     * dated row, never an edit to an existing one. StaffSalary::effectiveFor()
     * resolves the correct row for a given date: the greatest effective_from
     * that is <= the date in question, newest created_at wins on a tie.
     *
     * created_by is nullable specifically because this migration's own
     * backfill (below) has no real human actor — it is a system seed, not
     * a person setting a salary. Every row created afterward through the
     * app (a real raise) always has a real created_by.
     */
    public function up(): void
    {
        Schema::create('staff_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('staff_salaries')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'effective_from']);
        });

        // One-time backfill: every existing user's current users.base_salary
        // becomes their first staff_salaries row, dated the first of the
        // current month. users.base_salary itself is left in place
        // (legacy) — payroll reads exclusively from this table from here on.
        $seedDate = CarbonImmutable::now()->startOfMonth()->toDateString();
        $now = CarbonImmutable::now();

        $users = DB::table('users')->select('id', 'base_salary')->get();

        foreach ($users as $user) {
            DB::table('staff_salaries')->insert([
                'user_id' => $user->id,
                'amount' => $user->base_salary ?? 0,
                'effective_from' => $seedDate,
                'created_by' => null,
                'notes' => 'Seeded from users.base_salary at payroll module rollout.',
                'supersedes_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_salaries');
    }
};
