<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why and by whom someone was deactivated, alongside the existing
     * users.left_at. Additive and nullable: every current user stays active,
     * and nothing that already reads left_at (payroll, attendance) changes.
     *
     * deactivated_by is nullOnDelete only for form's sake — users can no
     * longer be deleted (User::booting()).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('left_reason')->nullable()->after('left_at');
            $table->text('left_note')->nullable()->after('left_reason');
            $table->foreignId('deactivated_by')->nullable()->after('left_note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn(['left_reason', 'left_note']);
        });
    }
};
