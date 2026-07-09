<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes a closing count (outgoing custodian's shift just ends —
     * nobody is taking over, e.g. end of the business day) from a normal
     * handover (outgoing custodian's shift ends AND incoming_user_id's
     * shift starts). Both still require a second person named in
     * incoming_user_id and both confirmation timestamps before the count
     * can be submitted for review — for a closing count that second person
     * is a witness, not a successor.
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->boolean('is_closing')->default(false)->after('incoming_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn('is_closing');
        });
    }
};
