<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OpeningBalanceSeeder extends Seeder
{
    private const OPENING_BALANCE_DATE = '2026-07-10';

    /**
     * Verified physical-count data from the storekeeper's opening stock
     * sheet, dated 2026-07-10. Confidence notes from the source count are
     * kept only as a comment here — pack sizes/names remain editable by the
     * storekeeper/manager; a go-live verification pass against physical
     * crates is expected for LOW/MEDIUM confidence rows.
     *
     * [name, category, base_unit, purchase_unit_name, units_per_purchase_unit, total_base_units]
     */
    private const ROWS = [
        ['Star Beer', 'Beer', 'bottle', 'crate', 12, 36],
        ['Desperados', 'Beer', 'bottle', 'crate', 20, 80],
        ['Star Radler', 'Beer', 'bottle', 'crate', 20, 100],
        ['Maltina', 'Malt Drink', 'bottle', 'crate', 24, 120],
        ['Hero', 'Beer', 'bottle', 'crate', 12, 48],
        ['Lambrusco', 'Wine', 'bottle', 'pack', 6, 6],
        ['Fearless', 'Energy Drink', 'can', 'pack', 12, 36],
        ['Coke Big', 'Soft Drink', 'bottle', 'pack', 12, 48],
        ['Coke Small', 'Soft Drink', 'bottle', 'pack', 12, 48],
        ['Eva Big', 'Water', 'bottle', 'pack', 12, 36],
        ['Baileys', 'Cream Liqueur', 'bottle', 'pack', 6, 0],
        ['Aquafina', 'Water', 'bottle', 'pack', 12, 228],
        ['Ballamour', 'Wine', 'bottle', 'pack', 36, 36],
        ['Chivita Exotic', 'Juice', 'pack', 'carton', 10, 60],
        ['Hollandia', 'Juice', 'pack', 'carton', 10, 60],
        ['Budweiser', 'Beer', 'bottle', 'crate', 12, 60],
        ['33 Export', 'Beer', 'bottle', 'crate', 12, 36],
        ['Double Black', 'Beer', 'bottle', 'crate', 18, 72],
        ['Sprite Big', 'Soft Drink', 'bottle', 'pack', 12, 24],
        ['Falkenburg', 'Beer', 'bottle', 'pack', 6, 0],
        ['Nutri Milk', 'Malt Drink', 'bottle', 'pack', 12, 72],
        ['Nutri Choco', 'Malt Drink', 'bottle', 'pack', 12, 72],
        ['Big Legend', 'Beer', 'bottle', 'crate', 12, 48],
        ['Medium Legend', 'Beer', 'bottle', 'crate', 18, 0],
        ['Small Legend', 'Beer', 'bottle', 'crate', 24, 24],
        ['Big Stout', 'Beer', 'bottle', 'crate', 12, 48],
        ['Medium Stout', 'Beer', 'bottle', 'crate', 18, 90],
        ['Small Stout', 'Beer', 'bottle', 'crate', 24, 0],
        ['Flying Fish', 'Beer', 'bottle', 'crate', 20, 40],
        ['Fayrouz', 'Soft Drink', 'bottle', 'crate', 24, 24],
        ['Extra Smooth', 'Beer', 'bottle', 'crate', 18, 72],
        ['Vita Milk', 'Malt Drink', 'bottle', 'pack', 24, 96],
        ['Gulder', 'Beer', 'bottle', 'crate', 12, 12],
        ['Magic Moment', 'Wine', 'bottle', 'pack', 12, 6],
        ['Amstel Malt', 'Malt Drink', 'can', 'pack', 24, 72],
        ['Black Bullet', 'Energy Drink', 'can', 'pack', 24, 120],
        ['Blue Bullet', 'Energy Drink', 'can', 'pack', 24, 48],
        ['Black Train', 'Spirits', 'bottle', 'pack', 12, 3],
        ['Blue Train', 'Spirits', 'bottle', 'pack', 12, 3],
        ['Champion', 'Beer', 'bottle', 'crate', 12, 48],
        ['Chamdor', 'Wine', 'bottle', 'pack', 12, 3],
        ['Big Ice', 'Beer', 'bottle', 'crate', 12, 24],
        ['Small Ice', 'Beer', 'bottle', 'crate', 24, 96],
        ['Andre', 'Wine', 'bottle', 'crate', 12, 24],
        ['Origin', 'Beer', 'bottle', 'crate', 12, 60],
        ['Veleta', 'Wine', 'bottle', 'crate', 12, 0],
        ['Kalahari', 'Bitters', 'bottle', 'crate', 12, 12],
        ['Gordon Big', 'Spirits', 'bottle', 'crate', 12, 0],
        ['Gordon Small', 'Spirits', 'bottle', 'pack', 48, 32],
        ['Tiger', 'Beer', 'bottle', 'crate', 20, 60],
        ['Heineken Small', 'Beer', 'bottle', 'crate', 12, 24],
        ['Heineken Big', 'Beer', 'bottle', 'crate', 20, 60],
        ['Jameson', 'Spirits', 'bottle', 'crate', 12, 9],
        ['Schweppes', 'Soft Drink', 'bottle', 'crate', 24, 0],
        ['Four Cousins', 'Wine', 'bottle', 'pack', 6, 12],
        ['Lord Big', 'Spirits', 'bottle', 'crate', 12, 2],
        ['Chelsea Big', 'Spirits', 'bottle', 'crate', 12, 1],
        ['Carlos Rossi', 'Wine', 'bottle', 'pack', 6, 3],
        ['Hennessy', 'Spirits', 'bottle', 'crate', 12, 0],
        ['Don Simon', 'Wine', 'bottle', 'crate', 12, 0],
        ['Toma Wine', 'Wine', 'bottle', 'pack', 6, 6],
        ['William Lawson', 'Spirits', 'bottle', 'crate', 12, 0],
        ['XL Big', 'Spirits', 'bottle', 'crate', 12, 0],
        ['XL Medium', 'Spirits', 'bottle', 'crate', 24, 0],
        ['XL Small', 'Spirits', 'bottle', 'pack', 48, 0],
        ['4th Street', 'Wine', 'bottle', 'pack', 6, 3],
        ['Agor Wine', 'Wine', 'bottle', 'pack', 6, 6],
        ['Campari Big', 'Spirits', 'bottle', 'crate', 12, 0],
        ['Campari Small', 'Spirits', 'bottle', 'pack', 24, 0],
        ['Big Ben', 'Spirits', 'bottle', 'pack', 24, 15],
        ['Monster', 'Energy Drink', 'can', 'pack', 24, 0],
        ['Malta Guinness', 'Malt Drink', 'bottle', 'crate', 24, 48],
        ['Origin Bitters', 'Bitters', 'bottle', 'pack', 24, 0],
    ];

    public function run(): void
    {
        $mainStore = WareHouse::where('type', 'storage')->first() ?? WareHouse::first();
        $recorder = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first() ?? User::first();

        if (! $mainStore || ! $recorder) {
            $this->command?->warn('OpeningBalanceSeeder skipped: no warehouse or user found to seed against.');

            return;
        }

        foreach (self::ROWS as [$name, $categoryName, $baseUnit, $purchaseUnitName, $unitsPerPurchaseUnit, $totalBaseUnits]) {
            $category = Category::firstOrCreate(['name' => $categoryName], ['type' => 'drink']);

            $product = Product::whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

            if ($product) {
                $product->update([
                    'category_id' => $product->category_id ?: $category->id,
                    'base_unit' => $baseUnit,
                    'purchase_unit_name' => $purchaseUnitName,
                    'units_per_purchase_unit' => $unitsPerPurchaseUnit,
                ]);
            } else {
                $product = Product::create([
                    'name' => $name,
                    'category_id' => $category->id,
                    'base_unit' => $baseUnit,
                    'purchase_unit_name' => $purchaseUnitName,
                    'units_per_purchase_unit' => $unitsPerPurchaseUnit,
                    'price' => 0,
                    'cost_price' => 0,
                    'is_active' => true,
                ]);
            }

            if ($totalBaseUnits <= 0) {
                continue;
            }

            $reference = "opening_balance:{$product->id}";
            $alreadySeeded = InventoryTransaction::where('reference', $reference)
                ->where('warehouse_id', $mainStore->id)
                ->exists();

            if ($alreadySeeded) {
                continue;
            }

            InventoryTransaction::create([
                'product_id' => $product->id,
                'warehouse_id' => $mainStore->id,
                'type' => 'opening_balance',
                'quantity' => $totalBaseUnits,
                'reference' => $reference,
                'user_id' => $recorder->id,
                'created_at' => self::OPENING_BALANCE_DATE,
                'updated_at' => self::OPENING_BALANCE_DATE,
            ]);

            $inventory = InventoryItem::where('product_id', $product->id)
                ->where('warehouse_id', $mainStore->id)
                ->first();

            if ($inventory) {
                $inventory->increment('quantity', $totalBaseUnits);
            } else {
                InventoryItem::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $mainStore->id,
                    'quantity' => $totalBaseUnits,
                ]);
            }
        }

        $this->command?->info('Opening balances seeded.');
    }
}
