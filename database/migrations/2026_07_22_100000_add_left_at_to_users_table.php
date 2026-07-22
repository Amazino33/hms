<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No column anywhere marked a user as a current leaver before this —
     * every row was implicitly "active" forever. Null means active/current
     * staff; a timestamp means they left then. Nullable and additive only,
     * so every existing FK relationship (shifts, orders, debts,
     * commissions) is completely unaffected.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('next_of_kin_phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
