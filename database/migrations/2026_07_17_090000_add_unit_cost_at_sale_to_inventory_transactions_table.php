<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nullable, never backfilled: historical rows stay null and the future
     * reporting layer falls back to current cost with an "estimated"
     * marker for them. Matches the precision of this table's own existing
     * cost_per_unit column (set on purchase-type rows) — decimal(10,2).
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->decimal('unit_cost_at_sale', 10, 2)->nullable()->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn('unit_cost_at_sale');
        });
    }
};
