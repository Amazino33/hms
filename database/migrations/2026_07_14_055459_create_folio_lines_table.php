<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('folio_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['room_charge', 'order', 'incidental', 'adjustment', 'payment']);
            // Positive = charge (increases balance owed), negative =
            // payment/credit (decreases it) — balance is a plain sum.
            // Lines are immutable: corrections are appended reversal/
            // adjustment lines, never an update to an existing row.
            $table->decimal('amount', 10, 2);
            $table->string('description');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            // Payment lines only:
            $table->string('payment_method')->nullable(); // cash / transfer / pos_terminal
            $table->boolean('verified')->default(false); // transfer payments only
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('folio_lines');
    }
};
