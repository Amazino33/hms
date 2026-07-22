<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per staff member per run. base/commission/gross/deduction/net
     * are all frozen at seal time — never recomputed afterward, even if a
     * commission is later voided or a salary later changes. Lines are paid
     * individually (different staff, different moments, different
     * methods), not all at once when the run is marked paid.
     */
    public function up(): void
    {
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();

            // Snapshotted at seal — authoritative once status is past 'draft'.
            $table->decimal('base_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('deduction_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);

            $table->enum('status', ['pending', 'paid', 'acknowledged', 'disputed', 'closed_no_ack'])
                ->default('pending');

            // Payment (CEO's single write).
            $table->enum('payment_method', ['cash', 'transfer'])->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            // Acknowledgement (staff).
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->decimal('dispute_reported_amount', 12, 2)->nullable();

            // Fallback close (CEO/supervisor).
            $table->text('closed_reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['payroll_run_id', 'status']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
    }
};
