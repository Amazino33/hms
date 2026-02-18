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
    public $deferProducts = true; // defer loading heavy product data until after initial render

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

    // 👇 NEW: Cancellation Reason Properties
    public $showCancelModal = false;
    public $cancellationReason = '';

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
                ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
                ->with('items.product')
                ->get();

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    if (isset($this->existingItems[$item->product_id ?: $item->id])) {
                        $this->existingItems[$item->product_id ?: $item->id]['quantity'] += $item->quantity;
                    } else {
                        $this->existingItems[$item->product_id ?: $item->id] = [
                            'id' => $item->product_id ?: $item->menu_item_id,
                            'name' => $item->product_name,
                            'price' => $item->unit_price,
                            'quantity' => $item->quantity,
                            'image' => $item->product ? $item->product->image : null,
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

    public function loadCurrentShift()
    {
        // This method exists to enable polling for shift status updates
        // The actual shift data is accessed via auth()->user()->currentShift() in the view
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

    public function loadProducts()
    {
        // Called by `wire:init` to trigger the deferred load in `with()`
        $this->deferProducts = false;
    }

    public function addToCart($itemId, $itemType = 'product')
    {
        // Check if user has an active shift
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before adding items to cart.')->danger()->send();
            return;
        }

        if ($itemType === 'product') {
            $product = Product::with('category')->find($itemId);

            // Determine warehouse based on user role (same logic as ReceiveTransfers page)
            $user = auth()->user();
            $warehouseId = null;

            if ($user->hasRole('bartender')) {
                // Find the bar warehouse (consumer type, typically the first consumer warehouse)
                $barWarehouse = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->first();
                if ($barWarehouse) {
                    $warehouseId = $barWarehouse->id;
                }
            } elseif ($user->hasRole('chef')) {
                // Find the kitchen warehouse (consumer type, typically the second consumer warehouse)
                $consumerWarehouses = \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->get();
                if ($consumerWarehouses->count() > 1) {
                    $kitchenWarehouse = $consumerWarehouses[1]; // Second consumer warehouse
                    $warehouseId = $kitchenWarehouse->id;
                } elseif ($consumerWarehouses->count() == 1) {
                    // If only one consumer warehouse, use it for chef
                    $warehouseId = $consumerWarehouses[0]->id;
                }
            } else {
                // For other roles, use the appropriate warehouse based on product category
                $warehouseId = match(true) {
                    $product && $product->category && $product->category->type === 'drink' => 
                        \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->first()?->id ?? 3,
                    $product && $product->category && $product->category->type === 'food' => 
                        \App\Models\WareHouse::where('type', 'consumer')->orderBy('id')->skip(1)->first()?->id ?? 5,
                    default => 3,
                };
            }

            // If no warehouse found, default to storage warehouse
            if (!$warehouseId) {
                $warehouseId = 3;
            }

            // Check stock availability in the specific warehouse that will be used for deduction
            $available = (int) DB::table('inventory_items')
                ->where('product_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity') ?? 0;

            $currentQty = isset($this->cart[$itemId]) ? $this->cart[$itemId]['quantity'] : 0;
            if ($available <= $currentQty) {
                Notification::make()->title('Out of Stock')->body("Only {$available} available in stock.")->danger()->send();
                return;
            }

            if (isset($this->cart[$itemId])) {
                $this->cart[$itemId]['quantity']++;
            } else {
                $this->cart[$itemId] = [
                    'name' => $product->name,
                    'price' => $product->price,
                    'quantity' => 1,
                    'type' => 'product',
                ];
            }
        } elseif ($itemType === 'menu_item') {
            $menuItem = \App\Models\MenuItem::find($itemId);
            if (!$menuItem) {
                $allMenuItems = \App\Models\MenuItem::all()->pluck('name', 'id');
                Notification::make()
                    ->title('Menu Item Not Found')
                    ->body('The selected menu item (ID: ' . $itemId . ') could not be found. Available menu items: ' . $allMenuItems->toJson())
                    ->danger()
                    ->send();
                return;
            }

            // Check ingredient availability for current quantity + 1
            $cartKey = 'menu_' . $itemId;
            $currentQty = isset($this->cart[$cartKey]) ? $this->cart[$cartKey]['quantity'] : 0;
            $insufficientIngredients = \App\Services\InventoryService::checkMenuItemIngredientsAvailability($itemId, $currentQty + 1);
            if (!empty($insufficientIngredients)) {
                $messages = [];
                foreach ($insufficientIngredients as $insufficient) {
                    $messages[] = "{$insufficient['ingredient']}: {$insufficient['available']} {$insufficient['unit']} available, need {$insufficient['required']}";
                }
                Notification::make()
                    ->title('Insufficient Ingredients')
                    ->body('Cannot add menu item: ' . implode('; ', $messages))
                    ->danger()
                    ->send();
                return;
            }

            $cartKey = 'menu_' . $itemId;
            if (isset($this->cart[$cartKey])) {
                $this->cart[$cartKey]['quantity']++;
            } else {
                $this->cart[$cartKey] = [
                    'name' => $menuItem->name,
                    'price' => $menuItem->sale_price,
                    'quantity' => 1,
                    'type' => 'menu_item',
                    'menu_item_id' => $itemId,
                ];
            }
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
        // Check if user has an active shift
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before processing payments.')->danger()->send();
            return;
        }

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
        $existingOrders = Order::where('table_id', $tableId)->whereIn('status', ['pending', 'preparing', 'ready', 'served'])->with('items')->get();

        // Preserve the original waiter user_id from existing orders so commission
        // is credited to the waiter, not the cashier processing payment.
        $waiterUserId = $existingOrders->first()?->user_id ?? auth()->id();

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
        try {
            $splitter = new OrderSplitter();
            $orders = $splitter->handle($allItems, $tableId, $waiterUserId, [
                'amount_paid' => $this->paidAmount,
                'payment_method' => $this->paymentMethod,
                'status' => $orderStatus,
                'guest_id' => $this->selectedGuestId,
                'processed_by_user_id' => auth()->id(),
            ]);
        } catch (\Exception $e) {
            // Handle inventory/stock errors
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()
                    ->title('Stock Error')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
                return;
            }
            // Re-throw other exceptions
            throw $e;
        }

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
        $this->updateTotal(); // Recalculate total after clearing cart
        $this->currentOrderId = null;
        $this->selectedTableId = null;
        $this->paidAmount = 0;
        $this->selectedGuestId = null;
        $this->showCart = false; // Close cart on mobile after payment
        
        // Clear product cache to refresh inventory display
        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);
    }

    // --- STANDARD CHECKOUT (Send to Kitchen) ---
    public function checkout($action = 'update')
    {
        // Check if user has an active shift
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before sending orders to kitchen.')->danger()->send();
            return;
        }

        if (empty($this->cart)) return;
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }
        $tableId = $this->selectedTableId;
        $tableName = \App\Models\Table::find($tableId)?->name ?? 'Unknown';

        // Use OrderSplitter to create separate orders for 'update' checkout
        try {
            $splitter = new OrderSplitter();
            $orders = $splitter->handle($this->cart, $tableId, auth()->id(), [
                'status' => 'pending',
                'payment_method' => 'cash',
            ]);
        } catch (\Exception $e) {
            // Handle inventory/stock errors
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()
                    ->title('Stock Error')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();
                return;
            }
            // Re-throw other exceptions
            throw $e;
        }

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
        
        // Clear product cache to refresh inventory display
        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);
        
        Notification::make()->title('Order Updated')->success()->send();
    }

    public function cancelOrder()
    {
        // Check if user has an active shift
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before canceling orders.')->danger()->send();
            return;
        }

        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }

        $tableId = $this->selectedTableId;

        // Find all unpaid orders for this table
        $orders = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->get();

        if ($orders->isEmpty()) {
            Notification::make()->title('No active orders to cancel')->warning()->send();
            return;
        }

        // Show cancellation reason modal instead of directly cancelling
        $this->showCancelModal = true;
    }

    public function confirmCancelOrder()
    {
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }

        if (empty($this->cancellationReason)) {
            Notification::make()->title('Cancellation reason is required')->warning()->send();
            return;
        }

        $tableId = $this->selectedTableId;

        // Find all unpaid orders for this table
        $orders = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->get();

        if ($orders->isEmpty()) {
            Notification::make()->title('No active orders to cancel')->warning()->send();
            $this->showCancelModal = false;
            $this->cancellationReason = '';
            return;
        }

        // Update order statuses to cancelled with reason
        foreach ($orders as $order) {
            $order->update([
                'status' => 'cancelled',
                'cancellation_reason' => $this->cancellationReason
            ]);
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

        // Clear product cache to refresh inventory display
        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);

        // Close modal and reset
        $this->showCancelModal = false;
        $this->cancellationReason = '';

        Notification::make()->title('Order Cancelled')->body('Reason: ' . $this->cancellationReason)->success()->send();
    }

    public function cancelCancelModal()
    {
        $this->showCancelModal = false;
        $this->cancellationReason = '';
    }

    public function printBill()
    {
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }

        if (empty($this->existingItems) && empty($this->cart)) {
            Notification::make()->title('No items to print')->warning()->send();
            return;
        }

        $table = \App\Models\Table::find($this->selectedTableId);
        $allItems = array_merge($this->existingItems, $this->cart);

        $this->dispatch('print-bill', [
            'tableName' => $table?->name ?? 'Table',
            'items'     => array_values($allItems),
            'total'     => $this->total,
            'date'      => now()->format('M j, Y g:i A'),
            'cashier'   => auth()->user()->name,
        ]);
    }

    public function with()
    {
        // Deferred load: return empty collections on first render to keep initial HTML small.
        if ($this->deferProducts) {
            return [
                'products' => collect(),
                'menuItems' => collect(),
            ];
        }

        $cacheKey = 'products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search;

        $products = Cache::remember($cacheKey, 1800, function () {
            $query = Product::where('is_active', true)
                ->with(['inventory.warehouse', 'category']);
            
            if (!empty($this->search))
                $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));
            elseif ($this->activeCategoryId)
                $query->where('category_id', $this->activeCategoryId);

            $products = $query->limit(100)->get();

            // Add available stock based on warehouse logic
            foreach ($products as $product) {
                $warehouseId = match(true) {
                    $product->category && $product->category->type === 'drink' => 4,
                    $product->category && $product->category->type === 'food' => 5,
                    default => 3,
                };
                
                $product->available_stock = $product->inventory->where('warehouse_id', $warehouseId)->sum('quantity');
            }

            return $products;
        });

        // Get menu items
        $menuItemsCacheKey = 'menu_items_' . $this->search;
        $menuItems = Cache::remember($menuItemsCacheKey, 1800, function () {
            $query = \App\Models\MenuItem::where('available_for_sale', true)
                ->with(['recipes.ingredient']);
            
            if (!empty($this->search))
                $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));

            return $query->limit(100)->get();
        });

        return [
            'products' => $products,
            'menuItems' => $menuItems
        ];
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

<div class="min-h-screen bg-gray-50 dark:bg-gray-900"
     x-data="{}"
     @print-bill.window="printPOSBill($event.detail[0] ?? $event.detail)">
    <!-- Shift Status Indicator -->
    <div wire:poll.10s="loadCurrentShift" class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                @if(auth()->user()->currentShift())
                    <div class="flex items-center space-x-2">
                        <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
                        <span class="text-sm font-medium text-green-700 dark:text-green-300">Shift Active</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Started: {{ auth()->user()->currentShift()->started_at->format('g:i A') }}
                        </span>
                    </div>
                @else
                    <div class="flex items-center space-x-2">
                        <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                        <span class="text-sm font-medium text-red-700 dark:text-red-300">No Active Shift</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Start a shift to process sales</span>
                    </div>
                @endif
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ now()->format('M j, Y g:i A') }}
            </div>
        </div>
    </div>

    <!-- Desktop Layout (Hidden on Mobile) -->
    <div class="hidden lg:block">
        <div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
            <div class="col-span-8 flex flex-col h-full bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 lg:m-0 lg:relative">
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search Item Name or Barcode..."
                            class="w-full px-4 py-3 pl-12 text-base lg:text-lg border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm {{ auth()->user()->currentShift() ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }}"
                            {{ auth()->user()->currentShift() ? 'autofocus' : 'disabled' }}>
                    </div>
                </div>
                <div class="flex overflow-x-auto overflow-y-hidden p-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 space-x-2 flex-nowrap">
                    @foreach($categories as $category)
                        <button @if(auth()->user()->currentShift()) wire:click="$set('activeCategoryId', {{ $category->id }})" @endif
                            class="px-3 py-2 lg:px-4 rounded-lg text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                            {{ auth()->user()->currentShift() ? '' : 'disabled' }}>{{ $category->name }}</button>
                    @endforeach
                </div>
                <div wire:init="loadProducts" class="flex-1 overflow-y-auto p-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-4 content-start relative">
                    @if(!auth()->user()->currentShift())
                        <div class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div class="w-8 h-8 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Start shift to add items</p>
                            </div>
                        </div>
                    @endif

                    @if($products->isEmpty())
                        @for($i = 0; $i < 8; $i++)
                            <div class="animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center h-28 lg:h-32"></div>
                        @endfor
                    @endif

                    @foreach($products as $product)
                        <div @if(auth()->user()->currentShift()) wire:click="addToCart({{ $product->id }}, 'product')" @endif
                            class="relative {{ auth()->user()->currentShift() ? 'cursor-pointer hover:border-amber-500 hover:shadow-md' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">{{ $product->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">₦{{ number_format($product->price) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                            </div>
                        </div>
                    @endforeach
                    @foreach($menuItems as $menuItem)
                        <div @if(auth()->user()->currentShift()) wire:click="addToCart({{ $menuItem->id }}, 'menu_item')" @endif
                            class="relative {{ auth()->user()->currentShift() ? 'cursor-pointer hover:border-amber-500 hover:shadow-md' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">{{ $menuItem->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">₦{{ number_format($menuItem->sale_price) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Menu Item
                            </div>
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
                <div class="flex-1 overflow-y-auto p-4 space-y-3 relative">
                    @if(!auth()->user()->currentShift())
                        <div class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div class="w-8 h-8 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Cart disabled - start shift</p>
                            </div>
                        </div>
                    @endif
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
                            <button @if(auth()->user()->currentShift()) wire:click="removeFromCart({{ $id }})" @endif class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1"><span class="text-lg">×</span></button>
                        </div>
                    @endforeach
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between text-xl lg:text-2xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                        <span>Total:</span><span>₦{{ number_format($total) }}</span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <button @if(auth()->user()->currentShift()) wire:click="checkout('update')" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Order</span></button>
                        <button @if(auth()->user()->currentShift()) wire:click="openPaymentModal" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Pay</span></button>
                        <button @if(auth()->user()->currentShift()) wire:click="cancelOrder" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Cancel</span></button>
                    </div>
                    @if(auth()->user()->hasAnyRole(['cashier', 'super_admin', 'manager']))
                    <button @if(auth()->user()->currentShift() && (!empty($existingItems) || !empty($cart))) wire:click="printBill" @endif
                        class="{{ auth()->user()->currentShift() && (!empty($existingItems) || !empty($cart)) ? 'bg-amber-500 hover:bg-amber-600 cursor-pointer' : 'bg-gray-300 dark:bg-gray-600 cursor-not-allowed' }} w-full text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center gap-2 touch-manipulation transition-colors mt-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <span class="text-sm">Print Unpaid Bill</span>
                    </button>
                    @endif
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
                    class="w-full px-4 py-3 pl-12 text-base border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm {{ auth()->user()->currentShift() ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }}"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                @if($search)
                    <button @if(auth()->user()->currentShift()) wire:click="clearSearch" @endif class="absolute right-3 top-1/2 -translate-y-1/2 {{ auth()->user()->currentShift() ? 'text-gray-400 hover:text-gray-600 cursor-pointer' : 'text-gray-300 cursor-not-allowed' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        <!-- Mobile Categories - Fixed -->
        <div class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 fixed top-[137px] left-0 right-0 z-20">
            <div class="flex overflow-x-auto overflow-y-hidden p-3 space-x-2 flex-nowrap">
                <button @if(auth()->user()->currentShift()) wire:click="$set('activeCategoryId', null)" @endif
                    class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ !$activeCategoryId ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                    All
                </button>
                @foreach($categories as $category)
                    <button @if(auth()->user()->currentShift()) wire:click="$set('activeCategoryId', {{ $category->id }})" @endif
                        class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                        {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>
        </div>

        <!-- Mobile Products Grid - Scrollable -->
        <div class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 mt-[50px] mb-[120px] relative">
            @if(!auth()->user()->currentShift())
                <div class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-3 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                        <div class="w-6 h-6 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                            <svg class="w-3 h-3 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                        </div>
                        <p class="text-xs font-medium text-gray-900 dark:text-white">Start shift to add items</p>
                    </div>
                </div>
            @endif
            <div wire:init="loadProducts" class="grid grid-cols-2 gap-3">
                @if($products->isEmpty())
                    @for($i = 0; $i < 8; $i++)
                        <div class="animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center h-28"></div>
                    @endfor
                @endif

                @foreach($products as $product)
                    <div @if(auth()->user()->currentShift()) wire:click="addToCart({{ $product->id }}, 'product')" @endif
                        class="relative {{ auth()->user()->currentShift() ? 'hover:border-amber-500 active:scale-95' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation">
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">{{ $product->name }}</div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">₦{{ number_format($product->price) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                        </div>
                    </div>
                @endforeach
                @foreach($menuItems as $menuItem)
                    <div @if(auth()->user()->currentShift()) wire:click="addToCart({{ $menuItem->id }}, 'menu_item')" @endif
                        class="relative {{ auth()->user()->currentShift() ? 'hover:border-amber-500 active:scale-95' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation">
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">{{ $menuItem->name }}</div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">₦{{ number_format($menuItem->sale_price) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Menu Item</div>
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
                <select @if(auth()->user()->currentShift()) wire:model.live="selectedTableId" @endif
                    class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg {{ auth()->user()->currentShift() ? 'bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 cursor-pointer' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }} font-bold"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
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

                    <div class="flex-1 overflow-y-auto p-4 space-y-3 relative">
                        @if(!auth()->user()->currentShift())
                            <div class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-3 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                    <div class="w-6 h-6 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                        <svg class="w-3 h-3 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                                        </svg>
                                    </div>
                                    <p class="text-xs font-medium text-gray-900 dark:text-white">Cart disabled</p>
                                </div>
                            </div>
                        @endif
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
                                    <button @if(auth()->user()->currentShift()) wire:click="removeFromCart({{ $id }})" @endif class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1">
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
                                <button @if(auth()->user()->currentShift()) wire:click="checkout('update')" @endif
                                    class="{{ auth()->user()->currentShift() ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Order
                                </button>
                                <button @if(auth()->user()->currentShift()) wire:click="openPaymentModal" @endif
                                    class="{{ auth()->user()->currentShift() ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Pay
                                </button>
                                <button @if(auth()->user()->currentShift()) wire:click="cancelOrder" @endif
                                    class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                                    Cancel
                                </button>
                            </div>
                            @if(auth()->user()->hasAnyRole(['cashier', 'super_admin', 'manager']))
                            <button @if(auth()->user()->currentShift() && (!empty($existingItems) || !empty($cart))) wire:click="printBill" @endif
                                class="{{ auth()->user()->currentShift() && (!empty($existingItems) || !empty($cart)) ? 'bg-amber-500 hover:bg-amber-600 cursor-pointer' : 'bg-gray-300 dark:bg-gray-600 cursor-not-allowed' }} w-full text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation flex items-center justify-center gap-2 mt-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                Print Unpaid Bill
                            </button>
                            @endif
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

    {{-- Print Bill JS --}}
    <script>
    window.printPOSBill = function printPOSBill(d) {
        const win = window.open('', '_blank', 'width=440,height=680,scrollbars=yes,resizable=yes');
        if (!win) { alert('Please allow pop-ups to print the bill.'); return; }
        const rows = (d.items || []).map(i =>
            `<tr><td style="padding:3px 6px;">${i.name}</td><td style="text-align:center;padding:3px 6px;">${i.quantity}</td><td style="text-align:right;padding:3px 6px;">&#8358;${Number(i.price * i.quantity).toLocaleString()}</td></tr>`
        ).join('');
        win.document.write(`<!DOCTYPE html>
<html><head><title>Unpaid Bill – ${d.tableName}</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Courier New', monospace; font-size:13px; width:80mm; padding:10px; color:#000; }
  h1 { text-align:center; font-size:16px; letter-spacing:2px; margin-bottom:2px; }
  .sub { text-align:center; font-size:11px; margin-bottom:4px; }
  .dashed { border-top:1px dashed #000; margin:6px 0; }
  .meta { font-size:12px; margin-bottom:2px; }
  table { width:100%; border-collapse:collapse; }
  th { text-align:left; font-size:11px; border-bottom:1px solid #000; padding:2px 6px; }
  .total-row { font-size:15px; font-weight:bold; text-align:right; margin-top:8px; }
  .footer { text-align:center; font-size:10px; margin-top:10px; color:#555; }
  @media print {
    body { width:auto; }
    button { display:none; }
  }
</style>
</head>
<body>
  <h1>HMS RECEIPT</h1>
  <div class="sub">*** UNPAID BILL ***</div>
  <div class="dashed"></div>
  <div class="meta">Table : <strong>${d.tableName}</strong></div>
  <div class="meta">Date  : ${d.date}</div>
  <div class="meta">Staff : ${d.cashier}</div>
  <div class="dashed"></div>
  <table>
    <thead><tr><th>Item</th><th style="text-align:center;">Qty</th><th style="text-align:right;">Amount</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>
  <div class="dashed"></div>
  <div class="total-row">TOTAL: &#8358;${Number(d.total).toLocaleString()}</div>
  <div class="dashed"></div>
  <div class="footer">Thank you for dining with us!<br>This is NOT a payment receipt.</div>
</body></html>`);
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); }, 600);
    }
    </script>

    @if($showCancelModal)
        <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700">
                <div
                    class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">❌ Cancel Order</h3>
                    <button wire:click="cancelCancelModal" class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                            class="text-2xl">&times;</span></button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="text-center mb-4">
                        <div class="text-red-600 dark:text-red-400 font-bold text-lg">⚠️ This action cannot be undone</div>
                        <div class="text-gray-600 dark:text-gray-400 text-sm">All active orders for this table will be cancelled</div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Cancellation Reason *</label>
                        <textarea wire:model="cancellationReason"
                            class="w-full p-3 text-base border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 touch-manipulation resize-none"
                            rows="3"
                            placeholder="Please provide a reason for cancelling this order..."></textarea>
                        @error('cancellationReason') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                    <button wire:click="cancelCancelModal"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg touch-manipulation">Keep Order</button>
                    <button wire:click="confirmCancelOrder"
                        class="px-4 py-3 font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 touch-manipulation flex items-center justify-center gap-2">
                        <span>Cancel Order</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>