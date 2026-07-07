<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * token_hash is a fast SHA-256 of the actual device token — safe here
     * (unlike PINs) because the token is high-entropy and never brute-
     * forceable, so a slow hash buys nothing. Revocation is just setting
     * revoked_at/revoked_by; every request re-checks it, so revocation is
     * effective immediately, not on some cache/expiry cycle.
     */
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash')->unique();
            $table->foreignId('registered_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
