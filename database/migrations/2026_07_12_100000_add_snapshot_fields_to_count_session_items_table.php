<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frozen at seal time inside sealAgreement() — never recomputed from a
     * live Product/Ingredient price afterward, so a later price change can
     * never alter a historic handover's naira figures or PDF.
     */
    public function up(): void
    {
        Schema::table('count_session_items', function (Blueprint $table) {
            $table->decimal('unit_selling_price', 12, 2)->nullable()->after('variance');
            $table->decimal('variance_value', 12, 2)->nullable()->after('unit_selling_price');
        });
    }

    public function down(): void
    {
        Schema::table('count_session_items', function (Blueprint $table) {
            $table->dropColumn(['unit_selling_price', 'variance_value']);
        });
    }
};
