<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('picked_up_by')->nullable()->after('booking_id')->constrained('users')->nullOnDelete();
            $table->timestamp('picked_up_at')->nullable()->after('picked_up_by');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('picked_up_by');
            $table->dropColumn('picked_up_at');
        });
    }
};
