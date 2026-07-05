<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_id')->constrained()->cascadeOnDelete();
            $table->enum('item_type', ['product', 'ingredient']);
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->cascadeOnDelete();

            // Snapshotted the moment the session opens; never queried or
            // displayed while the session is still in 'counting' status.
            $table->decimal('expected_quantity_at_open', 10, 2);

            // Sum of this item's per-sub-location counts (see
            // count_session_sub_counts). Null until at least one count is entered.
            $table->decimal('counted_quantity', 10, 2)->nullable();

            // expected_quantity_at_open adjusted for sales/usage/transfers
            // that happened between session open and submission for review.
            $table->decimal('adjusted_expected_quantity', 10, 2)->nullable();
            $table->decimal('variance', 10, 2)->nullable(); // counted_quantity - adjusted_expected_quantity

            $table->enum('decision', ['true_up', 'accountability', 'ignored'])->nullable();
            $table->text('decision_notes')->nullable();

            $table->timestamps();

            $table->unique(['count_session_id', 'item_type', 'product_id', 'ingredient_id'], 'count_session_items_unique');
        });

        Schema::create('count_session_sub_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_item_id')->constrained()->cascadeOnDelete();
            $table->string('sub_location');
            $table->decimal('quantity', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_session_sub_counts');
        Schema::dropIfExists('count_session_items');
    }
};
