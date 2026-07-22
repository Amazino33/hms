<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per pay period. draft (still compiling, figures recomputable)
     * -> sealed (money columns on every line frozen, sent to the CEO,
     * no more edits) -> closed (every line acknowledged or closed-with-
     * reason). voided is terminal — a correction never edits a sealed run,
     * it voids it and creates a new draft run with supersedes_id pointing
     * back at it, mirroring DailyBusinessSnapshot exactly.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payday')->nullable();
            $table->enum('status', ['draft', 'sealed', 'closed', 'voided'])->default('draft');
            $table->foreignId('prepared_by')->constrained('users');
            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
