<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * code_hash is bcrypt, same reasoning as PINs — these codes are
     * short, human-typed, and short-lived, so they deserve a slow hash
     * even though they're single-use.
     */
    public function up(): void
    {
        Schema::create('kiosk_registration_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('kiosk_device_id')->nullable()->constrained('kiosk_devices')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_registration_codes');
    }
};
