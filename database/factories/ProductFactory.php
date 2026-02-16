<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition()
    {
        return [
            'name' => $this->faker->words(2, true),
            'sku' => strtoupper($this->faker->bothify('???-####')),
            'category_id' => Category::factory(),
            'price' => $this->faker->randomFloat(2, 1, 100),
            'is_active' => true,
        ];
    }
}
