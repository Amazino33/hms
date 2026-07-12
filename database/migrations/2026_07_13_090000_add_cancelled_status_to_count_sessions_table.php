<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a mistaken session (e.g. someone accidentally picking themselves
     * as both outgoing and incoming custodian) be cleared out before it's
     * declared/sealed, instead of permanently blocking that person's
     * myOpenSession() lookup with no way out. Only reachable while
     * 'counting' or 'declared' — once a session reaches pending_review or
     * reviewed, stock has already moved and cancelling isn't safe.
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->enum('status', ['counting', 'declared', 'pending_review', 'reviewed', 'cancelled'])
                ->default('counting')
                ->change();
        });

        Schema::table('count_sessions', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('reviewed_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancelled_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancelled_reason']);
        });

        Schema::table('count_sessions', function (Blueprint $table) {
            $table->enum('status', ['counting', 'declared', 'pending_review', 'reviewed'])
                ->default('counting')
                ->change();
        });
    }
};
