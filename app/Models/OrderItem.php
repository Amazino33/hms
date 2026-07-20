<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
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
     * Get the actual product or menu item model
     */
    public function getItemAttribute()
    {
        if ($this->item_type === 'menu_item' && $this->menu_item_id) {
            return $this->menuItem;
        } elseif ($this->item_type === 'product' && $this->product_id) {
            return $this->product;
        }
        return null;
    }

    /**
     * Get the item price
     */
    public function getItemPriceAttribute()
    {
        return $this->unit_price;
    }
}
