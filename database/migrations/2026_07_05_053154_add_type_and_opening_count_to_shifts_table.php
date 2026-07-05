<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'type' defaults to 'waiter' so every existing shift row keeps its
     * current meaning unchanged. Bartender/chef shifts are new territory —
     * each one is tied back to the count session that opened it (either a
     * solo opening count or the handover count that closed it out for the
     * outgoing custodian).
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->enum('type', ['waiter', 'bartender', 'chef'])->default('waiter')->after('user_id');
            $table->foreignId('opening_count_session_id')->nullable()->after('type')
                ->constrained('count_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign(['opening_count_session_id']);
            $table->dropColumn(['type', 'opening_count_session_id']);
        });
    }
};
