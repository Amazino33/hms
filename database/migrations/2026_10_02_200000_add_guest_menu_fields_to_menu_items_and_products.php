<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7C (D38): owner-set guest menu extras on menu items and
     * products. All nullable / defaulted, so nothing about the POS changes.
     *
     * guest_badge is a short string with its allowed values held in
     * App\Support\GuestMenuOptions (the convention every guest status in
     * this codebase follows — an enum column is painful to extend on MySQL).
     */
    public function up(): void
    {
        foreach (['menu_items', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('guest_badge', 16)->nullable(); // chefs_special | bestseller | new | spicy
                $table->boolean('guest_recommended')->default(false);
                $table->integer('guest_sort')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['menu_items', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['guest_badge', 'guest_recommended', 'guest_sort']);
            });
        }
    }
};
