<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name typed into the terminal when a badge was enrolled.
     *
     * Kept in its own table rather than on attendance_logs or users because
     * it belongs to neither: a punch does not carry a name (ATTLOG is just a
     * PIN and a timestamp), and a machine ID frequently has no staff profile
     * behind it at all — the whole point of showing this column is to put a
     * name against the rows where Staff Member is blank.
     *
     * One row per enrolled badge, keyed on the same machine ID the punches
     * carry.
     */
    public function up(): void
    {
        Schema::create('biometric_enrollments', function (Blueprint $table) {
            $table->id();
            $table->string('biometric_id')->unique();
            $table->string('name')->nullable();
            $table->string('privilege')->nullable();
            $table->string('card')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_enrollments');
    }
};
