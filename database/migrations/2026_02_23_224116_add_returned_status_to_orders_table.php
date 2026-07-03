<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'preparing', 'ready', 'served', 'paid', 'cancelled', 'returned'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        // Reverting removes 'returned'
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'preparing', 'ready', 'served', 'paid', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};