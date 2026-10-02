<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A — every sold-out / available flip made from the KDS.
     * Append-only: no updated_at, and the model refuses updates/deletes.
     */
    public function up(): void
    {
        Schema::create('menu_item_availability_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->boolean('from');
            $table->boolean('to');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['menu_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_availability_logs');
    }
};
