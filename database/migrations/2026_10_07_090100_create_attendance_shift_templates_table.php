<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shift pattern: when it starts, how long it runs, and which days it
     * applies to.
     *
     * Duration rather than an end time, deliberately. "08:00 for 600 minutes"
     * and "08:00 for 1440 minutes" need no special case for crossing
     * midnight; an end_time column would need a separate "ends next day" flag
     * that every consumer would have to remember to honour.
     *
     * Nothing here is ever deleted. retired_at hides a template that
     * historical assignments still point at.
     */
    public function up(): void
    {
        Schema::create('attendance_shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->enum('pattern_type', ['weekly', 'rotation']);

            // ISO weekday numbers, 1 (Monday) through 7 (Sunday). Weekly only.
            $table->json('weekly_days')->nullable();

            // Rotation only: n days on, then n days off, repeating.
            $table->unsignedTinyInteger('rotation_on_days')->nullable();
            $table->unsignedTinyInteger('rotation_off_days')->nullable();

            // Arriving late to a handover shift strands the person waiting to
            // be relieved, so it carries the heavier late-relief fine.
            $table->boolean('is_handover')->default(false);

            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_templates');
    }
};
