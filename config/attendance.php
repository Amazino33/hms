<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Live fines master switch
    |--------------------------------------------------------------------------
    |
    | The outermost guard on charging anybody anything. It stays false until
    | Phase 3 ships leave, swaps and waivers — because until then there is no
    | way to excuse a shift somebody was legitimately off for, and a fine with
    | no way to excuse it is just a wrong fine.
    |
    | AttendanceSetting::isLiveOn() requires this AND shadow_mode off AND an
    | announced start date. Any one of them missing means shadow.
    |
    */

    'allow_live_fines' => env('ATTENDANCE_ALLOW_LIVE_FINES', false),

    /*
    |--------------------------------------------------------------------------
    | Engine start date
    |--------------------------------------------------------------------------
    |
    | The finaliser never reaches back before this date. Without it, the first
    | run after deploy would walk every scheduled shift since the schedules
    | were created and mark months of history absent — for days when nobody
    | was being tracked and the device was not even connected.
    |
    | Re-evaluation and simulation may look further back; ordinary
    | finalisation may not.
    |
    */

    'engine_start_date' => env('ATTENDANCE_ENGINE_START_DATE', '2026-10-08'),

    /*
    |--------------------------------------------------------------------------
    | Timestamp era boundary
    |--------------------------------------------------------------------------
    |
    | attendance_logs holds two conventions. Before commit a6b302f the device's
    | Lagos wall-clock string was stored as if it were UTC, so those rows read
    | one hour later than the punch actually happened. After it, punches are
    | parsed as Lagos and stored as true UTC.
    |
    | Nothing corrects the old rows — the live engine only ever runs on dates
    | after this boundary, so it never meets them. The simulator uses this to
    | label any back-test that crosses it, rather than quietly reporting
    | arrival times that are an hour wrong.
    |
    */

    'timestamp_fix_at' => env('ATTENDANCE_TIMESTAMP_FIX_AT', '2026-09-11 16:43:00'),

];
