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
            $table->boolean('enforce_kitchen_ingredient_stock')->default(true)->after('handover_count_scope');
        });

        // Ingredient stock isn't fully set up in this deployment yet — flip
        // it off for the existing company record so kitchen orders aren't
        // blocked while that's worked on in the background. A fresh install
        // still gets the safe default (true) above.
        DB::table('companies')->where('id', 1)->update(['enforce_kitchen_ingredient_stock' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('enforce_kitchen_ingredient_stock');
        });
    }
};
