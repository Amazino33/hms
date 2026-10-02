<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B — the venue's bank accounts, listed (with copy buttons) on a
     * guest's bill so they can pay by transfer. Switched off rather than
     * deleted, so anything that later refers to one keeps working.
     */
    public function up(): void
    {
        Schema::create('transfer_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name', 80);
            $table->string('account_name', 120);
            $table->string('account_number', 10);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_accounts');
    }
};
