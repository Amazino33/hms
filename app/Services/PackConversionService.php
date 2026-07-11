<?php

namespace App\Services;

use InvalidArgumentException;

class PackConversionService
{
    /**
     * Convert an entered quantity into base units (bottle/kg/litre/etc).
     * When entered as a purchase unit (crate/carton/pack), units_per_purchase_unit
     * must be known — callers should hide that option in the UI otherwise.
     */
    public static function toBaseQty(float $enteredQty, string $enteredUnit, ?int $unitsPerPurchaseUnit): float
    {
        if ($enteredUnit === 'purchase_unit') {
            if (! $unitsPerPurchaseUnit || $unitsPerPurchaseUnit < 2) {
                throw new InvalidArgumentException('Cannot convert a purchase-unit quantity without a valid units_per_purchase_unit.');
            }

            return round($enteredQty * $unitsPerPurchaseUnit, 2);
        }

        return round($enteredQty, 2);
    }

    public static function unitCost(float $lineTotalCost, float $baseQty): float
    {
        if ($baseQty <= 0) {
            return 0.0;
        }

        return round($lineTotalCost / $baseQty, 4);
    }
}
