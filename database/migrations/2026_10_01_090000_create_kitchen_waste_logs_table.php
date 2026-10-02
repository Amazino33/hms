<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cooked kitchen food that left the bill after Mark Ready (Phase 0D).
     *
     * Kitchen stock now leaves the shelf at Mark Ready, and cooked food is
     * never restocked — so cancelling a kitchen order after that point
     * moves no stock at all. This table is the record that it happened.
     *
     * Deliberately NOT a damage_write_off: that mechanism deducts stock,
     * and these ingredients were already deducted at Mark Ready. One row
     * per order item, nothing here ever touches inventory.
     */
    public function up(): void
    {
        Schema::create('kitchen_waste_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name');
            $table->unsignedInteger('quantity');
            $table->decimal('sale_value', 10, 2);
            $table->string('order_status_before');
            $table->text('reason')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_waste_logs');
    }
};
