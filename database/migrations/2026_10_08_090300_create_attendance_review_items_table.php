<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things that need a person's eye but carry no charge.
     *
     * A punch nobody was scheduled for usually means the rota is wrong — a
     * rotation anchored a day out, or a swap nobody recorded. Fining it would
     * be punishing somebody for turning up; ignoring it leaves the rota wrong
     * and everyone on it mis-judged. So it goes in a queue instead.
     */
    public function up(): void
    {
        Schema::create('attendance_review_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attendance_log_id')->nullable()
                ->constrained('attendance_logs', indexName: 'ari_log_id_foreign')
                ->nullOnDelete();
            $table->foreignId('shift_record_id')->nullable()
                ->constrained('attendance_shift_records', indexName: 'ari_shift_record_id_foreign')
                ->nullOnDelete();

            $table->date('shift_date')->nullable();

            // e.g. unscheduled_punch, suspected_outage.
            $table->string('reason');
            $table->text('detail')->nullable();

            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['resolved_at', 'reason'], 'ari_open_reason_index');
            // Stops a repeated finalise run stacking the same item over and
            // over for one punch.
            $table->unique(['reason', 'attendance_log_id'], 'ari_reason_log_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_review_items');
    }
};
