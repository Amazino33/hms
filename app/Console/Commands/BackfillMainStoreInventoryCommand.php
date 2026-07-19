<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\WareHouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Some products only ever received their first InventoryItem row at a
 * non-Main-Store warehouse (via Quick Inventory Update or a bulk import
 * naming a different warehouse) — those products are structurally invisible
 * to a Main Store stocktake, which only snapshots InventoryItem rows that
 * already exist for that warehouse. This creates the missing rows at
 * quantity 0, purely additive: it never touches an existing row's quantity
 * anywhere, so no stock counts change, only what's countable at Main Store.
 */
class BackfillMainStoreInventoryCommand extends Command
{
    protected $signature = 'app:backfill-main-store-inventory {--force : Skip the confirmation prompt}';

    protected $description = 'Create missing zero-quantity InventoryItem rows at Main Store for active products that only exist at other warehouses';

    public function handle(): int
    {
        $mainStore = WareHouse::where('type', 'storage')->first();

        if (! $mainStore) {
            $this->error('No warehouse of type "storage" (Main Store) exists — nothing to backfill against.');

            return self::FAILURE;
        }

        $existingProductIds = InventoryItem::where('warehouse_id', $mainStore->id)->pluck('product_id');

        $missingProducts = Product::where('is_active', true)
            ->whereNotIn('id', $existingProductIds)
            ->get(['id', 'name']);

        if ($missingProducts->isEmpty()) {
            $this->info('Nothing to backfill — every active product already has a row at Main Store.');

            return self::SUCCESS;
        }

        $this->line("Main Store: {$mainStore->name} (#{$mainStore->id})");
        $this->line("Products missing a Main Store row: {$missingProducts->count()}");
        foreach ($missingProducts as $product) {
            $this->line("  - {$product->name} (#{$product->id})");
        }
        $this->newLine();
        $this->line('Each will get a new InventoryItem at Main Store with quantity 0. No existing quantity anywhere is changed.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Proceed?')) {
            $this->info('Cancelled — nothing was created.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($missingProducts, $mainStore) {
            foreach ($missingProducts as $product) {
                InventoryItem::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $mainStore->id,
                    'quantity' => 0,
                ]);
            }
        });

        $this->info("Done — created {$missingProducts->count()} zero-quantity InventoryItem row(s) at Main Store.");

        return self::SUCCESS;
    }
}
