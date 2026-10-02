<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1A — menu content for the guest QR menu. On BOTH tables: food
     * is mostly menu_items, but every drink on the menu is a product.
     *
     * Paths are relative to the 'menu_photos' disk. Both WebP files are
     * produced by App\Services\MenuPhotoProcessor; the original upload is
     * never kept.
     */
    public function up(): void
    {
        foreach (['menu_items', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('photo_path')->nullable();
                $table->string('photo_thumb_path')->nullable();
                $table->string('description', 160)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['menu_items', 'products'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['photo_path', 'photo_thumb_path', 'description']);
            });
        }
    }
};
