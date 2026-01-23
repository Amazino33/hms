<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

new class extends Component {
    public $categories;
    public $tables;
    public $selectedTableId;
    public $activeCategoryId;
    public $currentOrderId = null;
    public $cart = [];
    public $total = 0;
    public $search = '';

    
    public function clearSearch()
    {
        $this->search = '';
    }

    // 👇 This runs automatically when you change the dropdown
    public function updatedSelectedTableId($value)
    {
        // 1. Clear current cart to avoid mixing orders
        $this->cart = [];
        $this->currentOrderId = null;

        if (!$value)
            return;

        // 2. Find if this table has a PENDING order
        $existingOrder = \App\Models\Order::where('table_id', $value)
            ->where('status', 'pending')
            ->with('items')
            ->latest()
            ->first();

        // 3. If found, load it into the Cart
        if ($existingOrder) {
            $this->currentOrderId = $existingOrder->id;

            foreach ($existingOrder->items as $item) {
                $this->cart[$item->product_id] = [
                    'id' => $item->product_id,
                    'name' => $item->product_name,
                    'price' => $item->unit_price,
                    'quantity' => $item->quantity,
                    'image' => $item->product->image ?? null,
                ];
            }
            $this->updateTotal();

            \Filament\Notifications\Notification::make()
                ->title('Order Loaded')
                ->body('Resumed order for ' . $existingOrder->table->name)
                ->info()
                ->send();
        }
    }

    public function updateTotal()
    {
        $this->total = 0;

        foreach ($this->cart as $item) {
            $this->total += $item['price'] * $item['quantity'];
        }
    }

    public function mount()
    {
        // Load categories that have products to avoid empty tabs
        $this->categories = Category::has('products')->get();

        // Default to the first category
        $this->activeCategoryId = $this->categories->first()?->id;

        // Load tables WITH their pending orders
        $tables = \App\Models\Table::all();

        foreach ($tables as $table) {
            $hasPendingOrder = $table->orders()->where('status', 'pending')->exists();
            
            if ($hasPendingOrder && $table->status !== 'occupied') {
                $table->update(['status' => 'occupied']);
            } elseif (!$hasPendingOrder && $table->status !== 'available') {
                $table->update(['status' => 'available']);
            }
        }

        // 2. Now load the tables for the view (Fresh data)
        $this->tables = \App\Models\Table::with(['orders' => function ($query) {
            $query->where('status', 'pending');
        }])->get();

        // Default to the first table
        $this->selectedTableId = $this->tables->first()?->id;

        if (request()->has('table_id')) {
            $tableId = request()->query('table_id');
            $this->selectedTableId = $tableId;
            $this->updatedSelectedTableId($tableId);
        }
    }

    public function addToCart($productId)
    {
        $product = Product::find($productId);

        // Add to cart logic
        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity']++;
        } else {
            $this->cart[$productId] = [
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
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
        $this->total = collect($this->cart)->sum(fn($item) => $item['price'] * $item['quantity']);
    }

    public function checkout($action = 'update')
    {
        if (empty($this->cart)) {
            return;
        }

        $tableId = $this->selectedTableId;

        // 1. Database Transaction
        DB::transaction(function () use ($tableId, $action) {
            // A. Determine Status
            $status = ($action === 'pay') ? 'paid' : 'pending';

            // Create Order
            $order = Order::updateOrCreate(
                ['id' => $this->currentOrderId],
                [
                    'order_number' => 'ORD-' . time(),
                    'total_amount' => $this->total,
                    'status' => 'pending',
                    'payment_method' => 'cash',
                    'table_id' => $tableId,
                    'user_id' => auth()->id(),
                ]
            );

            // C. Sync Items
            $order->items()->delete();

            // Create Items & Deduct Stock
            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ]);

                DB::table('inventory_items')
                    ->where('product_id', $productId)
                    ->where('warehouse_id', 2)
                    ->decrement('quantity', $item['quantity']);
            }

            // D. Update Table Status
            $table = \App\Models\Table::find($this->selectedTableId);
            if ($action === 'pay') {
                $order->update(['status' => 'paid']);
                $table->update(['status' => 'available']);
                $this->currentOrderId = null;
                $this->cart = []; 
                $this->selectedTableId = null;
            } else {
                $table->update(['status' => 'occupied']);
            }
        });

        // 2. Reset
        $this->cart = [];
        $this->total = 0;

        // 3. Notify
        Notification::make()
            ->title($action === 'pay' ? 'Payment Received' : 'Order Updated')
            ->success()
            ->send();
    }

    // ✅ FIXED: This now handles both search and category filtering
    public function with()
    {
        $query = Product::where('is_active', true);
        
        // If searching, override category filter
        if (!empty($this->search)) {
            $query->where(function($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('sku', 'like', '%'.$this->search.'%');
            });
        }
        // Only apply category filter when NOT searching
        elseif ($this->activeCategoryId) {
            $query->where('category_id', $this->activeCategoryId);
        }

        return [
            'products' => $query->get(),
        ];
    }
};
?>
{{-- HTML STARTS HERE --}}
<div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
    <script src="https://cdn.tailwindcss.com"></script>

    <div class="col-span-8 flex flex-col h-full bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">

    <div class="m-6">
        <div class="relative">
            <input 
                type="text" 
                wire:model.live.debounce.300ms="search" 
                placeholder="Search Item Name or Barcode..." 
                class="w-full px-4 py-3 pl-12 text-lg border border-gray-300 rounded-xl shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                autofocus
            >

            <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>

            @if($search)
                <button 
                    wire:click="clearSearch"
                    class="absolute inset-y-0 right-0 flex items-center pr-4 text-gray-400 hover:text-red-500"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            @endif
        </div>
    </div>


        <div class="flex overflow-x-auto p-2 bg-gray-50 border-b space-x-2">
            @foreach($categories as $category)
                <button wire:click="$set('activeCategoryId', {{ $category->id }})"
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
        <div class="p-4 border-b bg-white">
            <label class="block text-sm font-bold text-gray-700 mb-1">Select Table</label>
            <select wire:model.live="selectedTableId"
                class="w-full p-2 border rounded-lg bg-gray-50 font-bold text-gray-800">
                <option value="">-- Select a Table --</option>
                @foreach($tables as $table)
                    @php
                        // Check if this table has a pending order
                        $isOccupied = $table->orders->isNotEmpty();
                    @endphp

                    <option value="{{ $table->id }}"
                        class="{{ $isOccupied ? 'text-red-600 font-bold' : 'text-green-600' }}">
                        {{ $table->name }}
                        {{ $isOccupied ? '(Occupied)' : '(Free)' }}
                    </option>
                @endforeach
            </select>
        </div>

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
                            <div class="text-xs text-gray-500">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                        </div>
                        <div class="font-mono font-bold text-gray-700">
                            ₦{{ number_format($item['price'] * $item['quantity']) }}
                        </div>
                        <button wire:click="removeFromCart({{ $id }})" class="ml-3 text-red-500 hover:text-red-700">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
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
            <div class="grid grid-cols-2 gap-3">
                {{-- Button 1: Send to Kitchen (Update) --}}
                <button wire:click="checkout('update')"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg flex flex-col items-center justify-center">
                    <span class="text-sm">👨‍🍳 Send / Update</span>
                    <span class="text-xs font-normal opacity-80">Keep Table Open</span>
                </button>

                {{-- Button 2: Collect Payment (Close) --}}
                <button wire:click="checkout('pay')" wire:confirm="Are you sure you want to close this table?"
                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg flex flex-col items-center justify-center">
                    <span class="text-sm">💰 Collect Cash</span>
                    <span class="text-xs font-normal opacity-80">Close Table</span>
                </button>
            </div>
        </div>
    </div>
</div>