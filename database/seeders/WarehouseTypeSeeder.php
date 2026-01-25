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
        // Set warehouse types
        // Assuming warehouse ID 1 is the main storage
        WareHouse::where('id', 1)->update(['type' => 'storage']);
        
        // Set consumer warehouses (bar, kitchen, etc.)
        WareHouse::whereIn('id', [4, 5])->update(['type' => 'consumer']);
        
        // Any other warehouses default to storage
        WareHouse::whereNotIn('id', [1, 4, 5])->update(['type' => 'storage']);
    }
}