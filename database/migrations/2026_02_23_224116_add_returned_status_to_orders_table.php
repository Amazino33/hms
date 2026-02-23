<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'preparing', 'ready', 'served', 'paid', 'cancelled', 'returned') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Reverting removes 'returned'
        DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'preparing', 'ready', 'served', 'paid', 'cancelled') NOT NULL DEFAULT 'pending'");
    }
};