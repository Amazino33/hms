<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A bar/kitchen handover never creates a discrepancy row for an
     * overage (sealAgreement() only tracks it as a session-level total) —
     * that stays unchanged. A solo store count does, so this table needs a
     * direction to tell the two apart: 'shortage' rows keep their existing
     * meaning and actions (debit/write-off/pend/recount); 'overage' rows
     * get a narrower action set (acknowledge/pend only — there is no
     * debtor and nothing to write off, the stock is already trued up).
     *
     * Widening the status enum can't be done as a plain in-place ALTER
     * without doctrine/dbal, so existing status values are captured and
     * restored around the drop/recreate rather than trusting a default
     * backfill — this table is small (manager-resolution volume, not
     * transactional), but data loss on a migration is data loss.
     */
    public function up(): void
    {
        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->string('direction')->default('shortage')->after('count_session_item_id');
        });

        DB::table('handover_discrepancies')->update(['direction' => 'shortage']);

        $existingStatuses = DB::table('handover_discrepancies')->pluck('status', 'id');

        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->enum('status', ['pending_resolution', 'pending_investigation', 'debited', 'written_off', 'acknowledged'])
                ->default('pending_resolution')
                ->after('naira_value');
        });

        foreach ($existingStatuses as $id => $status) {
            DB::table('handover_discrepancies')->where('id', $id)->update(['status' => $status]);
        }
    }

    public function down(): void
    {
        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->dropColumn('direction');
        });

        $existingStatuses = DB::table('handover_discrepancies')->pluck('status', 'id');

        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('handover_discrepancies', function (Blueprint $table) {
            $table->enum('status', ['pending_resolution', 'pending_investigation', 'debited', 'written_off'])
                ->default('pending_resolution')
                ->after('naira_value');
        });

        foreach ($existingStatuses as $id => $status) {
            DB::table('handover_discrepancies')->where('id', $id)->update([
                'status' => $status === 'acknowledged' ? 'written_off' : $status,
            ]);
        }
    }
};
