<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0C: a room order's folio charge now names the order it is for,
     * one line per order, so cancelling one order (food, say) reverses
     * exactly its own charge and nothing else.
     *
     * Older room-order lines were posted one per room order (covering every
     * split order in it) with only the order numbers in the description;
     * they stay null here and untouched. RoomOrderService::cancel() handles
     * them by cancelling every order named in that line together.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('folio_id')
                ->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
