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
        // Storage warehouses: can have direct quantity input
        WareHouse::where('id', 1)->update(['type' => 'storage']);
        
        // Consumer warehouses: receive stock through transfers only
        // Typically: ID 4 = Bar, ID 5 = Kitchen
        WareHouse::whereIn('id', [4, 5])->update(['type' => 'consumer']);
        
        // Any other warehouses default to storage
        WareHouse::whereNotIn('id', [1, 4, 5])->update(['type' => 'storage']);
    }
}