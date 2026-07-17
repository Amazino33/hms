<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_business_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('business_date');

            $table->decimal('revenue_earned_total', 12, 2)->default(0);
            $table->decimal('revenue_bar', 12, 2)->default(0);
            $table->decimal('revenue_restaurant', 12, 2)->default(0);
            $table->decimal('revenue_rooms', 12, 2)->default(0);

            $table->decimal('cogs_total', 12, 2)->default(0);
            $table->unsignedInteger('cogs_estimated_count')->default(0);

            $table->decimal('gross_profit', 12, 2)->default(0);
            $table->decimal('damages_cost_total', 12, 2)->default(0);

            $table->decimal('cash_collected_total', 12, 2)->default(0);
            $table->decimal('cash_collected_cash', 12, 2)->default(0);
            $table->decimal('cash_collected_pos', 12, 2)->default(0);
            $table->decimal('cash_collected_transfers_verified', 12, 2)->default(0);
            $table->decimal('cash_collected_transfers_unverified', 12, 2)->default(0);

            $table->decimal('gap_total', 12, 2)->default(0);
            $table->decimal('gap_unverified_transfers', 12, 2)->default(0);
            $table->decimal('gap_open_folio_balance', 12, 2)->default(0);
            $table->decimal('gap_unsettled_shift_amount', 12, 2)->default(0);
            $table->decimal('gap_staff_debt_outstanding', 12, 2)->default(0);

            $table->decimal('staff_debt_new', 12, 2)->default(0);
            $table->decimal('staff_debt_repaid', 12, 2)->default(0);

            $table->decimal('expenses_total', 12, 2)->default(0);

            $table->unsignedInteger('rooms_occupied')->default(0);
            $table->decimal('occupancy_rate', 5, 2)->default(0);
            $table->decimal('adr', 10, 2)->default(0);

            $table->foreignId('supersedes_id')->nullable()->constrained('daily_business_snapshots')->nullOnDelete();
            $table->timestamp('computed_at');
            $table->timestamps();

            // Not a plain unique index on business_date: superseding rows
            // deliberately share a business_date with the row they replace
            // (append-only history). Readers always take the latest row
            // per date — see DailyBusinessSnapshot::latestFor().
            $table->index(['business_date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_business_snapshots');
    }
};
