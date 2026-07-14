<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->enum('type', ['waiter', 'bartender', 'chef', 'receptionist'])->default('waiter')->change();
            // A receptionist's starting till cash — no other shift type has
            // this concept (waiters start at zero and remit everything
            // they collect). Only "expected cash at close" for a
            // receptionist includes this baseline.
            $table->decimal('starting_float', 10, 2)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('starting_float');
            $table->enum('type', ['waiter', 'bartender', 'chef'])->default('waiter')->change();
        });
    }
};
