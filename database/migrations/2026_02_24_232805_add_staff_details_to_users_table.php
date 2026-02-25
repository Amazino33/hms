<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Professional
            $table->string('staff_code')->unique()->nullable()->after('id');
            $table->string('primary_location')->nullable();

            // Identity
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->string('id_card_copy')->nullable();
            $table->string('guarantor_form')->nullable();

            // Financial
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();

            // Emergency
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'staff_code',
                'primary_location',
                'id_type',
                'id_number',
                'id_card_copy',
                'guarantor_form',
                'base_salary',
                'bank_name',
                'account_number',
                'account_name',
                'next_of_kin_name',
                'next_of_kin_phone',
            ]);
        });
    }
};
