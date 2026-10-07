<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fine amounts and thresholds, versioned by the date they take effect.
     *
     * Append-only, and that is the point: a fine issued in March must still
     * be explainable in June using March's figures. Editing a row in place
     * would silently rewrite the basis of every fine already handed out
     * under it.
     *
     * Naira as integers. These are whole-naira penalties, and float
     * arithmetic has no business near money owed by a person.
     */
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();

            $table->unsignedSmallInteger('grace_minutes')->default(15);
            $table->unsignedSmallInteger('duplicate_punch_window_minutes')->default(30);

            $table->unsignedInteger('fine_late')->default(500);
            $table->unsignedInteger('fine_late_relief')->default(1000);
            $table->unsignedInteger('fine_early_leave')->default(1500);
            $table->unsignedInteger('fine_no_clockout')->default(1500);
            $table->unsignedInteger('fine_absent')->default(3000);

            // Set by the admin once they decide what a day of pay is worth.
            $table->unsignedInteger('absence_day_pay_amount')->nullable();

            // Nothing is fined before this date; null means not yet announced.
            $table->date('rules_start_date')->nullable();

            // On until the owner is satisfied the figures are right: Phase 2
            // computes everything and records nothing.
            $table->boolean('shadow_mode')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
