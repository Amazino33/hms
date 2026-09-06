<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records whether an order's stock was ever actually taken off the
     * shelf, so a cancellation can only give back what genuinely left.
     *
     * Most orders deduct at creation (OrderSplitter::handle()), but a room
     * order defers until the kitchen/bar display marks it Ready
     * (RoomOrderService passes defer_stock_deduction). OrderObserver
     * returned stock on any transition into cancelled/returned with no
     * record of whether the deduction had happened — so cancelling a
     * room order that was never marked Ready credited stock that never
     * left, inflating inventory. That surfaces later as a *shortage* at
     * the next handover count, because the physical shelf never had it.
     *
     * Backfill is derived from the transaction ledger rather than guessed:
     * an order was deducted if and only if it wrote a 'sale' (product) or
     * 'usage' (ingredient) transaction referencing it. Only null vs
     * not-null is load-bearing; the timestamp itself is approximated from
     * the order's created_at, since the exact historic moment isn't
     * recoverable for room orders and nothing reads it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_deducted_at')->nullable()->after('status');
        });

        $orderIds = collect()
            ->merge(DB::table('inventory_transactions')
                ->where('type', 'sale')
                ->where('reference', 'like', 'order:%')
                ->pluck('reference'))
            ->merge(DB::table('ingredient_transactions')
                ->where('type', 'usage')
                ->where('reference', 'like', 'order:%')
                ->pluck('reference'))
            ->map(fn ($reference) => (int) str_replace('order:', '', (string) $reference))
            ->filter()
            ->unique()
            ->values();

        $orderIds->chunk(1000)->each(function ($chunk) {
            DB::table('orders')
                ->whereIn('id', $chunk->all())
                ->update(['stock_deducted_at' => DB::raw('created_at')]);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_deducted_at');
        });
    }
};
