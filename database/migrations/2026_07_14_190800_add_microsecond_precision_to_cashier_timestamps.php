<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CashierSessionService::accruedCash() windows by comparing timestamps
 * against opened_at/declared_at — with the default second-level
 * precision, two actions moments apart (e.g. a settlement confirmed, then
 * declareClose() called right after) can round to the exact same stored
 * second, making them indistinguishable regardless of which comparison
 * operator is used. Microsecond precision on the columns actually
 * compared removes the ambiguity at the source instead of trying to work
 * around it with query logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('cash_confirmed_at', 6)->nullable()->change();
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->timestamp('opened_at', 6)->change();
            $table->timestamp('declared_at', 6)->nullable()->change();
        });

        Schema::table('cash_drops', function (Blueprint $table) {
            $table->timestamp('confirmed_at', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('cash_confirmed_at')->nullable()->change();
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->timestamp('opened_at')->change();
            $table->timestamp('declared_at')->nullable()->change();
        });

        Schema::table('cash_drops', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->change();
        });
    }
};
