<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow; // Ignore the first row (headers)

class ProductImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        // 1. Handle Categories (Optional: Find ID by Name)
        // If Excel says "Beer", find the Category ID for Beer.
        $category = Category::where('name', $row['category_id'])->first();
        
        // 2. Create or Update the Product
        // We use 'updateOrCreate' so we don't create duplicates if the name exists
        return Product::updateOrCreate(
            ['name' => $row['name']], // Check if this name exists
            [
                'sku' => $row['sku'],
                'category_id' => $category ? $category->id : null, // Assign category if found
                'price' => $row['price'],
                'cost' => $row['cost'],
                'is_active' => true,
            ]
        );
    }
}