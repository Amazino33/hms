<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The status enum already included 'cancelled' from the very first
     * migration, but nothing in the app ever set it — no cancel action
     * existed anywhere. Adds the audit fields a real cancel action needs
     * (who, when, why).
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->text('cancelled_reason')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_reason', 'cancelled_at']);
        });
    }
};
