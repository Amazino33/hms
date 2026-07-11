<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds pack-entry snapshotting and per-line partial-receipt tracking to
     * both transfer-item tables. The existing `quantity` column stays the
     * authoritative sent base quantity (already base units today, no data
     * migration needed) — widened to decimal here to match
     * inventory_items/inventory_transactions rather than silently
     * truncating a converted crate quantity.
     */
    public function up(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->default(0)->change();
            $table->decimal('entered_qty', 10, 2)->nullable()->after('quantity');
            $table->enum('entered_unit', ['purchase_unit', 'base_unit'])->nullable()->after('entered_qty');
            $table->unsignedInteger('units_per_purchase_unit_snapshot')->nullable()->after('entered_unit');
            $table->decimal('received_quantity', 10, 2)->nullable()->after('units_per_purchase_unit_snapshot');
            $table->enum('outcome', ['pending', 'received_full', 'received_short', 'rejected'])->default('pending')->after('received_quantity');
            $table->foreignId('received_by')->nullable()->after('outcome')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
        });

        Schema::table('ingredient_transfer_items', function (Blueprint $table) {
            $table->decimal('entered_qty', 10, 2)->nullable()->after('quantity');
            $table->enum('entered_unit', ['purchase_unit', 'base_unit'])->nullable()->after('entered_qty');
            $table->unsignedInteger('units_per_purchase_unit_snapshot')->nullable()->after('entered_unit');
            $table->decimal('received_quantity', 10, 2)->nullable()->after('units_per_purchase_unit_snapshot');
            $table->enum('outcome', ['pending', 'received_full', 'received_short', 'rejected'])->default('pending')->after('received_quantity');
            $table->foreignId('received_by')->nullable()->after('outcome')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['entered_qty', 'entered_unit', 'units_per_purchase_unit_snapshot', 'received_quantity', 'outcome', 'received_at']);
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('ingredient_transfer_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['entered_qty', 'entered_unit', 'units_per_purchase_unit_snapshot', 'received_quantity', 'outcome', 'received_at']);
        });
    }
};
