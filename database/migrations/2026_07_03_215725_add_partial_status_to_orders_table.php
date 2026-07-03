<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The POS checkout has always tried to set this value on underpaid
        // orders (see OrderSplitter), but it was never actually added to the
        // enum, causing every guest-debt checkout to throw a data-truncation
        // error. Adding it here makes that existing code path actually work.
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'preparing', 'ready', 'served', 'partial', 'paid', 'cancelled', 'returned',
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'preparing', 'ready', 'served', 'paid', 'cancelled', 'returned',
            ])->default('pending')->change();
        });
    }
};
