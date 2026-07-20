<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only meaningful for main_store_stocktake — bar_handover/kitchen_handover
 * are already single-type by session type. A storekeeper chooses up front
 * whether she's counting products or ingredients; the two are never mixed
 * into the same session, so every existing per-item screen (already built
 * for a single homogeneous type) keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->enum('item_scope', ['product', 'ingredient'])->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn('item_scope');
        });
    }
};
