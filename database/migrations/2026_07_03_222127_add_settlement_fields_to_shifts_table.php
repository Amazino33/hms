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
        Schema::table('shifts', function (Blueprint $table) {
            // Server-computed figures, frozen at settlement time for audit
            // purposes (never trust client-supplied totals for these).
            $table->decimal('expected_cash', 10, 2)->nullable()->after('supervisor_confirmed_pos');
            $table->decimal('expected_pos', 10, 2)->nullable()->after('expected_cash');
            $table->decimal('cash_variance', 10, 2)->nullable()->after('expected_pos');
            $table->decimal('surplus_amount', 10, 2)->default(0)->after('cash_variance');
            $table->text('settlement_notes')->nullable()->after('surplus_amount');
            $table->timestamp('settled_at')->nullable()->after('settlement_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'expected_cash', 'expected_pos', 'cash_variance', 'surplus_amount', 'settlement_notes', 'settled_at',
            ]);
        });
    }
};
