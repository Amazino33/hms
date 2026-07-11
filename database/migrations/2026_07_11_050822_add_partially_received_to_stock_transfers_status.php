<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'partially_received' sits between 'sent' and 'received' — set once
     * some but not all lines have a non-pending outcome. 'received' stays
     * the terminal "every line resolved" state, unchanged in meaning, so
     * every existing query/test checking for status === 'received' keeps
     * working exactly as before.
     */
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'partially_received', 'received', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->enum('status', ['pending', 'sent', 'received', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};
