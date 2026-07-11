<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set only once the real incoming custodian PIN-authenticates at review
     * start (CountSessionService::bindIncomingCustodian()) — distinguishes a
     * PIN-verified incoming_user_id from the unverified dropdown guess the
     * outgoing custodian makes at session-open time. Review UI/seal
     * reachability gate on this being set, not on incoming_user_id alone.
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->timestamp('incoming_bound_at')->nullable()->after('incoming_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn('incoming_bound_at');
        });
    }
};
