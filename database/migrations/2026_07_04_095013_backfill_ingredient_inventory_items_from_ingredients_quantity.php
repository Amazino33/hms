<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing Ingredient.quantity is a single global figure with no
     * warehouse dimension. Since storekeeper stock conceptually lives at
     * Main Store, seed each ingredient's current quantity there so the new
     * per-warehouse table starts in agreement with the old column.
     */
    public function up(): void
    {
        $mainStoreId = DB::table('warehouses')->where('type', 'storage')->orderBy('id')->value('id') ?? 1;

        $ingredients = DB::table('ingredients')->select('id', 'quantity')->get();

        $now = now();

        foreach ($ingredients as $ingredient) {
            DB::table('ingredient_inventory_items')->updateOrInsert(
                ['ingredient_id' => $ingredient->id, 'warehouse_id' => $mainStoreId],
                ['quantity' => $ingredient->quantity, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        // Backfill-only migration; nothing to structurally reverse beyond
        // clearing the rows it created.
        DB::table('ingredient_inventory_items')->truncate();
    }
};
