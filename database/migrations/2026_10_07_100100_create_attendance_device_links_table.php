<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who a badge belonged to, and when. Append-only history.
     *
     * Two distinct ways a link stops, and they mean opposite things:
     *
     *   effective_to — the person left. The link was real; their punches up
     *   to that date are genuinely theirs and stay attributed to them.
     *
     *   voided_at — the link was a mistake. It should never have existed, so
     *   the punches it claimed are not theirs and get re-attributed.
     *
     * Collapsing the two into one "ended" flag would make it impossible to
     * tell a leaver's history from a clerical error, which is exactly the
     * distinction a disputed fine turns on.
     */
    public function up(): void
    {
        Schema::create('attendance_device_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_device_user_id')
                ->constrained(
                    table: 'attendance_device_users',
                    indexName: 'adl_device_user_id_foreign',
                )
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attendance_device_user_id', 'voided_at', 'effective_to'], 'adl_active_link_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_device_links');
    }
};
