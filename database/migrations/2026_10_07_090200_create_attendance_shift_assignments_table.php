<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which pattern a person is on, and from when.
     *
     * Append-only: a schedule change closes the current row and opens a new
     * one, so what someone was expected to work last month survives this
     * month's change. That history is the entire basis on which a fine can
     * later be defended or overturned.
     *
     * Only the closing fields (effective_to, ended_by) are ever updated.
     */
    public function up(): void
    {
        Schema::create('attendance_shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Explicit short names throughout this table: the generated ones
            // ("attendance_shift_assignments_attendance_shift_template_id_foreign")
            // run past MySQL's 64-character identifier limit and the migration
            // dies mid-run.
            $table->foreignId('attendance_shift_template_id')
                ->constrained(
                    table: 'attendance_shift_templates',
                    indexName: 'asa_template_id_foreign',
                )
                ->restrictOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            // Weekly templates only: this person works a subset of the
            // template's days (template says all 7, this one is Mon-Fri).
            $table->json('weekly_days_override')->nullable();

            // Rotation templates only, and required for them: a date this
            // person is known to be ON. The whole pattern derives from it.
            $table->date('rotation_anchor_date')->nullable();

            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The resolver's hot path: assignments covering a date for one
            // person.
            $table->index(['user_id', 'effective_from', 'effective_to'], 'asa_user_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_assignments');
    }
};
