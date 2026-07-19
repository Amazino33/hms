<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryList;
use App\Models\InventoryTransaction;
use App\Models\WareHouse;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class ProductImport implements OnEachRow, WithHeadingRow
{
    public function onRow(Row $row)
    {
        $rowIndex = $row->getIndex();
        $row      = $row->toArray();
        
        // 1. Validate Essential Data
        if (!isset($row['name']) || !isset($row['price'])) {
            return; 
        }

        // 2. Get the Category ID (using the helper below)
        $categoryId = $this->getCategoryId($row['category_id']);

        // Safety Check: If category doesn't exist, we can't create the product
        // (Optional: You could assign a default "Uncategorized" ID here if you have one)
        if (!$categoryId) {
            return; 
        }

        // 3. Find or Create the Product
        $product = Product::firstOrCreate(
            ['sku' => $row['sku']], 
            [
                'name'        => $row['name'],
                'category_id' => $categoryId,
                'price'       => $row['price'],
                'cost_price'  => $row['cost'] ?? 0,
            ]
        );

        // 4. Add Inventory
        // Always Main Store, regardless of what the spreadsheet's
        // "warehouse" column says — a product's first-ever stock record
        // must never be created anywhere else, or it becomes invisible to
        // a Main Store stocktake (which only ever counts InventoryItem rows
        // that already exist there). Move stock elsewhere afterwards via a
        // transfer. A bulk import row is a stock intake, not a correction —
        // it can only ever ADD to existing stock, never silently overwrite
        // it, and every addition is logged as an InventoryTransaction like
        // any other purchase.
        $warehouse = WareHouse::where('type', 'storage')->first();
        $quantity = (float) ($row['quantity'] ?? 0);

        if ($warehouse && $quantity > 0) {
            DB::transaction(function () use ($product, $warehouse, $quantity, $rowIndex) {
                $inventory = InventoryItem::query()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->first();

                if ($inventory) {
                    $inventory->increment('quantity', $quantity);
                } else {
                    InventoryItem::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => $quantity,
                    ]);
                }

                InventoryTransaction::create([
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => 'purchase',
                    'quantity' => $quantity,
                    'cost_per_unit' => $product->cost_price,
                    'reference' => 'bulk_import_row_' . $rowIndex,
                    'user_id' => auth()->id(),
                ]);
            });
        }
    }

    /**
     * Helper: Find Category ID by Name
     */
    private function getCategoryId($input)
    {
        // A. If the user put a number (ID) in the excel, use it directly.
        if (is_numeric($input)) {
            return $input;
        }

        // B. If the user put text (e.g., "Beer"), search the 'name' column.
        // We use 'LIKE' to make it case-insensitive ("beer" finds "Beer").
        $category = Category::where('name', 'LIKE', $input)->first();

        return $category ? $category->id : null; 
    }
}