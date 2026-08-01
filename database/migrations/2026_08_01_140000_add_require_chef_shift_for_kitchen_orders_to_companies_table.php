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
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('require_chef_shift_for_kitchen_orders')->default(true)->after('enforce_kitchen_ingredient_stock');
        });

        // Same testing-phase reasoning as enforce_kitchen_ingredient_stock:
        // flip it off for the existing company record so waiters can place
        // food orders without a chef shift while that side of the operation
        // is still being set up. A fresh install keeps the safe default.
        DB::table('companies')->where('id', 1)->update(['require_chef_shift_for_kitchen_orders' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('require_chef_shift_for_kitchen_orders');
        });
    }
};
