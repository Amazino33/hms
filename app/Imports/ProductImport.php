<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryList;
use App\Models\WareHouse;
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

        // 4. Update Inventory
        // We find the warehouse by the name provided in the Excel (e.g. "Main Bar")
        $warehouse = Warehouse::where('name', $row['warehouse'])->first();

        if ($warehouse) {
            InventoryItem::updateOrCreate(
                [
                    'product_id'   => $product->id,
                    'warehouse_id' => $warehouse->id,
                ],
                [
                    'quantity' => $row['quantity'] ?? 0,
                ]
            );
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