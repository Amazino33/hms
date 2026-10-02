<?php

namespace App\Services\Guest;

use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * Guest request refs (Phase 2): "T5-0423" / "R7-0018" — T or R, the
 * table/room number, then a 4-digit sequence that restarts each BUSINESS
 * day (9am Lagos), so a late-night request and the morning after it fall in
 * the right day. Mirrors OrderNumberGenerator: insert-if-missing, then a
 * locked increment inside the caller's transaction.
 */
class GuestRequestRefs
{
    /**
     * @return array{ref: string, business_date: string}
     */
    public static function next(string $placeCode): array
    {
        $businessDate = BusinessDay::today();

        DB::table('guest_request_sequences')->insertOrIgnore([
            'business_date' => $businessDate,
            'last_value' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('guest_request_sequences')->where('business_date', $businessDate)->lockForUpdate()->first();
        $next = (int) $row->last_value + 1;

        DB::table('guest_request_sequences')->where('id', $row->id)->update(['last_value' => $next, 'updated_at' => now()]);

        return [
            'ref' => $placeCode.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT),
            'business_date' => $businessDate,
        ];
    }

    /**
     * "T5" for "Table 5", "TVIP2" for "VIP 2", "R7" for room 7 — the short
     * code staff and the guest read back to each other. Tables only have a
     * name: "Table N" (or a bare number) becomes TN, anything else its first
     * six letters/digits.
     */
    public static function tableCode(string $tableName): string
    {
        $alnum = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', preg_replace('/^\s*table\s*/i', '', $tableName)));

        if (preg_match('/^\d+$/', $alnum)) {
            return 'T'.$alnum;
        }

        return 'T'.substr($alnum !== '' ? $alnum : 'X', 0, 6);
    }

    public static function roomCode(string $roomNumber): string
    {
        return 'R'.substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $roomNumber)) ?: 'X', 0, 6);
    }
}
