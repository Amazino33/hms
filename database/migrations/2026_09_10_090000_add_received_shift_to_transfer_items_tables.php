<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock landed in a warehouse, never in a shift: a receipt recorded who
 * clicked (received_by) and when (received_at), but nothing tied it to
 * the custodian shift it belongs to. That is the whole reason an
 * off-shift bartender receiving a delivery quietly moved the numbers of
 * whoever was actually on duty — the count/handover reads the warehouse,
 * and the receipt had no shift to disagree with.
 *
 * Nullable on purpose, and stays nullable: storekeeper/super_admin (and
 * any role a manager grants receiving to via Page Permissions) receive
 * without holding a custodian shift at all, and every row that predates
 * this column has no shift to backfill from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->foreignId('received_shift_id')->nullable()->after('received_by')->constrained('shifts')->nullOnDelete();
        });

        Schema::table('ingredient_transfer_items', function (Blueprint $table) {
            $table->foreignId('received_shift_id')->nullable()->after('received_by')->constrained('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_shift_id');
        });

        Schema::table('ingredient_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_shift_id');
        });
    }
};
