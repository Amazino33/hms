<?php

namespace Database\Seeders;

use App\Models\Guest;
use App\Models\Room;
use App\Models\Table;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Sample records so a fresh non-production install has something to
     * click through in Bookings/POS/Guests. Never run in production.
     */
    public function run(): void
    {
        Room::firstOrCreate(
            ['number' => '101'],
            ['type' => 'Single', 'price_per_night' => 15000, 'status' => 'available'],
        );

        Room::firstOrCreate(
            ['number' => '102'],
            ['type' => 'Double', 'price_per_night' => 25000, 'status' => 'available'],
        );

        Room::firstOrCreate(
            ['number' => '201'],
            ['type' => 'Suite', 'price_per_night' => 45000, 'status' => 'available'],
        );

        Table::firstOrCreate(
            ['name' => 'Table 1'],
            ['capacity' => 4, 'status' => 'available', 'location' => 'Indoor'],
        );

        Table::firstOrCreate(
            ['name' => 'Table 2'],
            ['capacity' => 2, 'status' => 'available', 'location' => 'Outdoor'],
        );

        Guest::firstOrCreate(
            ['name' => 'Demo Guest'],
            ['phone' => '08000000000'],
        );
    }
}