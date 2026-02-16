<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition()
    {
        return [
            'order_number' => strtoupper($this->faker->bothify('ORD-#####')),
            'status' => 'pending',
            'total_amount' => 0.00,
            'payment_method' => null,
        ];
    }
}
