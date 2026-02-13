<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create categories
        $foodCategory = Category::firstOrCreate([
            'name' => 'Food',
            'type' => 'food'
        ]);

        $drinkCategory = Category::firstOrCreate([
            'name' => 'Drinks',
            'type' => 'drink'
        ]);

        // Create sample products
        $products = [
            [
                'name' => 'Egusi Soup',
                'sku' => 'EGU001',
                'category_id' => $foodCategory->id,
                'price' => 8000.00,
                'is_active' => true,
            ],
            [
                'name' => 'Jollof Rice',
                'sku' => 'JOL001',
                'category_id' => $foodCategory->id,
                'price' => 5000.00,
                'is_active' => true,
            ],
            [
                'name' => 'Fried Rice',
                'sku' => 'FRI001',
                'category_id' => $foodCategory->id,
                'price' => 4500.00,
                'is_active' => true,
            ],
            [
                'name' => 'Coke',
                'sku' => 'COK001',
                'category_id' => $drinkCategory->id,
                'price' => 500.00,
                'is_active' => true,
            ],
            [
                'name' => 'Beer',
                'sku' => 'BEE001',
                'category_id' => $drinkCategory->id,
                'price' => 800.00,
                'is_active' => true,
            ],
        ];

        foreach ($products as $productData) {
            Product::firstOrCreate(
                ['sku' => $productData['sku']],
                $productData
            );
        }
    }
}