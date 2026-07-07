<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * declared_amount is what the waiter says they're handing over;
     * confirmed_amount is what the named receiver actually says they
     * counted — kept separate rather than overwriting, so a mismatch stays
     * visible on the record rather than silently disappearing. Only
     * confirmed drops ever reduce a waiter's expected cash remittance.
     */
    public function up(): void
    {
        Schema::create('cash_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('waiter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->decimal('declared_amount', 10, 2);
            $table->decimal('confirmed_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'confirmed'])->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drops');
    }
};
