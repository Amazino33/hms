<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role names are stored as plain strings rather than FKs to Spatie's
     * roles table on purpose: this row records the AUTHOR'S INTENT ("this
     * went to waiters"), which must survive a role being renamed or
     * deleted later. The resolved list of actual people lives in
     * announcement_recipients, which is the roster reporting reads from.
     */
    public function up(): void
    {
        Schema::create('announcement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('role_name');
            $table->timestamps();

            $table->unique(['announcement_id', 'role_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_targets');
    }
};
