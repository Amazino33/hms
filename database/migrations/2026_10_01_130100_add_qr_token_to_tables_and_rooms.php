<?php

use App\Services\Guest\QrTokens;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B — the random token printed (as a QR code) on every table and
     * room: /m/{token}. It names a place, never grants anything, and is
     * never derived from the id or number — guessing one table's code must
     * tell you nothing about another's.
     *
     * Every existing table and room gets one here; new ones get one from
     * the HasQrToken model trait when they're created.
     */
    public function up(): void
    {
        foreach (['tables', 'rooms'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('qr_token', 12)->nullable()->unique();
            });
        }

        foreach (['tables', 'rooms'] as $tableName) {
            DB::table($tableName)->whereNull('qr_token')->orderBy('id')->pluck('id')->each(
                fn ($id) => DB::table($tableName)->where('id', $id)->update(['qr_token' => QrTokens::generate()])
            );
        }
    }

    public function down(): void
    {
        foreach (['tables', 'rooms'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique(['qr_token']);
                $table->dropColumn('qr_token');
            });
        }
    }
};
