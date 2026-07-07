<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pin_hash (bcrypt) is for verification only and can never be reversed
     * to enumerate PINs. pin_lookup_hash is a separate, deterministic HMAC
     * of the same PIN, kept solely so the kiosk number pad can resolve
     * "who just typed this" and so uniqueness can be enforced with a real
     * unique index — it is never used as a security check on its own.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
            $table->string('pin_lookup_hash')->nullable()->unique()->after('pin_hash');
            $table->timestamp('pin_set_at')->nullable()->after('pin_lookup_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pin_lookup_hash']);
            $table->dropColumn(['pin_hash', 'pin_lookup_hash', 'pin_set_at']);
        });
    }
};
