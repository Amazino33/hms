<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('open'); // open|pending_supervisor|closed
            $table->timestamp('opened_at');
            // The moment she declares close — freezes the accrual window
            // right there, so accruedCash() doesn't keep growing while the
            // declaration sits waiting on a supervisor's own count.
            $table->timestamp('declared_at')->nullable();
            $table->decimal('declared_closing_cash', 10, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('supervisor_counted_cash', 10, 2)->nullable();
            $table->decimal('gap', 10, 2)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cashier_session_outflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_session_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('type'); // deposit|handover
            $table->string('note');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_session_outflows');
        Schema::dropIfExists('cashier_sessions');
    }
};
