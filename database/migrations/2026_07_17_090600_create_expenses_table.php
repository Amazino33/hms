<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only with void, never edited: amount/category/date_incurred
     * are immutable once created — a correction voids the row (kept
     * forever, flagged, excluded from totals) and a fresh row is entered.
     * Only `note` may be edited after creation.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('date_incurred');
            $table->text('note')->nullable();
            $table->string('receipt_photo')->nullable();
            $table->foreignId('entered_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['expense_category_id', 'date_incurred']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
