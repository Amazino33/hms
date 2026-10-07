<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A badge as the terminal knows it.
     *
     * Linking is by device_user_id and never by name: the names typed into
     * the keypad are shortened, misspelled, and sometimes shared ("Victor",
     * "VICTOR/network"), so matching on them would quietly attribute one
     * person's attendance to another.
     *
     * Retiring is one-way. Once a badge is retired its ID can never be linked
     * again to anybody, because the device reuses IDs when staff leave and a
     * reused ID would otherwise silently inherit the previous holder's
     * history.
     */
    public function up(): void
    {
        Schema::create('attendance_device_users', function (Blueprint $table) {
            $table->id();
            $table->string('device_user_id')->unique();
            $table->string('device_name')->nullable();

            // Filled from attendance_logs by the backfill, and by Phase 2 for
            // badges first seen after this ships.
            $table->timestamp('first_seen_at')->nullable();

            $table->timestamp('retired_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retired_reason')->nullable();
            $table->timestamps();

            $table->index('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_users');
    }
};
