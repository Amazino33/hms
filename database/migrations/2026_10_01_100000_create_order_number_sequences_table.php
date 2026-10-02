<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One counter per (Lagos calendar date, station) — the source of every
     * order number since Phase 0A (App\Services\Orders\OrderNumberGenerator).
     * Numbers used to be built from the current second, so two orders for
     * the same station in one second collided on orders.order_number's
     * unique index.
     *
     * station holds the suffix the number ends in ("B", "K", "M"), or a
     * prefixed key for a series with its own prefix ("RET-K" for return
     * tickets), so each series counts independently.
     *
     * Existing orders keep their old-format numbers; nothing is backfilled.
     */
    public function up(): void
    {
        Schema::create('order_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('station', 20);
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();

            $table->unique(['date', 'station']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_number_sequences');
    }
};
