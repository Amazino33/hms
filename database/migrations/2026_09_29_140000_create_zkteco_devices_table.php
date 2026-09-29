<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the terminal last made contact, and on which endpoint.
     *
     * This lived only in Log::info() until now, which on this server means it
     * did not live anywhere: production runs LOG_LEVEL=warning, so every
     * device-contact line was discarded before it reached the file. The
     * result was a system that could not answer "is the clock even talking to
     * us?" — the first question worth asking whenever attendance looks wrong.
     *
     * Four separate stamps because they fail independently: a device can
     * handshake and never poll, or push punches and ignore commands, and
     * which one stopped is the whole diagnosis.
     */
    public function up(): void
    {
        Schema::create('zkteco_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial')->unique();
            $table->timestamp('last_handshake_at')->nullable();
            $table->timestamp('last_poll_at')->nullable();
            $table->timestamp('last_push_at')->nullable();
            $table->timestamp('last_punch_at')->nullable();
            $table->unsignedBigInteger('punches_received')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_devices');
    }
};
