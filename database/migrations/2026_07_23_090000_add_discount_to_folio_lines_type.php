<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->enum('type', ['room_charge', 'order', 'incidental', 'adjustment', 'payment', 'discount'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('folio_lines', function (Blueprint $table) {
            $table->enum('type', ['room_charge', 'order', 'incidental', 'adjustment', 'payment'])->change();
        });
    }
};
