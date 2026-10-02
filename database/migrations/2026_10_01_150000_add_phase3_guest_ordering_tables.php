<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — guest requests become real orders (food at waiter
     * acceptance, drinks at bartender release).
     *
     * guest_request_items gains the order line it became. Its status column
     * is a string, so the new 'needs_waiter' value needs no schema change.
     *
     * The three log tables are append-only. A wait/conflict log is closed
     * exactly once (ended_at / resolved_at) by GuestBarMonitor; nothing
     * else ever changes them.
     */
    public function up(): void
    {
        Schema::table('guest_request_items', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('guest_request_id')->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });

        Schema::create('guest_session_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_table_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users');
            $table->json('request_ids');
            $table->timestamp('created_at')->useCurrent();
        });

        // A stretch of time with guest drinks waiting and NO bartender shift.
        Schema::create('bar_shift_wait_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('max_waiting_lines')->default(0);
            $table->timestamp('alerted_manager_at')->nullable();
            $table->timestamps();
        });

        // A stretch of time with MORE THAN ONE bartender shift open (D1).
        Schema::create('bar_shift_conflict_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('detected_at');
            $table->json('shift_ids');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bar_shift_conflict_logs');
        Schema::dropIfExists('bar_shift_wait_logs');
        Schema::dropIfExists('guest_session_handovers');

        Schema::table('guest_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
