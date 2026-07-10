<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per CountSessionItem once the incoming bartender/chef has
     * looked at it during the review phase. 'accepted' means they agree
     * with the outgoing's declared figure; 'disputed' means incoming_
     * quantities holds their own count and the two need to recount
     * together; 'unresolved' means they still disagreed after recounting,
     * so incoming_quantities becomes the baseline and a manager is
     * notified (never blocking). There's deliberately no "resolved
     * figures" column — resolving a dispute means the outgoing amends
     * their own count_session_sub_counts rows (PIN-signed), which
     * LogsActivity on that model already captures as before/after.
     */
    public function up(): void
    {
        Schema::create('count_session_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_session_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->constrained('users');
            $table->enum('outcome', ['accepted', 'disputed', 'unresolved']);
            $table->json('incoming_quantities')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('count_session_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_session_item_reviews');
    }
};
