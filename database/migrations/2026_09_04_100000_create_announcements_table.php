<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff notice board with signatures. Deliberately NOT built on
     * Filament's database notifications (already enabled in
     * AdminPanelProvider): those are per-user copies with no forced
     * acknowledgement and no roster of who confirmed what. Here there is
     * ONE message and many signatures against it, which is the whole point
     * — a manager needs to answer "who has not read this yet".
     */
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');

            // The author's call, per announcement: true renders a blocking
            // modal that must be signed before the app can be used; false
            // renders a dismissible sticky note that returns on next login
            // until signed. A routine notice should not wall off a waiter
            // mid-service, but a critical one should.
            $table->boolean('must_acknowledge')->default(true);

            // A notice aimed at office staff has no business interrupting
            // the PIN pad on a shared kiosk during service.
            $table->boolean('show_on_kiosk')->default(true);

            $table->enum('audience', ['all', 'roles'])->default('all');

            // Three separate nullable timestamps rather than one status
            // column, because each answers a different question and the
            // combination is what defines visibility (see
            // Announcement::scopeLive). published_at null = still a draft.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every page load for every logged-in user runs the "anything
            // live for me?" query, so it must be index-served.
            $table->index(['published_at', 'unpublished_at', 'expires_at'], 'announcements_live_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
