<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\Guest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Filament\Notifications\Notification;
use App\Services\OrderSplitter;

new class extends Component {
    public $categories;
    public $tables;
    public $selectedTableId;
    public $activeCategoryId;
    public $currentOrderId = null;
    public $cart = [];
    public $existingItems = [];
    public $total = 0;
    public $search = '';

    // Mobile-specific properties
    public $showCart = false;

    // Payment Properties
    public $showPaymentModal = false;
    public $paidAmount = 0;
    public $paymentMethod = 'cash';
    public $selectedGuestId = null;

    // 👇 NEW: Guest Creation Properties
    public $showGuestModal = false;
    public $newGuestName = '';
    public $newGuestPhone = '';

    public function clearSearch()
    {
        $this->search = '';
    }

    public function updatedSelectedTableId($value)
    {
        $this->selectedTableId = $value;
        $this->cart = [];
        $this->existingItems = [];
        $this->currentOrderId = null;

        if (!$value)
            return;

        // Get the selected table
        $table = $this->tables->find($value);
        if (!$table) return;

        // If table has active orders, load existing items
        if ($table->orders->isNotEmpty()) {
            $orders = \App\Models\Order::where('table_id', $value)
                ->where('status', 'pending')
                ->with('items.product')
                ->get();

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    if (isset($this->existingItems[$item->product_id])) {
                        $this->existingItems[$item->product_id]['quantity'] += $item->quantity;
                    } else {
                        $this->existingItems[$item->product_id] = [
                            'id' => $item->product_id,
                            'name' => $item->product_name,
                            'price' => $item->unit_price,
                            'quantity' => $item->quantity,
                            'image' => $item->product->image ?? null,
                        ];
                    }
                }
            }

            $this->updateTotal();
            if ($orders->isNotEmpty()) {
                Notification::make()->title('Order Resumed')->info()->send();
            }
        }
        // If table is free, cart remains empty
    }

    public function updateTotal()
    {
        $this->total = 0;
        foreach ($this->cart as $item) {
            $this->total += $item['price'] * $item['quantity'];
        }
        foreach ($this->existingItems as $item) {
            $this->total += $item['price'] * $item['quantity'];
        }
    }

    public function mount($table_id = null)
    {
        $this->categories = Cache::remember('categories', 3600, function () {
            return Category::has('products')->get();
        });
        $this->activeCategoryId = $this->categories->first()?->id;

        $this->loadTables();

        $this->selectedTableId = $table_id ?? $this->tables->first(function($table) {
            $hasActiveOrder = $table->orders->isNotEmpty();
            return $table->status === 'available' || (!$hasActiveOrder && $table->status === 'occupied');
        })?->id ?? $this->tables->first()?->id;

        // Load existing order if table is selected
        if ($this->selectedTableId) {
            $this->updatedSelectedTableId($this->selectedTableId);
        }
    }

    public function addToCart($productId)
    {
        $product = Product::with('category')->find($productId);

        // Check stock availability in consumer warehouses
        $available = (int) DB::table('inventory_items')
            ->where('product_id', $productId)
            ->where('warehouse_id', '!=', 3)
            ->sum('quantity');

        $currentQty = isset($this->cart[$productId]) ? $this->cart[$productId]['quantity'] : 0;
        if ($available <= $currentQty) {
            Notification::make()->title('Out of Stock')->danger()->send();
            return;
        }

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
        $this->total = collect($this->cart)->sum(fn($item) => $item['price'] * $item['quantity']) + collect($this->existingItems)->sum(fn($item) => $item['price'] * $item['quantity']);
    }

    // --- PAYMENT LOGIC ---

    public function openPaymentModal()
    {
        if (empty($this->cart) && empty($this->existingItems))
            return;
        $this->calculateTotal();
        $this->paidAmount = $this->total;
        $this->paymentMethod = 'cash';
        $this->selectedGuestId = null;
        $this->showPaymentModal = true;
        $this->showCart = false; // Close cart on mobile when opening payment
    }

    // 👇 NEW: Logic to Save a Guest instantly
    public function saveNewGuest()
    {
        $this->validate([
            'newGuestName' => 'required|string|min:2',
            'newGuestPhone' => 'nullable|string|min:10', // Optional but recommended
        ]);

        // Create the guest
        $guest = Guest::create([
            'name' => $this->newGuestName,
            'phone' => $this->newGuestPhone,
            // 'email' => ... (if you need it)
        ]);

        // Auto-select the new guest in the dropdown
        $this->selectedGuestId = $guest->id;

        // Reset and Close Guest Modal
        $this->newGuestName = '';
        $this->newGuestPhone = '';
        $this->showGuestModal = false;

        Notification::make()->title('Guest Added')->success()->send();
    }

    public function processPayment()
    {
        // Ensure user is authenticated
        if (!auth()->check()) {
            Notification::make()->title('Authentication Required')->danger()->send();
            return;
        }

        $this->validate([
            'paidAmount' => 'required|numeric|min:0',
            'paymentMethod' => 'required',
            'selectedGuestId' => ($this->paidAmount < $this->total) ? 'required' : 'nullable',
        ], [
            'selectedGuestId.required' => 'Select a Guest to record debt.',
        ]);

        $tableId = $this->selectedTableId;

        $orderStatus = ($this->paidAmount >= $this->total) ? 'paid' : 'partial';
        $tableStatus = 'available';

        // 1. Restore old stock & delete all previous orders for the table
        $existingOrders = Order::where('table_id', $tableId)->where('status', 'pending')->with('items')->get();
        foreach ($existingOrders as $existingOrder) {
            foreach ($existingOrder->items as $item) {
                $product = Product::with('category')->find($item->product_id);
                if ($product) {
                    $warehouseId = $this->getWarehouseId($product);
                    DB::table('inventory_items')
                        ->where('product_id', $item->product_id)
                        ->where('warehouse_id', $warehouseId)
                        ->increment('quantity', $item->quantity);
                }
            }
            $existingOrder->items()->delete();
            $existingOrder->delete();
        }

        // Prepare all items for OrderSplitter (combine existing and new, summing quantities)
        $allItems = $this->existingItems;
        foreach ($this->cart as $productId => $item) {
            if (isset($allItems[$productId])) {
                $allItems[$productId]['quantity'] += $item['quantity'];
            } else {
                $allItems[$productId] = $item;
            }
        }

        // Use OrderSplitter service to create separate orders per destination
        $splitter = new OrderSplitter();
        $orders = $splitter->handle($allItems, $tableId, auth()->id(), [
            'amount_paid' => $this->paidAmount,
            'payment_method' => $this->paymentMethod,
            'status' => $orderStatus,
            'guest_id' => $this->selectedGuestId,
        ]);

        // Create payment record for shift tracking (only one for the total payment)
        if ($this->paidAmount > 0 && !empty($orders)) {
            \App\Models\OrderPayment::create([
                'order_id' => $orders[0]->id,
                'amount' => $this->paidAmount,
                'method' => $this->paymentMethod,
                'user_id' => auth()->id(),
                'shift_id' => auth()->user()?->currentShift()?->id,
                'paid_at' => now(),
            ]);
        }

        \App\Models\Table::find($tableId)->update(['status' => $tableStatus]);

        // Reload tables to reflect status change
        $this->loadTables();

        $balance = $this->total - $this->paidAmount;
        $msg = $orderStatus === 'paid' ? "Paid: ₦" . number_format($this->paidAmount) : "Debt Recorded: ₦" . number_format($balance);
        Notification::make()->title($msg)->success()->send();

        // Reset UI
        $this->showPaymentModal = false;
        $this->existingItems = [];
        $this->cart = [];
        $this->currentOrderId = null;
        $this->selectedTableId = null;
        $this->paidAmount = 0;
        $this->selectedGuestId = null;
        $this->showCart = false; // Close cart on mobile after payment
    }

    // --- STANDARD CHECKOUT (Send to Kitchen) ---
    public function checkout($action = 'update')
    {
        if (empty($this->cart)) return;
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }
        $tableId = $this->selectedTableId;
        $tableName = \App\Models\Table::find($tableId)?->name ?? 'Unknown';

        // Use OrderSplitter to create separate orders for 'update' checkout
        $splitter = new OrderSplitter();
        $orders = $splitter->handle($this->cart, $tableId, auth()->id(), [
            'status' => 'pending',
            'payment_method' => 'cash',
        ]);

        // Update table status to occupied when order is sent to kitchen
        \App\Models\Table::find($tableId)->update(['status' => 'occupied']);

        // Move cart items to existing items (grayed out)
        foreach ($this->cart as $productId => $item) {
            $this->existingItems[$productId] = [
                'id' => $productId,
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['quantity'],
                'image' => null, // Could fetch from product if needed
            ];
        }

        $this->cart = [];
        $this->updateTotal();
        $this->showCart = false; // Close cart on mobile after checkout
        Notification::make()->title('Order Updated')->success()->send();
    }

    public function cancelOrder()
    {
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }

        $tableId = $this->selectedTableId;

        // Find all pending orders for this table and cancel them
        $orders = Order::where('table_id', $tableId)
            ->where('status', 'pending')
            ->get();

        if ($orders->isEmpty()) {
            Notification::make()->title('No active orders to cancel')->warning()->send();
            return;
        }

        // Update order statuses to cancelled
        foreach ($orders as $order) {
            $order->update(['status' => 'cancelled']);
        }

        // Set table status to available
        \App\Models\Table::find($tableId)->update(['status' => 'available']);

        // Clear cart and existing items
        $this->cart = [];
        $this->existingItems = [];
        $this->currentOrderId = null;
        $this->total = 0;

        // Reload tables to reflect status change
        $this->loadTables();

        Notification::make()->title('Order Cancelled')->success()->send();
    }

    public function with()
    {
        $cacheKey = 'products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search;

        return Cache::remember($cacheKey, 1800, function () {
            $query = Product::where('is_active', true)->withSum(['inventory as available_stock' => fn($q) => $q->where('warehouse_id', '!=', 3)], 'quantity');
            if (!empty($this->search))
                $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));
            elseif ($this->activeCategoryId)
                $query->where('category_id', $this->activeCategoryId);

            return ['products' => $query->limit(100)->get()];
        });
    }

    private function loadTables()
    {
        $this->tables = Cache::remember('tables_with_active_orders', 300, function () {
            return \App\Models\Table::with(['orders' => function ($q) {
                $q->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
                    ->latest();
            }])->get();
        });
    }

    // Helper to get Warehouse ID consistently
    private function getWarehouseId($product)
    {
        if (!$product || !$product->category) return 3; // Default to Main Warehouse (ID 3)

        return match($product->category->type) {
            'drink' => 4, // 🍺 Bar
            'food'  => 5, // 🍔 Kitchen
            default => 3, // 📦 Main
        };
    }
};
?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Desktop Layout (Hidden on Mobile) -->
    <div class="hidden lg:block">
        <div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
            <div class="col-span-8 flex flex-col h-full bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 lg:m-0 lg:relative">
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search Item Name or Barcode..."
                            class="w-full px-4 py-3 pl-12 text-base lg:text-lg border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            autofocus>
                    </div>
                </div>
                <div class="flex overflow-x-auto overflow-y-hidden p-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 space-x-2 flex-nowrap">
                    @foreach($categories as $category)
                        <button wire:click="$set('activeCategoryId', {{ $category->id }})"
                            class="px-3 py-2 lg:px-4 rounded-lg text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600' }}">{{ $category->name }}</button>
                    @endforeach
                </div>
                <div class="flex-1 overflow-y-auto p-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-4 content-start">
                    @foreach($products as $product)
                        <div wire:click="addToCart({{ $product->id }})"
                            class="relative cursor-pointer bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-amber-500 hover:shadow-md rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <span class="absolute top-2 right-2 px-1.5 py-0.5 lg:px-2 lg:py-0.5 rounded-full text-xs font-bold {{ ($product->available_stock ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $product->available_stock ?? 0 }} left</span>
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">{{ $product->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">₦{{ number_format($product->price) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="col-span-4 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col h-full lg:h-full max-h-[50vh] lg:max-h-none">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Select Table</label>
                    <select wire:model.live="selectedTableId"
                        class="w-full p-3 text-base border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 font-bold touch-manipulation">
                        <option value="">-- Select a Table --</option>
                        @foreach($tables as $table)
                            @php
                                $hasActiveOrder = $table->orders->isNotEmpty();
                                $isOccupied = $table->status === 'occupied' && $hasActiveOrder;
                            @endphp
                            <option value="{{ $table->id }}" class="{{ $isOccupied ? 'text-red-600 font-bold' : 'text-green-600' }}">
                                {{ $table->name }} {{ $isOccupied ? '(Occupied)' : '(Free)' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1 overflow-y-auto p-4 space-y-3">
                    @if(!empty($existingItems))
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2">Existing Items</h4>
                        @foreach($existingItems as $id => $item)
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2 opacity-75">
                                <div class="flex-1">
                                    <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                                </div>
                                <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                            </div>
                        @endforeach
                        @if(!empty($cart))
                            <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 mt-4">New Items</h4>
                        @endif
                    @endif
                    @foreach($cart as $id => $item)
                        <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                            <button wire:click="removeFromCart({{ $id }})" class="ml-3 text-red-500 hover:text-red-700 touch-manipulation p-1"><span class="text-lg">×</span></button>
                        </div>
                    @endforeach
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between text-xl lg:text-2xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                        <span>Total:</span><span>₦{{ number_format($total) }}</span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <button wire:click="checkout('update')"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Order</span></button>
                        <button wire:click="openPaymentModal"
                            class="bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Pay</span></button>
                        <button wire:click="cancelOrder"
                            class="bg-red-600 hover:bg-red-700 text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Cancel</span></button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Layout (Hidden on Desktop) -->
    <div class="lg:hidden min-h-screen flex flex-col">
        <!-- Mobile Search - Fixed -->
        <div class="bg-white dark:bg-gray-900 px-4 py-3 border-b border-gray-200 dark:border-gray-700 fixed top-[62px] left-0 right-0 z-20">
            <div class="relative">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search products..."
                    class="w-full px-4 py-3 pl-12 text-base border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                @if($search)
                    <button wire:click="clearSearch" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        <!-- Mobile Categories - Fixed -->
        <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 fixed top-[135px] left-0 right-0 z-20">
            <div class="flex overflow-x-auto overflow-y-hidden p-3 space-x-2 flex-nowrap">
                <button wire:click="$set('activeCategoryId', null)"
                    class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ !$activeCategoryId ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                    All
                </button>
                @foreach($categories as $category)
                    <button wire:click="$set('activeCategoryId', {{ $category->id }})"
                        class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Mobile Products Grid - Scrollable -->
        <div class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 mt-[103px] mb-[120px]">
            <div class="grid grid-cols-2 gap-3">
                @foreach($products as $product)
                    <div wire:click="addToCart({{ $product->id }})"
                        class="relative bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-amber-500 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation active:scale-95">
                        <div class="absolute top-2 right-2">
                            <span class="px-1.5 py-0.5 rounded-full text-xs font-bold {{ ($product->available_stock ?? 0) > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $product->available_stock ?? 0 }}
                            </span>
                        </div>
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">{{ $product->name }}</div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">₦{{ number_format($product->price) }}</div>
                        <div class="mt-2">
                            <div class="bg-amber-500 text-white text-xs px-2 py-1 rounded-full font-bold">TAP TO ADD</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Mobile Total - Fixed above POS bar -->
        <div class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 py-3 fixed bottom-[62px] left-0 right-0 z-25">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="text-center">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Total</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format($total) }}</div>
                    </div>
                    @if(!empty($cart) || !empty($existingItems))
                        <div class="text-center">
                            <div class="text-xs text-gray-500 dark:text-gray-400">Items</div>
                            <div class="text-lg font-bold text-blue-600">{{ count($cart) + count($existingItems) }}</div>
                        </div>
                    @endif
                </div>
                <div class="flex space-x-2">
                    <!-- Send and Pay buttons moved to cart modal -->
                </div>
            </div>
        </div>

        <!-- Mobile POS Bar - Fixed at bottom -->
        <div class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 py-3 fixed bottom-0 left-0 right-0 z-25">
            <div class="flex items-center justify-between">
                <!-- Table Selector -->
                <select wire:model.live="selectedTableId"
                    class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 font-bold">
                    <option value="">Table</option>
                    @foreach($tables as $table)
                        @php
                            $hasActiveOrder = $table->orders->isNotEmpty();
                            $isOccupied = $table->status === 'occupied' && $hasActiveOrder;
                        @endphp
                        <option value="{{ $table->id }}" class="{{ $isOccupied ? 'text-red-600' : 'text-green-600' }}">
                            {{ $table->name }}</option>
                    @endforeach
                </select>
                <!-- Cart Toggle -->
                <button wire:click="$toggle('showCart')" class="relative p-2 bg-blue-600 text-white rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.1 5H19M7 13v8a2 2 0 002 2h10a2 2 0 002-2v-3"></path>
                    </svg>
                    @if(!empty($cart) || !empty($existingItems))
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                            {{ count($cart) + count($existingItems) }}
                        </span>
                    @endif
                </button>
            </div>
        </div>

        <!-- Mobile Cart Sidebar -->
        @if(isset($showCart) && $showCart)
            <div class="fixed inset-0 bg-black bg-opacity-50 z-50 flex">
                <div class="ml-auto w-80 bg-white dark:bg-gray-900 h-full flex flex-col">
                    <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">🛒 Cart</h3>
                        <button wire:click="$toggle('showCart')" class="text-gray-400 hover:text-red-500 p-2">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-4 space-y-3">
                        @if(!empty($existingItems))
                            <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2">Existing Items</h4>
                            @foreach($existingItems as $id => $item)
                                <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2 opacity-75">
                                    <div class="flex-1">
                                        <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                                    </div>
                                    <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                                </div>
                            @endforeach
                        @endif

                        @if(!empty($cart))
                            <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 {{ !empty($existingItems) ? 'mt-4' : '' }}">New Items</h4>
                            @foreach($cart as $id => $item)
                                <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                                    <div class="flex-1">
                                        <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x {{ $item['quantity'] }}</div>
                                    </div>
                                    <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                                    <button wire:click="removeFromCart({{ $id }})" class="ml-3 text-red-500 hover:text-red-700 touch-manipulation p-1">
                                        <span class="text-lg">×</span>
                                    </button>
                                </div>
                            @endforeach
                        @endif

                        @if(empty($cart) && empty($existingItems))
                            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                                <div class="text-4xl mb-2">🛒</div>
                                <div>Your cart is empty</div>
                                <div class="text-sm">Tap on products to add them</div>
                            </div>
                        @endif
                    </div>

                    @if(!empty($cart) || !empty($existingItems))
                        <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                            <div class="flex justify-between text-xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                                <span>Total:</span><span>₦{{ number_format($total) }}</span>
                            </div>
                            <div class="grid grid-cols-3 gap-3">
                                <button wire:click="checkout('update')"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Order
                                </button>
                                <button wire:click="openPaymentModal"
                                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Pay
                                </button>
                                <button wire:click="cancelOrder"
                                    class="bg-red-600 hover:bg-red-700 text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if($showPaymentModal)
        <div class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700 relative max-h-[90vh] overflow-y-auto">

                <div
                    class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">💰 Checkout</h3>
                    <button wire:click="$set('showPaymentModal', false)" class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                            class="text-2xl">&times;</span></button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="text-center mb-6">
                        <div class="text-sm text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Total Due
                        </div>
                        <div class="text-3xl lg:text-4xl font-black text-gray-900 dark:text-white">₦{{ number_format($total) }}</div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Amount Received</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-lg">₦</span>
                            <input type="number" wire:model.live="paidAmount" inputmode="decimal"
                                class="w-full pl-8 pr-4 py-4 text-xl font-bold border rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white touch-manipulation"
                                placeholder="0.00">
                        </div>
                    </div>

                    @php $balance = $total - (float) $paidAmount; @endphp

                    @if($balance < 0)
                        <div
                            class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border border-green-200 dark:border-green-800 text-center">
                            <span class="text-green-700 dark:text-green-400 font-bold text-sm">Change:</span>
                            <div class="text-2xl font-black text-green-600 dark:text-green-400">
                                ₦{{ number_format(abs($balance)) }}</div>
                        </div>
                    @elseif($balance > 0)
                        <div
                            class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg border border-red-200 dark:border-red-800 text-center animate-pulse">
                            <span class="text-red-700 dark:text-red-400 font-bold text-sm">⚠️ Remaining Debt:</span>
                            <div class="text-2xl font-black text-red-600 dark:text-red-400">₦{{ number_format($balance) }}</div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-red-600 mb-1">Select Guest for Debt *</label>
                            <div class="flex gap-2">
                                <select wire:model="selectedGuestId"
                                    class="w-full p-3 text-base border border-red-300 rounded-lg dark:bg-gray-800 dark:border-red-900 touch-manipulation">
                                    <option value="">-- Select Guest --</option>
                                    @foreach(\App\Models\Guest::all() as $guest)
                                        <option value="{{ $guest->id }}">{{ $guest->name }}</option>
                                    @endforeach
                                </select>
                                <button wire:click="$set('showGuestModal', true)"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-xl font-bold flex items-center justify-center touch-manipulation">
                                    +
                                </button>
                            </div>
                            @error('selectedGuestId') <span class="text-xs text-red-600 font-bold">{{ $message }}</span>
                            @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach(['cash' => '💵 Cash', 'pos' => '💳 POS', 'transfer' => '🏦 Transfer'] as $key => $label)
                                <button wire:click="$set('paymentMethod', '{{ $key }}')"
                                    class="p-3 border rounded-lg font-bold text-sm transition-colors touch-manipulation {{ $paymentMethod === $key ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3 sticky bottom-0 bg-white dark:bg-gray-900">
                    <button wire:click="$set('showPaymentModal', false)"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 touch-manipulation">Cancel</button>
                    <button wire:click="processPayment"
                        class="px-4 py-3 font-bold text-white bg-green-600 rounded-lg hover:bg-green-700 shadow-lg shadow-green-600/30 flex items-center justify-center gap-2 touch-manipulation"><span>Confirm</span></button>
                </div>
            </div>
        </div>
    @endif

    @if($showGuestModal)
        <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden border border-gray-200 dark:border-gray-700">
                <div
                    class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">👤 Add New Guest</h3>
                    <button wire:click="$set('showGuestModal', false)" class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                            class="text-2xl">&times;</span></button>
                </div>

                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Full Name *</label>
                        <input type="text" wire:model="newGuestName" inputmode="text"
                            class="w-full p-3 text-base border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 touch-manipulation"
                            placeholder="e.g. Mr. John Doe">
                        @error('newGuestName') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Phone Number</label>
                        <input type="tel" wire:model="newGuestPhone" inputmode="tel"
                            class="w-full p-3 text-base border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 touch-manipulation"
                            placeholder="e.g. 08012345678">
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                    <button wire:click="$set('showGuestModal', false)"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg touch-manipulation">Cancel</button>
                    <button wire:click="saveNewGuest"
                        class="px-4 py-3 font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700 touch-manipulation">Save Guest</button>
                </div>
            </div>
        </div>
    @endif
</div>