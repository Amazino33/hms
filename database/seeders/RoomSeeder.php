<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

/**
 * The venue's real 34 rooms. Placeholder nightly rates only — an admin
 * enters real rates afterward via the Rooms page. Idempotent by room
 * number, safe to re-run (e.g. after adding a new room to this list).
 */
class RoomSeeder extends Seeder
{
    private const PLACEHOLDER_RATE = 15000;

    public function run(): void
    {
        $standard = RoomType::firstOrCreate(
            ['name' => 'Standard'],
            ['price_per_night' => self::PLACEHOLDER_RATE, 'is_active' => true],
        );

        for ($i = 1; $i <= 34; $i++) {
            Room::firstOrCreate(
                ['number' => (string) $i],
                [
                    'type' => 'Standard',
                    'room_type_id' => $standard->id,
                    'price_per_night' => self::PLACEHOLDER_RATE,
                    'status' => 'available',
                    'housekeeping' => 'clean',
                ]
            );
        }
    }
}
