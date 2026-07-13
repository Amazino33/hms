<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['bottle', 'crate', 'pack', 'carton', 'dozen'] as $name) {
            Unit::firstOrCreate(['name' => $name]);
        }
    }
}
