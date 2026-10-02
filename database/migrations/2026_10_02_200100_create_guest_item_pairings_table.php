<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7C (D38): "Goes well with" — up to 3 owner-picked pairings per
     * menu item or product, in order. Items are polymorphic by type
     * (menu_item | product), like guest_request_items.
     */
    public function up(): void
    {
        Schema::create('guest_item_pairings', function (Blueprint $table) {
            $table->id();
            $table->string('item_type', 12);
            $table->unsignedBigInteger('item_id');
            $table->string('pair_type', 12);
            $table->unsignedBigInteger('pair_id');
            $table->unsignedTinyInteger('sort')->default(0); // 0–2
            $table->timestamps();

            $table->unique(['item_type', 'item_id', 'pair_type', 'pair_id'], 'guest_item_pairings_unique');
            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_item_pairings');
    }
};
