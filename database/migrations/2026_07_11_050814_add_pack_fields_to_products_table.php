<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crates/packs never enter the ledger — every stock level, transaction,
     * count, and transfer line is always in base units (bottle, can, kg,
     * litre...). purchase_unit_name + units_per_purchase_unit exist purely
     * at the input layer so the storekeeper can enter "2 crates" and have
     * it converted to base units before anything is written.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('base_unit')->default('bottle')->after('name');
            $table->string('purchase_unit_name')->nullable()->after('base_unit');
            $table->unsignedInteger('units_per_purchase_unit')->nullable()->after('purchase_unit_name');
            $table->decimal('last_cost_price', 12, 2)->nullable()->after('cost_price');
            $table->boolean('created_by_staff')->default(false)->after('is_active');
            $table->foreignId('created_by')->nullable()->after('created_by_staff')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['base_unit', 'purchase_unit_name', 'units_per_purchase_unit', 'last_cost_price', 'created_by_staff']);
        });
    }
};
