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

];
