<?php

namespace Database\Seeders;

use App\Models\WareHouse;
use Illuminate\Database\Seeder;

class WarehouseTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // These IDs are relied on as hardcoded fallbacks elsewhere (e.g.
        // OrderSplitter::getBarWarehouseId(), InventoryService), so they
        // must exist in every environment, not just local/demo setups.
        WareHouse::firstOrCreate(
            ['id' => 1],
            ['name' => 'Main Store', 'type' => 'storage', 'is_active' => true],
        );

        WareHouse::firstOrCreate(
            ['id' => 4],
            ['name' => 'Bar', 'type' => 'consumer', 'is_active' => true],
        );

        WareHouse::firstOrCreate(
            ['id' => 5],
            ['name' => 'Kitchen', 'type' => 'consumer', 'is_active' => true],
        );

        // Backfill type for any warehouse rows that predate this seeder
        // and still have a null/unset type.
        WareHouse::where('id', 1)->whereNull('type')->update(['type' => 'storage']);
        WareHouse::whereIn('id', [4, 5])->whereNull('type')->update(['type' => 'consumer']);
        WareHouse::whereNotIn('id', [1, 4, 5])->whereNull('type')->update(['type' => 'storage']);
    }
}