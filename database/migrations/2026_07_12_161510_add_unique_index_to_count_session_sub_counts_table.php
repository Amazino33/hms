<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Defensive collapse before the unique index is added: no known
        // code path creates a second row for the same (item, sub_location)
        // pair, but the schema never actually enforced that until now — if
        // any duplicate slipped in (a bad migration run, manual DB surgery,
        // etc.), keep the most-recently-updated row and drop the rest so
        // this migration never fails on real data.
        $duplicateGroups = DB::table('count_session_sub_counts')
            ->select('count_session_item_id', 'sub_location')
            ->groupBy('count_session_item_id', 'sub_location')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $rows = DB::table('count_session_sub_counts')
                ->where('count_session_item_id', $group->count_session_item_id)
                ->where('sub_location', $group->sub_location)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            $rows->skip(1)->each(fn ($row) => DB::table('count_session_sub_counts')->where('id', $row->id)->delete());
        }

        Schema::table('count_session_sub_counts', function (Blueprint $table) {
            $table->unique(['count_session_item_id', 'sub_location'], 'count_session_sub_counts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('count_session_sub_counts', function (Blueprint $table) {
            $table->dropUnique('count_session_sub_counts_unique');
        });
    }
};
