<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_return')) {
                $table->boolean('is_return')->default(false)->after('status');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'return_reason')) {
                $table->string('return_reason')->nullable()->after('subtotal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_return');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('return_reason');
        });
    }
};