<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1B — every time a table's or room's QR code was replaced, by
     * whom and why. Append-only, and deliberately never stores the old
     * token: keeping it would let a leaked code be looked up again.
     */
    public function up(): void
    {
        Schema::create('qr_token_regenerations', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('regenerated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_token_regenerations');
    }
};
