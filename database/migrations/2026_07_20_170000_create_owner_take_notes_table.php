<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight flag, not a formal approval workflow: a waiter/receptionist
 * notes "oga took this" against their own shift before ending it. The
 * cashier sees it during settlement and uses their own judgment (existing
 * manual Staff Debt entry) to decide what to actually record — this table
 * is just the note that follows the shift from waiter to cashier, plus
 * read-only visibility for the CEO. No status/approve-reject machinery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_take_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->nullable();
            $table->text('description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_take_notes');
    }
};
