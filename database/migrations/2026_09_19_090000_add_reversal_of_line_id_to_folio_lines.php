<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Folio lines stay immutable — a receptionist "editing" a payment or a
     * discount before checkout posts a reversal line instead of changing
     * the original. This column is what links the two, so the ledger can
     * strike a voided line through and a line can never be voided twice.
     */
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->foreignId('reversal_of_line_id')->nullable()->after('reference')
                ->constrained('folio_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_of_line_id');
        });
    }
};
