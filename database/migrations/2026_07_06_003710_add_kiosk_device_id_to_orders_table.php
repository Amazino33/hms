<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null means "placed on a personal phone," not "unknown" — only orders
     * actually placed through a registered kiosk device carry this.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('kiosk_device_id')->nullable()->after('shift_id')->constrained('kiosk_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_device_id');
        });
    }
};
