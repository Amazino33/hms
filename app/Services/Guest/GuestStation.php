<?php

namespace App\Services\Guest;

use App\Models\Product;
use App\Services\InventoryService;

/**
 * Bar or kitchen — the same routing OrderSplitter applies when an order is
 * created (see docs/audits/phase-2-verification.md §1), built from the
 * same public InventoryService warehouse functions, so a guest's Drinks /
 * Food tab always matches where the order will actually go.
 *
 * Menu items are always kitchen. A product follows its category type via
 * its warehouse; a 'service' product routes to neither and is left off
 * the guest menu (null).
 */
class GuestStation
{
    public const BAR = 'bar';

    public const KITCHEN = 'kitchen';

    public static function forProduct(Product $product): ?string
    {
        $warehouseId = InventoryService::getWarehouseForProduct($product);

        return match ($warehouseId) {
            InventoryService::getBarWarehouseId() => self::BAR,
            InventoryService::getKitchenWarehouseId() => self::KITCHEN,
            default => null,
        };
    }

    public static function tabFor(string $station): string
    {
        return $station === self::BAR ? 'drinks' : 'food';
    }
}
