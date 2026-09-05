<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The roster: who was required to read this. Written ONCE at publish
     * time by resolving the audience, then frozen.
     *
     * This is the whole reason the "who hasn't read it" list can be
     * trusted months later. Recomputing the expected readers live from
     * current role membership would mean a promotion, a role rename or a
     * staff member leaving silently rewrites history — the roster you
     * review in December would not match what it said in September, and
     * an unsigned notice could quietly disappear from the outstanding
     * list simply because someone changed jobs.
     */
    public function up(): void
    {
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Someone hired after publish still sees a notice that is
            // inside its window, and gets added to the roster the first
            // time it is served to them. Flagged rather than
            // back-dated, so the roster never implies they were on
            // staff when it went out.
            $table->boolean('is_late_join')->default(false);

            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
