<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A staff member's guarantor, in its own table rather than on users so
     * existing staff can be given one later and someone can have more than
     * one. users.guarantor_form (a lone file path) stays as it is.
     */
    public function up(): void
    {
        Schema::create('staff_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('relationship')->nullable();
            $table->string('occupation')->nullable();
            $table->string('form_path')->nullable();
            $table->foreignId('staff_application_id')->nullable()->constrained('staff_applications')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_guarantors');
    }
};
