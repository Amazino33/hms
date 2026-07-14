<?php

namespace Database\Seeders;

use App\Models\IncidentalPriceListItem;
use Illuminate\Database\Seeder;

class IncidentalPriceListSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['name' => 'Extra towel', 'price' => 500],
            ['name' => 'Extra pillow', 'price' => 500],
            ['name' => 'Laundry service', 'price' => 2000],
            ['name' => 'Late checkout (per hour)', 'price' => 3000],
            ['name' => 'Damage / breakage fee', 'price' => 5000],
            ['name' => 'Airtime top-up', 'price' => 1000],
        ];

        foreach ($items as $item) {
            IncidentalPriceListItem::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
