<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'declared' sits between 'counting' and 'pending_review' — used only by
     * handover-with-a-successor sessions (the new peer-to-peer flow).
     * main_store_stocktake and is_closing sessions never enter it and keep
     * using the existing counting -> pending_review -> reviewed path.
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->enum('status', ['counting', 'declared', 'pending_review', 'reviewed'])
                ->default('counting')
                ->change();
        });

        Schema::table('count_sessions', function (Blueprint $table) {
            // Set only on the unwitnessed path: the incoming bartender counts
            // alone because the outgoing is absent, and this person co-signs
            // in their place. outgoing_user_id is still set on these sessions
            // (so accountableUserId() still resolves to the absent bartender)
            // — this column just marks who attested to the count instead.
            $table->foreignId('witness_user_id')->nullable()->after('incoming_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('witness_user_id');
        });

        Schema::table('count_sessions', function (Blueprint $table) {
            $table->enum('status', ['counting', 'pending_review', 'reviewed'])
                ->default('counting')
                ->change();
        });
    }
};
