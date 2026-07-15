<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing settlement lifecycle (status is a plain string
 * column, no DB enum, so new values need no ALTER) rather than replacing
 * it. New flow: active -> awaiting_cashier -> confirmed, with 'flagged'
 * as an additional value entered when a POS-machine dispute is open
 * (a disputed transfer doesn't change the shift's own status — it's
 * tracked per order_payments row instead — but a POS-machine mismatch
 * has no natural row of its own, so it lives here).
 *
 * Data backfill: any shift currently sitting in the old 'pending_supervisor'
 * status (declared, never confirmed under the old single-step supervisor
 * flow) becomes 'awaiting_cashier' — nothing lost, it now surfaces
 * correctly in the cashier's queue instead of sitting invisible.
 * 'pending_supervisor' was only ever used by waiter/receptionist shifts
 * (confirmed by audit), so this half of the backfill is unscoped.
 *
 * Existing 'closed' waiter/receptionist shifts become 'confirmed' so
 * there is exactly one terminal status string for THIS lifecycle going
 * forward — deliberately scoped by type, since bartender/chef shifts
 * ALSO use 'closed' as their own terminal status (their dual-PIN
 * handover seal, a completely separate, out-of-scope lifecycle) and
 * must keep writing 'closed' unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('cashier_counted_cash', 10, 2)->nullable()->after('supervisor_confirmed_cash');
            $table->foreignId('cash_confirmed_by')->nullable()->after('cashier_counted_cash')->constrained('users')->nullOnDelete();
            $table->timestamp('cash_confirmed_at')->nullable()->after('cash_confirmed_by');

            $table->decimal('pos_machine_confirmed_amount', 10, 2)->nullable()->after('cash_confirmed_at');
            $table->foreignId('pos_confirmed_by')->nullable()->after('pos_machine_confirmed_amount')->constrained('users')->nullOnDelete();
            $table->timestamp('pos_confirmed_at')->nullable()->after('pos_confirmed_by');

            $table->boolean('pos_flagged')->default(false)->after('pos_confirmed_at');
            $table->text('pos_flag_note')->nullable()->after('pos_flagged');
            $table->string('pos_ruling')->nullable()->after('pos_flag_note'); // late_verify|charge|void
            $table->text('pos_ruling_note')->nullable()->after('pos_ruling');
            $table->foreignId('pos_ruled_by')->nullable()->after('pos_ruling_note')->constrained('users')->nullOnDelete();
            $table->timestamp('pos_ruled_at')->nullable()->after('pos_ruled_by');
        });

        DB::table('shifts')->where('status', 'pending_supervisor')->update(['status' => 'awaiting_cashier']);
        DB::table('shifts')
            ->where('status', 'closed')
            ->whereIn('type', ['waiter', 'receptionist'])
            ->update(['status' => 'confirmed']);
    }

    public function down(): void
    {
        DB::table('shifts')->where('status', 'awaiting_cashier')->update(['status' => 'pending_supervisor']);
        DB::table('shifts')
            ->where('status', 'confirmed')
            ->whereIn('type', ['waiter', 'receptionist'])
            ->update(['status' => 'closed']);

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_confirmed_by');
            $table->dropConstrainedForeignId('pos_confirmed_by');
            $table->dropConstrainedForeignId('pos_ruled_by');
            $table->dropColumn([
                'cashier_counted_cash', 'cash_confirmed_at',
                'pos_machine_confirmed_amount', 'pos_confirmed_at',
                'pos_flagged', 'pos_flag_note', 'pos_ruling', 'pos_ruling_note', 'pos_ruled_at',
            ]);
        });
    }
};
