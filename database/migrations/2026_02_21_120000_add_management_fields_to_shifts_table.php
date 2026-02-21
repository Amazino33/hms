<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('status')->default('active')->after('ended_at');
            $table->decimal('declared_cash', 10, 2)->default(0)->after('status');
            $table->decimal('declared_pos', 10, 2)->default(0)->after('declared_cash');
            $table->decimal('supervisor_confirmed_cash', 10, 2)->default(0)->after('declared_pos');
            $table->decimal('supervisor_confirmed_pos', 10, 2)->default(0)->after('supervisor_confirmed_cash');
        });

        DB::table('shifts')
            ->whereNotNull('ended_at')
            ->update(['status' => 'closed']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'declared_cash',
                'declared_pos',
                'supervisor_confirmed_cash',
                'supervisor_confirmed_pos',
            ]);
        });
    }
};
