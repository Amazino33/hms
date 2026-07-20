<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The create/edit form no longer sends `type` at all (room_type_id replaced
 * it) — a NOT NULL constraint on a column nothing writes anymore would
 * break every new room. Existing rows keep whatever value they already
 * have; this only stops future inserts from being rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('type')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('type')->nullable(false)->change();
        });
    }
};
