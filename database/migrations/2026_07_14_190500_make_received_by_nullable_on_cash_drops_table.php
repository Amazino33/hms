<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash drops now route to a shared cashier queue instead of a waiter-
 * chosen named recipient — received_by is set at CONFIRM time (whoever
 * actually picks it up), not at declare time, so it must allow null while
 * pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_drops', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_drops', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable(false)->change();
        });
    }
};
