<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rooms.type stays exactly as it was — nothing else in the app reads it
 * besides RoomResource's own form, and dropping it would mean touching
 * every test that builds a Room with a 'type' string. room_type_id is the
 * new source of truth for price going forward; existing rooms are backfilled
 * against a RoomType per distinct type string they already used, priced at
 * the average of what those rooms were already charging — a starting point
 * an admin can adjust in the new Room Types settings page, not a guess this
 * migration expects to be final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('room_type_id')->nullable()->after('type')->constrained()->nullOnDelete();
        });

        $groups = DB::table('rooms')
            ->select('type', DB::raw('AVG(price_per_night) as avg_price'))
            ->whereNotNull('type')
            ->groupBy('type')
            ->get();

        foreach ($groups as $group) {
            $roomTypeId = DB::table('room_types')->where('name', $group->type)->value('id');

            if (! $roomTypeId) {
                $roomTypeId = DB::table('room_types')->insertGetId([
                    'name' => $group->type,
                    'price_per_night' => round((float) $group->avg_price, 2),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('rooms')->where('type', $group->type)->update(['room_type_id' => $roomTypeId]);
        }
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_type_id');
        });
    }
};
