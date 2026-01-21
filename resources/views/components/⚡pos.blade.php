<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

new class extends Component
{
    public $categories;
    public $activeCategoryId;
    public $cart = []; 
    public $total = 0;

    public function mount()
    {
        // Load categories that have products to avoid empty tabs
        $this->categories = Category::has('products')->get();
        
        // Default to the first category
        $this->activeCategoryId = $this->categories->first()?->id;
    }

    public function addToCart($productId)
    {
        $product = Product::find($productId);
        
        // Add to cart logic
        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['qty']++;
        } else {
            $this->cart[$productId] = [
                'name' => $product->name,
                'price' => $product->price,
                'qty' => 1,
            ];
        }
        $this->calculateTotal();
    }

    public function removeFromCart($productId)
    {
        unset($this->cart[$productId]);
        $this->calculateTotal();
    }

    public function calculateTotal()
    {
        $this->total = collect($this->cart)->sum(fn($item) => $item['price'] * $item['qty']);
    }

    public function checkout()
    {
        if (empty($this->cart)) {
            return;
        }

        // 1. Database Transaction
        DB::transaction(function () {
            // Create Order
            $order = Order::create([
                'order_number' => 'ORD-' . time(),
                'total_amount' => $this->total,
                'status' => 'pending',
                'payment_method' => 'cash',
            ]);

            // Create Items & Deduct Stock
            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_name' => $item['name'],
                    'quantity' => $item['qty'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['qty'],
                ]);

                // Update Inventory (Assuming Warehouse ID 2 is "Bar")
                // In a real app, you might pick the warehouse dynamically
                DB::table('inventory_items')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', 2) 
                    ->decrement('quantity', $item['qty']);
            }
        });

        // 2. Reset
        $this->cart = [];
        $this->total = 0;

        // 3. Notify
        Notification::make() 
            ->title('Order Sent to Kitchen')
            ->success()
            ->send();
    }
    
    // Provide data to the view
    public function with()
    {
        return [
            'products' => Product::where('category_id', $this->activeCategoryId)->get(),
        ];
    }
};
?>
{{-- HTML STARTS HERE --}}
<div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <div class="col-span-8 flex flex-col h-full bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        
        <div class="flex overflow-x-auto p-2 bg-gray-50 border-b space-x-2">
            @foreach($categories as $category)
                <button 
                    wire:click="$set('activeCategoryId', {{ $category->id }})"
                    class="px-4 py-2 rounded-lg text-sm font-bold whitespace-nowrap transition-colors
                    {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' }}">
                    {{ $category->name }}
                </button>
            @endforeach
        </div>

        <div class="flex-1 overflow-y-auto p-4 grid grid-cols-3 gap-4 content-start">
            @foreach($products as $product)
                <div wire:click="addToCart({{ $product->id }})" 
                     class="cursor-pointer bg-white border hover:border-amber-500 hover:shadow-md rounded-xl p-3 flex flex-col items-center justify-center text-center transition-all h-32">
                    <div class="font-bold text-gray-800 line-clamp-2">{{ $product->name }}</div>
                    <div class="text-amber-600 font-mono mt-1">₦{{ number_format($product->price) }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="col-span-4 bg-white rounded-xl shadow-sm border border-gray-200 flex flex-col h-full">
        <div class="p-4 border-b bg-gray-50 font-bold text-lg text-gray-800">
            Current Order
        </div>

        <div class="flex-1 overflow-y-auto p-4 space-y-3">
            @if(empty($cart))
                <div class="text-center text-gray-400 mt-10">Cart is empty</div>
            @else
                @foreach($cart as $id => $item)
                    <div class="flex justify-between items-center border-b pb-2">
                        <div class="flex-1">
                            <div class="font-bold text-sm">{{ $item['name'] }}</div>
                            <div class="text-xs text-gray-500">₦{{ $item['price'] }} x {{ $item['qty'] }}</div>
                        </div>
                        <div class="font-mono font-bold text-gray-700">
                            ₦{{ number_format($item['price'] * $item['qty']) }}
                        </div>
                        <button wire:click="removeFromCart({{ $id }})" class="ml-3 text-red-500 hover:text-red-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                              <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                            </svg>
                        </button>
                    </div>
                @endforeach
            @endif
        </div>

        <div class="p-4 bg-gray-50 border-t">
            <div class="flex justify-between text-xl font-bold mb-4 text-gray-900">
                <span>Total:</span>
                <span>₦{{ number_format($total) }}</span>
            </div>
            <button 
                wire:click="checkout" 
                wire:loading.attr="disabled"
                class="w-full bg-green-600 hover:bg-green-700 text-white py-4 rounded-xl text-lg font-bold shadow-lg transform active:scale-95 transition-all">
                <span wire:loading.remove>PLACE ORDER</span>
                <span wire:loading>PROCESSING...</span>
            </button>
        </div>
    </div>
</div>