<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            // Only add category_id if it doesn't exist
            if (!Schema::hasColumn('menu_items', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('sku')->constrained()->nullOnDelete();
            }

            // Drop commission_amount if it exists
            if (Schema::hasColumn('menu_items', 'commission_amount')) {
                $table->dropColumn('commission_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->decimal('commission_amount', 10, 2)->nullable();
            // We don't drop category_id in down() because it might have existed before this migration
        });
    }
};