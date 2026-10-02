<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — the guest's live bill, payment claims, waiter calls, and
     * moving a sitting to another table.
     *
     * Money is decimal(10,2) naira, like order_items. Claims and moves are
     * append-only (D23, D4): a claim's status is set exactly once more at
     * payment or withdrawal, and nothing ever edits or deletes a move.
     */
    public function up(): void
    {
        Schema::table('guest_table_sessions', function (Blueprint $table) {
            // D19: where the live bill starts. Moves back on "Same guests? Yes".
            $table->timestamp('bill_from_at')->nullable()->after('opened_at');
            $table->foreignId('closed_by_user_id')->nullable()->after('close_reason')->constrained('users')->nullOnDelete();
        });

        DB::table('guest_table_sessions')->whereNull('bill_from_at')->update(['bill_from_at' => DB::raw('opened_at')]);

        Schema::create('guest_payment_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_table_session_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 32);
            $table->string('payer_name', 60);
            $table->decimal('amount', 10, 2);
            $table->foreignId('transfer_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('open'); // open | withdrawn | matched | unmatched
            $table->timestamp('status_set_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guest_table_session_id', 'status']);
            $table->index(['device_id', 'status']);
        });

        Schema::create('guest_waiter_calls', function (Blueprint $table) {
            $table->id();
            // A call can come before the table has any request, so no session yet.
            $table->foreignId('guest_table_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 32);
            $table->string('reason', 8); // ice | cups | bill | other
            $table->string('note', 60)->nullable();
            $table->string('status', 14)->default('open'); // open | acknowledged | expired
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['status', 'created_at']);
            $table->index(['device_id', 'created_at']);
        });

        Schema::create('table_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_table_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_table_id')->constrained('tables');
            $table->foreignId('to_table_id')->constrained('tables');
            $table->json('order_ids');
            $table->json('request_ids');
            $table->foreignId('moved_by_user_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['from_table_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_moves');
        Schema::dropIfExists('guest_waiter_calls');
        Schema::dropIfExists('guest_payment_claims');

        Schema::table('guest_table_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by_user_id');
            $table->dropColumn('bill_from_at');
        });
    }
};
