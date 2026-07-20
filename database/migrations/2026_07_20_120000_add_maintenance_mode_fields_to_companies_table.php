<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('maintenance_message')->nullable();
            $table->unsignedInteger('maintenance_duration_minutes')->nullable()->default(15);
            $table->string('maintenance_secret')->nullable();
            // Set fresh each time maintenance mode is actually turned on —
            // the countdown shown on the maintenance page is computed from
            // this plus maintenance_duration_minutes, not from when the
            // settings were last saved.
            $table->timestamp('maintenance_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'maintenance_message',
                'maintenance_duration_minutes',
                'maintenance_secret',
                'maintenance_started_at',
            ]);
        });
    }
};
