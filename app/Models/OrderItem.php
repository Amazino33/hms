<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * Get the item name (either product or menu item)
     */
    public function getItemNameAttribute()
    {
        return $this->product_name;
    }

    /**
     * Get the item price
     */
    public function getItemPriceAttribute()
    {
        return $this->unit_price;
    }
}
