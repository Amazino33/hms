<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact details the application form collects that users had no place
     * for. Additive and nullable: existing staff simply have them empty.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->text('address')->nullable()->after('phone');
            $table->string('photo_path')->nullable()->after('address');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin_phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address', 'photo_path', 'next_of_kin_relationship']);
        });
    }
};
