<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Applications from the public /join form. Nothing here can log in: a
     * user account exists only once a super admin approves, and approval is
     * the only way one is created (StaffApplicationService).
     *
     * account_number and id_number are text because the model encrypts them
     * at rest. On rejection every personal field is cleared and the uploaded
     * files deleted; only the name, dates and reason remain as the record
     * that an application was made.
     */
    public function up(): void
    {
        Schema::create('staff_applications', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('status')->default('pending')->index();

            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable()->index();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            // A bcrypt hash of the password the applicant chose. Becomes the
            // account's password on approval; cleared on approval or rejection.
            $table->string('password_hash')->nullable();

            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();

            $table->string('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->string('account_name')->nullable();

            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone')->nullable();
            $table->text('guarantor_address')->nullable();
            $table->string('guarantor_relationship')->nullable();
            $table->string('guarantor_occupation')->nullable();
            $table->string('guarantor_form_path')->nullable();

            $table->string('id_type')->nullable();
            $table->text('id_number')->nullable();
            $table->string('id_document_path')->nullable();

            $table->timestamp('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_applications');
    }
};
