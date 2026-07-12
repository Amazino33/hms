<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized session-level totals, computed once at seal and never
     * touched again — avoids re-summing every item on every history-list
     * render. total_overage_quantity is quantity only (overage carries no
     * naira credit per spec).
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->decimal('total_shortage_value', 12, 2)->nullable()->after('reviewed_at');
            $table->decimal('total_overage_quantity', 10, 2)->nullable()->after('total_shortage_value');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn(['total_shortage_value', 'total_overage_quantity']);
        });
    }
};
