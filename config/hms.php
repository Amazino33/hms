<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Selling price rounding step
    |--------------------------------------------------------------------------
    |
    | When the procurement price panel suggests a new selling price (based
    | on a product's cost change, preserving its existing margin), the
    | suggestion is rounded up to the nearest multiple of this value.
    |
    */
    'price_rounding_step' => (int) env('HMS_PRICE_ROUNDING_STEP', 50),

    /*
    |--------------------------------------------------------------------------
    | Reservation auto-release hour
    |--------------------------------------------------------------------------
    |
    | A same-day reservation with no deposit is released (status -> no_show)
    | once this hour of the day passes, freeing the room back up. Deposit-
    | backed reservations never auto-release. 24-hour clock, default 18:00.
    |
    */
    'reservation_auto_release_hour' => (int) env('HMS_RESERVATION_AUTO_RELEASE_HOUR', 18),

];
