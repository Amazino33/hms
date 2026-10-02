<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7C (D38): how each guest line was added — menu | search |
     * recommended | pairing | addon | round | again. A label for the
     * owner's reports only; it never changes price, routing or stock.
     */
    public function up(): void
    {
        Schema::table('guest_request_items', function (Blueprint $table) {
            $table->string('added_via', 12)->default('menu')->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('guest_request_items', function (Blueprint $table) {
            $table->dropColumn('added_via');
        });
    }
};
