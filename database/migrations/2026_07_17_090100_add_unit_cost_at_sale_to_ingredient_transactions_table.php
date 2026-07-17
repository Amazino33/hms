<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same rationale as the inventory_transactions counterpart: nullable,
     * never backfilled, snapshotted on 'usage'-type rows tied to a menu-
     * item sale so the future reporting layer can compute food COGS too,
     * not just bar/product sales. Matches this table's own existing
     * cost_per_unit column precision — decimal(10,2).
     */
    public function up(): void
    {
        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->decimal('unit_cost_at_sale', 10, 2)->nullable()->after('cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('ingredient_transactions', function (Blueprint $table) {
            $table->dropColumn('unit_cost_at_sale');
        });
    }
};
