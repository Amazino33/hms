<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-item movement breakdown frozen at the moment a count locks
 * (CountBreakdownSnapshotService). Every table here is append-only — the
 * models refuse update/delete — so a pop-up opened a year from now still
 * adds up to the figure that was sealed, whatever happens to the live
 * orders/transfers/prices afterward. Only created_at, never updated_at:
 * nothing here is ever updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('previous_count_session_id')->nullable();
            $table->timestamp('window_from')->nullable();
            $table->timestamp('window_to');
            $table->string('counted_by_name')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->string('first_signer_label')->nullable();
            $table->string('first_signer_name')->nullable();
            $table->string('second_signer_label')->nullable();
            $table->string('second_signer_name')->nullable();
            // Set only on a breakdown rebuilt afterwards from the records
            // (hms:backfill-count-breakdowns) for a count sealed before
            // breakdowns were captured. Null means frozen at the seal itself.
            $table->timestamp('reconstructed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('count_breakdown_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_breakdown_id')->constrained()->cascadeOnDelete();
            $table->foreignId('count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('count_session_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->enum('section', ['product', 'ingredient']);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name');
            $table->string('unit')->nullable();
            $table->string('pack_unit_name')->nullable();
            $table->unsignedInteger('units_per_pack')->nullable();

            $table->decimal('brought_forward', 12, 2)->default(0);
            $table->decimal('transferred_in', 12, 2)->default(0);
            $table->decimal('returns_in', 12, 2)->default(0);
            $table->decimal('other_in', 12, 2)->default(0);
            $table->decimal('available', 12, 2)->default(0);
            $table->decimal('sold_qty', 12, 2)->default(0);
            $table->decimal('sales_amount', 14, 2)->nullable();
            $table->decimal('damages_writeoffs', 12, 2)->default(0);
            $table->decimal('other_out', 12, 2)->default(0);
            $table->decimal('unrecorded_change', 12, 2)->default(0);
            $table->decimal('expected_remaining', 12, 2)->default(0);
            $table->decimal('counted', 12, 2)->default(0);
            $table->decimal('variance_qty', 12, 2)->default(0);
            $table->decimal('unit_selling_price', 12, 2)->default(0);
            $table->decimal('unit_cost_price', 12, 2)->nullable();
            $table->decimal('variance_value_selling', 14, 2)->default(0);
            $table->decimal('variance_value_cost', 14, 2)->nullable();
            $table->boolean('has_movement')->default(false);
            $table->string('brought_forward_note')->nullable();

            $table->foreignId('supersedes_id')->nullable()->constrained('count_breakdown_lines')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['warehouse_id', 'section', 'item_id']);
        });

        Schema::create('count_breakdown_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_breakdown_line_id')->constrained()->cascadeOnDelete();
            $table->string('figure');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('status')->default('active');
            $table->string('label')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('waiter_name')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('recorder_name')->nullable();
            $table->string('approver_name')->nullable();
            $table->string('voided_by_name')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['count_breakdown_line_id', 'figure']);
        });

        Schema::create('count_open_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('order_number')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 2);
            $table->string('waiter_name')->nullable();
            $table->string('order_status')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('count_variance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_breakdown_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_variance_notes');
        Schema::dropIfExists('count_open_orders');
        Schema::dropIfExists('count_breakdown_movements');
        Schema::dropIfExists('count_breakdown_lines');
        Schema::dropIfExists('count_breakdowns');
    }
};
