<?php

use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase always runs every migration against an empty database, so
 * the interesting case here — rooms that already had a free-text `type`
 * before room_type_id existed — can't be exercised by simply letting the
 * suite's normal migration run happen. This drives the actual production
 * migration file directly: undo it, insert legacy-style rows exactly as a
 * pre-migration production database would have them, then re-run it.
 */
it('backfills room_type_id from existing type strings, averaging price per distinct type', function () {
    $migration = require database_path('migrations/2026_07_20_150100_add_room_type_id_to_rooms_table.php');

    $migration->down();

    DB::table('rooms')->insert([
        ['number' => '1', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean', 'created_at' => now(), 'updated_at' => now()],
        ['number' => '2', 'type' => 'Standard', 'price_per_night' => 12000, 'status' => 'available', 'housekeeping' => 'clean', 'created_at' => now(), 'updated_at' => now()],
        ['number' => '3', 'type' => 'Suite', 'price_per_night' => 40000, 'status' => 'available', 'housekeeping' => 'clean', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    $standardType = DB::table('room_types')->where('name', 'Standard')->first();
    $suiteType = DB::table('room_types')->where('name', 'Suite')->first();

    expect($standardType)->not->toBeNull();
    expect((float) $standardType->price_per_night)->toEqual(11000.0); // average of 10000/12000
    expect($suiteType)->not->toBeNull();
    expect((float) $suiteType->price_per_night)->toEqual(40000.0);

    $room1 = DB::table('rooms')->where('number', '1')->first();
    $room2 = DB::table('rooms')->where('number', '2')->first();
    $room3 = DB::table('rooms')->where('number', '3')->first();

    expect($room1->room_type_id)->toBe($standardType->id);
    expect($room2->room_type_id)->toBe($standardType->id);
    expect($room3->room_type_id)->toBe($suiteType->id);

    // Original quantities/prices on the rooms themselves are untouched.
    expect((float) $room1->price_per_night)->toEqual(10000.0);
    expect((float) $room2->price_per_night)->toEqual(12000.0);
});

it('does not create a duplicate room type if one with that name already exists', function () {
    $migration = require database_path('migrations/2026_07_20_150100_add_room_type_id_to_rooms_table.php');

    $migration->down();

    $existingId = DB::table('room_types')->insertGetId([
        'name' => 'Standard', 'price_per_night' => 99999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('rooms')->insert([
        'number' => '1', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('room_types')->where('name', 'Standard')->count())->toBe(1);
    $room = DB::table('rooms')->where('number', '1')->first();
    expect($room->room_type_id)->toBe($existingId);

    // Pre-existing type's own price was NOT overwritten by the backfill.
    $type = DB::table('room_types')->where('name', 'Standard')->first();
    expect((float) $type->price_per_night)->toEqual(99999.0);
});
