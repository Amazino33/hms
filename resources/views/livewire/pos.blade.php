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
    public $existingItems = [];
    public $existingTotal = 0; // sum of existingItems, synced to Alpine
    public $search = '';
    public $deferProducts = true; // defer loading heavy product data until after initial render

    // Payment Properties (guest/debt remain server-side)
    public $selectedGuestId = null;

    // Guest Creation Properties
    public $showGuestModal = false;
    public $newGuestName = '';
    public $newGuestPhone = '';

    // Cancellation Reason Properties
    public $showCancelModal = false;
    public $cancellationReason = '';

    public function clearSearch()
    {
        $this->search = '';
    }

    public function updatedSelectedTableId($value)
    {
        $this->selectedTableId = $value;
        $this->existingItems = [];
        $this->existingTotal = 0;
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

            $this->existingTotal = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity']);
            if ($orders->isNotEmpty()) {
                Notification::make()->title('Order Resumed')->info()->send();
            }
        }
        // If table is free, existingItems remains empty
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

    /**
     * Validate whether an item can be added to the local Alpine cart.
     * Does NOT mutate Livewire state — so Livewire skips DOM diffing on success.
     * Returns ['ok' => true, 'item' => [...]] or ['ok' => false].
     */
    public function validateAndAddToCart(int $itemId, string $itemType, int $currentQty = 0): array
    {
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before adding items to cart.')->danger()->send();
            return ['ok' => false];
        }

        if ($itemType === 'product') {
            $product = Product::with('category')->find($itemId);

            // Sum stock across ALL consumer warehouses for this product.
            // Avoids the fragile orderBy('id') bar/kitchen swap that breaks on each environment.
            $consumerWarehouseIds = Cache::remember('consumer_warehouse_ids', 3600, fn() =>
                \App\Models\WareHouse::where('type', 'consumer')->pluck('id')
            );
            $available = (int) DB::table('inventory_items')
                ->where('product_id', $itemId)
                ->whereIn('warehouse_id', $consumerWarehouseIds)
                ->sum('quantity');

            if ($available <= $currentQty) {
                Notification::make()->title('Out of Stock')->body("Only {$available} available in stock.")->danger()->send();
                return ['ok' => false];
            }

            return ['ok' => true, 'item' => [
                'name'  => $product->name,
                'price' => (float) $product->price,
                'type'  => 'product',
            ]];
        }

        if ($itemType === 'menu_item') {
            $menuItem = \App\Models\MenuItem::find($itemId);
            if (!$menuItem) {
                Notification::make()->title('Menu Item Not Found')->body("ID: {$itemId}")->danger()->send();
                return ['ok' => false];
            }

            $insufficientIngredients = \App\Services\InventoryService::checkMenuItemIngredientsAvailability($itemId, $currentQty + 1);
            if (!empty($insufficientIngredients)) {
                $messages = collect($insufficientIngredients)->map(fn($i) => "{$i['ingredient']}: {$i['available']} {$i['unit']} available, need {$i['required']}")->join('; ');
                Notification::make()->title('Insufficient Ingredients')->body($messages)->danger()->send();
                return ['ok' => false];
            }

            return ['ok' => true, 'item' => [
                'name'         => $menuItem->name,
                'price'        => (float) $menuItem->sale_price,
                'type'         => 'menu_item',
                'menu_item_id' => $itemId,
            ]];
        }

        return ['ok' => false];
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

    public function processPayment(array $cartItems, float $paidAmount, string $paymentMethod, ?int $guestId = null)
    {
        // Check if user has an active shift
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before processing payments.')->danger()->send();
            return;
        }

        if (!auth()->check()) {
            Notification::make()->title('Authentication Required')->danger()->send();
            return;
        }

        $total = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity'])
               + collect($cartItems)->sum(fn($i) => ($i['price'] ?? 0) * ($i['qty'] ?? $i['quantity'] ?? 1));

        if ($total <= 0) {
            Notification::make()->title('Cart is empty')->warning()->send();
            return;
        }

        if ($paidAmount < $total && empty($guestId)) {
            Notification::make()->title('Select a Guest for Debt')->warning()->send();
            return;
        }

        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table or Take Away')->warning()->send();
            return;
        }

        $isTakeaway = $this->selectedTableId === 'takeaway';
        $tableId = $isTakeaway ? null : (int) $this->selectedTableId;
        $orderStatus = ($paidAmount >= $total) ? 'paid' : 'partial';

        // Restore old stock & delete previous orders only when a table is involved
        $waiterUserId = auth()->id();
        if (!$isTakeaway && $tableId) {
            $existingOrders = Order::where('table_id', $tableId)->whereIn('status', ['pending', 'preparing', 'ready', 'served'])->with('items')->get();
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
        }

        // Prepare all items for OrderSplitter (combine existing and new)
        $allItems = $this->existingItems;
        foreach ($cartItems as $productId => $item) {
            $qty = $item['qty'] ?? $item['quantity'] ?? 1;
            $normalizedItem = [
                'name'     => $item['name'],
                'price'    => $item['price'],
                'quantity' => $qty,
                'type'     => $item['type'] ?? 'product',
            ];
            if (!empty($item['menu_item_id'])) {
                $normalizedItem['menu_item_id'] = $item['menu_item_id'];
            }
            if (isset($allItems[$productId])) {
                $allItems[$productId]['quantity'] += $qty;
            } else {
                $allItems[$productId] = $normalizedItem;
            }
        }

        try {
            $splitter = new OrderSplitter();
            $orders = $splitter->handle($allItems, $tableId, $waiterUserId, [
                'amount_paid'           => $paidAmount,
                'payment_method'        => $paymentMethod,
                'status'                => $orderStatus,
                'guest_id'              => $guestId,
                'processed_by_user_id'  => auth()->id(),
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()->title('Stock Error')->body($e->getMessage())->danger()->send();
                return;
            }
            throw $e;
        }

        if ($paidAmount > 0 && !empty($orders)) {
            \App\Models\OrderPayment::create([
                'order_id' => $orders[0]->id,
                'amount'   => $paidAmount,
                'method'   => $paymentMethod,
                'user_id'  => auth()->id(),
                'shift_id' => auth()->user()?->currentShift()?->id,
                'paid_at'  => now(),
            ]);
        }

        if ($tableId) {
            \App\Models\Table::find($tableId)->update(['status' => 'available']);
            $this->loadTables();
        }

        $balance = $total - $paidAmount;
        $msg = $orderStatus === 'paid' ? "Paid: ₦" . number_format($paidAmount) : "Debt Recorded: ₦" . number_format($balance);
        Notification::make()->title($msg)->success()->send();

        // Reset server-side state (Alpine resets its own state after the await resolves)
        $this->existingItems = [];
        $this->existingTotal = 0;
        $this->currentOrderId = null;
        $this->selectedTableId = null;
        $this->selectedGuestId = null;

        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);
    }

    // --- STANDARD CHECKOUT (Send to Kitchen) ---
    public function checkout(array $cartItems, string $action = 'update')
    {
        if (!auth()->user()->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before sending orders to kitchen.')->danger()->send();
            return;
        }

        if (empty($cartItems)) return;
        if (!$this->selectedTableId || $this->selectedTableId === 'takeaway') {
            Notification::make()->title('Please select a table to send orders to the kitchen')->warning()->send();
            return;
        }
        $tableId = $this->selectedTableId;

        // Normalize qty → quantity for OrderSplitter
        $normalized = [];
        foreach ($cartItems as $key => $item) {
            $norm = [
                'name'     => $item['name'],
                'price'    => $item['price'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 1,
                'type'     => $item['type'] ?? 'product',
            ];
            if (!empty($item['menu_item_id'])) {
                $norm['menu_item_id'] = $item['menu_item_id'];
            }
            $normalized[$key] = $norm;
        }

        try {
            $splitter = new OrderSplitter();
            $splitter->handle($normalized, $tableId, auth()->id(), [
                'status'         => 'pending',
                'payment_method' => 'cash',
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()->title('Stock Error')->body($e->getMessage())->danger()->send();
                return;
            }
            throw $e;
        }

        \App\Models\Table::find($tableId)->update(['status' => 'occupied']);

        // Merge new items into existingItems so they render as grayed-out server-side
        foreach ($normalized as $key => $item) {
            if (isset($this->existingItems[$key])) {
                $this->existingItems[$key]['quantity'] += $item['quantity'];
            } else {
                $this->existingItems[$key] = ['id' => $key, 'name' => $item['name'], 'price' => $item['price'], 'quantity' => $item['quantity'], 'image' => null];
            }
        }
        $this->existingTotal = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity']);

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
        $orders = ($tableId && $tableId !== 'takeaway')
            ? Order::where('table_id', $tableId)->whereIn('status', ['pending', 'preparing', 'ready', 'served'])->get()
            : collect();

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

        // Set table status to available (only for real tables)
        if ($tableId && $tableId !== 'takeaway') {
            \App\Models\Table::find($tableId)?->update(['status' => 'available']);
        }

        // Clear server-side state
        $this->existingItems = [];
        $this->existingTotal = 0;
        $this->currentOrderId = null;

        // Reload tables to reflect status change
        $this->loadTables();

        // Clear product cache to refresh inventory display
        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);

        $reason = $this->cancellationReason;

        // Close modal and reset
        $this->showCancelModal = false;
        $this->cancellationReason = '';

        // Tell Alpine to clear its local cart
        $this->dispatch('order-cancelled');

        Notification::make()->title('Order Cancelled')->body('Reason: ' . $reason)->success()->send();
    }

    public function cancelCancelModal()
    {
        $this->showCancelModal = false;
        $this->cancellationReason = '';
    }

    public function printBill(array $cartItems = [])
    {
        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table first')->warning()->send();
            return;
        }

        if (empty($this->existingItems) && empty($cartItems)) {
            Notification::make()->title('No items to print')->warning()->send();
            return;
        }

        $isTakeaway = $this->selectedTableId === 'takeaway';
        $table = $isTakeaway ? null : \App\Models\Table::find($this->selectedTableId);
        $tableName = $isTakeaway ? 'Take Away' : ($table?->name ?? 'Table');

        // Normalize cart items for display
        $normalizedCart = [];
        foreach ($cartItems as $key => $item) {
            $normalizedCart[$key] = [
                'name'     => $item['name'],
                'price'    => $item['price'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 1,
            ];
        }

        $allItems = array_merge($this->existingItems, $normalizedCart);
        $total = collect($allItems)->sum(fn($i) => $i['price'] * $i['quantity']);

        $this->dispatch('print-bill', [
            'tableName' => $tableName,
            'items'     => array_values($allItems),
            'total'     => $total,
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

            // Add available stock: sum across all consumer warehouses
            // This avoids wrong results when bar/kitchen warehouse IDs differ per environment
            $consumerWarehouseIds = \App\Models\WareHouse::where('type', 'consumer')->pluck('id');
            foreach ($products as $product) {
                $product->available_stock = $product->inventory
                    ->whereIn('warehouse_id', $consumerWarehouseIds->all())
                    ->sum('quantity');
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
    private function getWarehouseId($product): int
    {
        return \App\Services\InventoryService::getWarehouseForProduct($product);
    }
};
?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-900"
     x-data="posCart()"
     x-init="
         existingTotal = {{ (int) $existingTotal }};
         $watch('$wire.existingTotal', v => existingTotal = v);
     "
     @print-bill.window="printPOSBill($event.detail[0] ?? $event.detail)"
     @order-cancelled.window="cart = {}; showCart = false">
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
                        <div @if(auth()->user()->currentShift()) @click="addProductToCart({{ $product->id }}, '{{ addslashes($product->name) }}', {{ (float)$product->price }}, {{ (int)($product->available_stock ?? 0) }})" @endif
                            class="relative {{ auth()->user()->currentShift() ? 'cursor-pointer hover:border-amber-500 hover:shadow-md' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">{{ $product->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">₦{{ number_format($product->price) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                            </div>
                        </div>
                    @endforeach
                    @foreach($menuItems as $menuItem)
                        <div @if(auth()->user()->currentShift()) @click="addMenuItemToCart({{ $menuItem->id }}, '{{ addslashes($menuItem->name) }}', {{ (float)$menuItem->sale_price }})" @endif
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
                        <option value="takeaway">Take Away</option>
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
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 mt-4" x-show="cartCount > 0">New Items</h4>
                    @endif
                    {{-- Alpine-managed new cart items --}}
                    <template x-for="(item, key) in cart" :key="key">
                        <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800 dark:text-gray-200" x-text="item.name"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦<span x-text="item.price"></span> x <span x-text="item.qty"></span></div>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦<span x-text="(item.price * item.qty).toLocaleString()"></span></div>
                            <button @if(auth()->user()->currentShift()) @click="removeFromCart(key)" @endif class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1"><span class="text-lg">×</span></button>
                        </div>
                    </template>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between text-xl lg:text-2xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                        <span>Total:</span><span>₦<span x-text="total.toLocaleString()"></span></span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <button @if(auth()->user()->currentShift()) @click="sendToKitchen()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Order</span></button>
                        <button @if(auth()->user()->currentShift()) @click="openPaymentModal()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Pay</span></button>
                        <button @if(auth()->user()->currentShift()) wire:click="cancelOrder" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span class="text-sm lg:text-base">Cancel</span></button>
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
                    <div @if(auth()->user()->currentShift()) @click="addProductToCart({{ $product->id }}, '{{ addslashes($product->name) }}', {{ (float)$product->price }}, {{ (int)($product->available_stock ?? 0) }})" @endif
                        class="relative {{ auth()->user()->currentShift() ? 'hover:border-amber-500 active:scale-95' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation">
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">{{ $product->name }}</div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">₦{{ number_format($product->price) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                        </div>
                    </div>
                @endforeach
                @foreach($menuItems as $menuItem)
                    <div @if(auth()->user()->currentShift()) @click="addMenuItemToCart({{ $menuItem->id }}, '{{ addslashes($menuItem->name) }}', {{ (float)$menuItem->sale_price }})" @endif
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
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦<span x-text="total.toLocaleString()"></span></div>
                    </div>
                    <div class="text-center" x-show="cartCount + {{ count($existingItems) }} > 0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Items</div>
                        <div class="text-lg font-bold text-blue-600" x-text="cartCount + {{ count($existingItems) }}"></div>
                    </div>
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
                    <option value="takeaway">Take Away</option>
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
                <button @click="showCart = !showCart" class="relative p-2 bg-blue-600 text-white rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.1 5H19M7 13v8a2 2 0 002 2h10a2 2 0 002-2v-3"></path>
                    </svg>
                    <span x-show="cartCount + {{ count($existingItems) }} > 0"
                          x-text="cartCount + {{ count($existingItems) }}"
                          class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"></span>
                </button>
            </div>
        </div>

        <!-- Mobile Cart Sidebar -->
        <div x-show="showCart" x-cloak class="fixed inset-0 bg-black bg-opacity-50 z-50 flex">
            <div class="ml-auto w-80 bg-white dark:bg-gray-900 h-full flex flex-col">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">🛒 Cart</h3>
                    <button @click="showCart = false" class="text-gray-400 hover:text-red-500 p-2">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="px-4 py-2 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"></path>
                    </svg>
                    @if($selectedTableId === 'takeaway')
                        <span>Take Away</span>
                    @elseif($selectedTableId)
                        <span>{{ $tables->find($selectedTableId)?->name ?? 'Table' }}</span>
                    @else
                        <span class="italic">No table selected</span>
                    @endif
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

                    {{-- Alpine-managed new cart items --}}
                    <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 {{ !empty($existingItems) ? 'mt-4' : '' }}" x-show="cartCount > 0">New Items</h4>
                    <template x-for="(item, key) in cart" :key="key">
                        <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800 dark:text-gray-200" x-text="item.name"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦<span x-text="item.price"></span> x <span x-text="item.qty"></span></div>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦<span x-text="(item.price * item.qty).toLocaleString()"></span></div>
                            <button @if(auth()->user()->currentShift()) @click="removeFromCart(key)" @endif class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1">
                                <span class="text-lg">×</span>
                            </button>
                        </div>
                    </template>

                    <div x-show="cartCount === 0 && {{ count($existingItems) }} === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <div class="text-4xl mb-2">🛒</div>
                        <div>Your cart is empty</div>
                        <div class="text-sm">Tap on products to add them</div>
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800"
                     x-show="cartCount > 0 || {{ count($existingItems) > 0 ? 'true' : 'false' }}">
                    <div class="flex justify-between text-xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                        <span>Total:</span><span>₦<span x-text="total.toLocaleString()"></span></span>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <button @if(auth()->user()->currentShift()) @click="sendToKitchen()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                            Order
                        </button>
                        <button @if(auth()->user()->currentShift()) @click="openPaymentModal()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                            Pay
                        </button>
                        <button @if(auth()->user()->currentShift()) wire:click="cancelOrder" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div x-show="showPaymentModal" x-cloak class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
        <div
            class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700 relative max-h-[90vh] overflow-y-auto">

            <div
                class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">💰 Checkout</h3>
                <button @click="showPaymentModal = false" class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                        class="text-2xl">&times;</span></button>
            </div>

            <div class="p-6 space-y-4">
                <div class="text-center mb-6">
                    <div class="text-sm text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Total Due</div>
                    <div class="text-3xl lg:text-4xl font-black text-gray-900 dark:text-white">₦<span x-text="total.toLocaleString()"></span></div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Amount Received</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-lg">₦</span>
                        <input type="number" x-model="paidAmount" inputmode="decimal"
                            class="w-full pl-8 pr-4 py-4 text-xl font-bold border rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white touch-manipulation"
                            placeholder="0.00">
                    </div>
                </div>

                {{-- Change display (Alpine-driven) --}}
                <div x-show="balance < 0"
                    class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg border border-green-200 dark:border-green-800 text-center">
                    <span class="text-green-700 dark:text-green-400 font-bold text-sm">Change:</span>
                    <div class="text-2xl font-black text-green-600 dark:text-green-400">
                        ₦<span x-text="Math.abs(balance).toLocaleString()"></span></div>
                </div>

                <div x-show="balance > 0"
                    class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg border border-red-200 dark:border-red-800 text-center animate-pulse">
                    <span class="text-red-700 dark:text-red-400 font-bold text-sm">⚠️ Remaining Debt:</span>
                    <div class="text-2xl font-black text-red-600 dark:text-red-400">₦<span x-text="balance.toLocaleString()"></span></div>
                </div>

                {{-- Guest selector shown only when there is a debt --}}
                <div x-show="balance > 0">
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
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach(['cash' => '💵 Cash', 'pos' => '💳 POS', 'transfer' => '🏦 Transfer'] as $key => $label)
                            <button @click="paymentMethod = '{{ $key }}'"
                                :class="paymentMethod === '{{ $key }}' ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600'"
                                class="p-3 border rounded-lg font-bold text-sm transition-colors touch-manipulation">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3 sticky bottom-0 bg-white dark:bg-gray-900">
                <button @click="showPaymentModal = false"
                    class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 touch-manipulation">Cancel</button>
                <button @click="confirmPayment()"
                    :disabled="isLoading"
                    class="px-4 py-3 font-bold text-white bg-green-600 rounded-lg hover:bg-green-700 shadow-lg shadow-green-600/30 flex items-center justify-center gap-2 touch-manipulation disabled:opacity-50"><span x-text="isLoading ? 'Processing…' : 'Confirm'"></span></button>
            </div>
        </div>
    </div>

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

    {{-- posCart Alpine Component --}}
    <script>
    window.posCart = function () {
        return {
            // Alpine-managed cart: { key: { name, price, qty, type, menu_item_id? } }
            cart: {},
            existingTotal: 0,   // synced from $wire.existingTotal via x-init
            showCart: false,
            showPaymentModal: false,
            paidAmount: 0,
            paymentMethod: 'cash',
            isLoading: false,

            get cartCount() {
                return Object.keys(this.cart).length;
            },

            get newCartTotal() {
                return Object.values(this.cart).reduce((sum, i) => sum + i.price * i.qty, 0);
            },

            get total() {
                return this.existingTotal + this.newCartTotal;
            },

            get balance() {
                return this.total - parseFloat(this.paidAmount || 0);
            },

            /**
             * Add a product to cart without a round-trip (optimistic).
             * Stock is pre-checked at render time via $product->available_stock.
             * The server's OrderSplitter does the real check on checkout.
             */
            addProductToCart(id, name, price, availableStock) {
                const key = String(id);
                const currentQty = this.cart[key] ? this.cart[key].qty : 0;

                if (availableStock <= currentQty) {
                    alert('Out of stock: only ' + availableStock + ' available.');
                    return;
                }

                if (this.cart[key]) {
                    this.cart[key].qty++;
                } else {
                    this.cart[key] = { name, price, qty: 1, type: 'product' };
                }
            },

            /**
             * Add a menu item to cart — must hit server for ingredient availability check.
             */
            async addMenuItemToCart(id, name, price) {
                if (this.isLoading) return;
                const key = 'menu_' + id;
                const currentQty = this.cart[key] ? this.cart[key].qty : 0;

                this.isLoading = true;
                try {
                    const result = await this.$wire.validateAndAddToCart(id, 'menu_item', currentQty);
                    if (result.ok) {
                        if (this.cart[key]) {
                            this.cart[key].qty++;
                        } else {
                            this.cart[key] = {
                                name:         result.item.name ?? name,
                                price:        result.item.price ?? price,
                                qty:          1,
                                type:         'menu_item',
                                menu_item_id: id,
                            };
                        }
                    }
                } finally {
                    this.isLoading = false;
                }
            },

            removeFromCart(key) {
                const updated = { ...this.cart };
                delete updated[key];
                this.cart = updated;
            },

            openPaymentModal() {
                if (this.total <= 0) return;
                this.paidAmount = this.total;
                this.paymentMethod = 'cash';
                this.$wire.$set('selectedGuestId', null);
                this.showPaymentModal = true;
                this.showCart = false;
            },

            async confirmPayment() {
                if (this.isLoading) return;
                this.isLoading = true;
                try {
                    await this.$wire.processPayment(
                        this.cart,
                        parseFloat(this.paidAmount || 0),
                        this.paymentMethod,
                        this.$wire.selectedGuestId || null
                    );
                    // Wire updated server state — sync Alpine directly (no dispatch needed)
                    this.cart = {};
                    this.showPaymentModal = false;
                    this.showCart = false;
                    this.paidAmount = 0;
                    this.existingTotal = this.$wire.existingTotal;
                } catch (e) {
                    // Errors already shown as Filament notifications from server
                } finally {
                    this.isLoading = false;
                }
            },

            async sendToKitchen() {
                if (this.isLoading || this.cartCount === 0) return;
                this.isLoading = true;
                try {
                    await this.$wire.checkout(this.cart);
                    // Sync existingTotal from wire after server merges cart into existingItems
                    this.existingTotal = this.$wire.existingTotal;
                    this.cart = {};
                    this.showCart = false;
                } catch (e) {
                    // Errors already shown as Filament notifications from server
                } finally {
                    this.isLoading = false;
                }
            },

            printBill() {
                this.$wire.printBill(this.cart);
            },
        };
    };
    </script>

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