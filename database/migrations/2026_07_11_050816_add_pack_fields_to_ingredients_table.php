<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ingredients already have unit_name (kg, litre) serving as the base
     * unit, and cost_per_unit already serves as "last cost" — only the
     * purchase-unit/pack-size fields and the staff-created-product flag
     * are genuinely new here, mirroring the same fields added to products.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->string('purchase_unit_name')->nullable()->after('unit_name');
            $table->unsignedInteger('units_per_purchase_unit')->nullable()->after('purchase_unit_name');
            $table->boolean('created_by_staff')->default(false)->after('category');
            $table->foreignId('created_by')->nullable()->after('created_by_staff')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['purchase_unit_name', 'units_per_purchase_unit', 'created_by_staff']);
        });
    }
};
