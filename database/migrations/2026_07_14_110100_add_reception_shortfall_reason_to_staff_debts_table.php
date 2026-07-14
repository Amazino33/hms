<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_debts', function (Blueprint $table) {
            $table->enum('reason', ['shift_shortfall', 'unpaid_order_conversion', 'manual', 'count_session_shortfall', 'reception_shortfall'])
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('staff_debts', function (Blueprint $table) {
            $table->enum('reason', ['shift_shortfall', 'unpaid_order_conversion', 'manual', 'count_session_shortfall'])
                ->change();
        });
    }
};
