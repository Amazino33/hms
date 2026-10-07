<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One badge, one person.
     *
     * The column has carried no constraint until now, so two users could hold
     * the same device ID and every punch from it would be attributed to
     * whichever row the query happened to return first. The duplicate check
     * that would block this runs in the backfill migration immediately
     * before, and fails loudly with the offending rows rather than picking a
     * winner.
     *
     * NULL is exempt from a unique index in MySQL and sqlite alike, so any
     * number of unpaired users is fine.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('biometric_id', 'users_biometric_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_biometric_id_unique');
        });
    }
};
