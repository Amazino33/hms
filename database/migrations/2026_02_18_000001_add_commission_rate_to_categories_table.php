<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Fixed commission amount (in ₦) a waiter earns per item sold in this category.
            // e.g. Wine = 100 means ₦100 per unit sold, regardless of price.
            $table->decimal('commission_rate', 10, 2)->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};
