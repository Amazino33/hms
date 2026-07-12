<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            // Frozen at open time from companies.handover_count_scope — a
            // later admin setting change must never alter a session already
            // in progress, only the next one opened. 'all' for every
            // pre-existing row (the historic, pre-toggle behavior).
            $table->string('count_scope')->default('all')->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('count_sessions', function (Blueprint $table) {
            $table->dropColumn('count_scope');
        });
    }
};
