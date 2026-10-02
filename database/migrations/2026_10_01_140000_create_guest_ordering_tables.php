<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 2 — guest requests. A request is NOT an order: nothing here
     * touches orders, stock or folios. Staff confirmation (Phase 3) is what
     * turns request lines into real orders through OrderSplitter.
     *
     * Money is decimal(10,2) naira, like order_items. Statuses are strings
     * with the allowed values held in the models (an enum column is painful
     * to extend on MySQL, and Phases 3–5 add behaviour to these states).
     */
    public function up(): void
    {
        // One sitting at a table. Opens on the FIRST submitted request (D14),
        // never on a scan.
        Schema::create('guest_table_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();
            // Restrict, not cascade: MySQL refuses CASCADE / SET NULL on a
            // column a stored generated column (open_table_id below) is built
            // from. A table with guest history is switched off, not deleted.
            $table->foreignId('table_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 20)->nullable();
            $table->foreignId('assigned_waiter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamp('last_activity_at');
            $table->timestamps();

            // Belt and braces for "at most one open session per table": the
            // service already serialises on a row lock; this makes a second
            // open session impossible at the database level too. Non-null
            // only while open, and NULLs never collide in a unique index.
            $table->unsignedBigInteger('open_table_id')->nullable()
                ->storedAs('case when closed_at is null then table_id end');
            $table->unique('open_table_id');
            $table->index(['closed_at', 'last_activity_at']);
        });

        Schema::create('guest_requests', function (Blueprint $table) {
            $table->id();
            // e.g. T5-0423 — unique within its business day (the sequence
            // restarts each day, so the same ref can recur on another day).
            $table->string('ref', 20);
            $table->date('business_date');
            $table->string('source', 10); // table | room
            $table->foreignId('table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_table_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_id', 32);
            $table->string('status', 24)->default('pending');
            $table->decimal('total_snapshot', 10, 2);
            $table->timestamp('submitted_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_date', 'ref']);
            $table->index(['status', 'submitted_at']);
            $table->index(['device_id', 'status']);
            $table->index(['table_id', 'status']);
        });

        Schema::create('guest_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_request_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 12); // menu_item | product
            $table->unsignedBigInteger('item_id');
            $table->string('station', 10); // bar | kitchen
            $table->string('name_snapshot');
            $table->decimal('unit_price_snapshot', 10, 2);
            $table->unsignedSmallInteger('quantity_requested');
            $table->unsignedSmallInteger('quantity_final')->nullable();
            $table->json('chips')->nullable();
            $table->string('note', 100)->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('removed_reason')->nullable();
            $table->timestamps();

            $table->index(['item_type', 'item_id']);
        });

        // One counter per BusinessDay (9am Lagos rollover) — mirrors
        // order_number_sequences.
        Schema::create('guest_request_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_request_sequences');
        Schema::dropIfExists('guest_request_items');
        Schema::dropIfExists('guest_requests');
        Schema::dropIfExists('guest_table_sessions');
    }
};
