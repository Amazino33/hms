<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('page_class'); // e.g., 'App\Filament\Pages\PosPage'
            $table->string('page_name'); // e.g., 'POS Page'
            $table->string('role_name'); // e.g., 'waiter', 'chef'
            $table->timestamps();

            $table->unique(['page_class', 'role_name']); // Prevent duplicates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_permissions');
    }
};