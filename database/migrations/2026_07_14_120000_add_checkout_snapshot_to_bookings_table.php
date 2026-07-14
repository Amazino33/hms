<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Frozen at the moment of checkout — the printed A4 receipt
            // always renders from this, never a live folio query, so it
            // can never drift even if something is appended to the folio
            // afterwards (e.g. a transfer verified/rejected days later).
            $table->json('checkout_snapshot')->nullable()->after('checked_out_by');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('checkout_snapshot');
        });
    }
};
