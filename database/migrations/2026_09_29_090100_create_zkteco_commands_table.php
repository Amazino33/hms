<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work queued for the terminal.
     *
     * We can never dial the device — it sits behind the venue's router and
     * only ever polls us. So anything we want from it (here: "tell me the
     * name enrolled against PIN 7") has to wait in this queue until its next
     * GET /iclock/getrequest, which is every Delay=10 seconds.
     *
     * sent_at is stamped when the command goes out on a poll, and the
     * return_code when the device reports back on /iclock/devicecmd, so a
     * command the firmware rejected is visible rather than silently lost.
     */
    public function up(): void
    {
        Schema::create('zkteco_commands', function (Blueprint $table) {
            $table->id();
            $table->string('serial')->nullable();
            $table->string('command');
            $table->timestamp('sent_at')->nullable();
            $table->string('return_code')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_commands');
    }
};
