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
    // public $tables; -> Removed to prevent rehydration constraint loss
    public $selectedTableId;
    public $activeCategoryId;
    public $currentOrderId = null;
    public $existingItems = [];
    public $existingTotal = 0; // sum of existingItems, synced to Alpine
    public $search = '';
    public $deferProducts = true; // defer loading heavy product data until after initial render
    public $lastPolledShiftId = null; // tracks whether wire:poll's tick actually needs to re-render anything

    // Payment Properties (guest/debt remain server-side)
    public $selectedGuestId = null;

    // Guest Creation Properties
    public $showGuestModal = false;
    public $newGuestName = '';
    public $newGuestPhone = '';

    // Cancellation Reason Properties
    public $showCancelModal = false;
    public $cancellationReason = '';

    // Return Item Properties
    public $showReturnModal = false;
    public $returnItemKey = null;
    public $returnReason = '';
    public $returnQuantity = 1;
    public $maxReturnQuantity = 1;

    // Cash Drop Properties
    public $showCashDropModal = false;
    public $cashDropReceiverId = null;
    public $cashDropAmount = 0;
    public $cashDropNote = '';

    // Computed Property for Tables
    public function getTablesProperty()
    {
        return \App\Models\Table::with([
            'orders' => function ($q) {
                $q->whereIn('status', ['pending', 'preparing', 'ready', 'served'])->latest();
            }
        ])->get();
    }

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

        $table = \App\Models\Table::find($value);
        if (!$table)
            return;

        // Directly query the active order to ensure accurate, live authorization
        $activeOrder = \App\Models\Order::where('table_id', $value)
            ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
            ->latest()
            ->first();

        if ($activeOrder) {
            // Prevent waiter from accessing a table served by someone else
            if ($activeOrder->user_id && $activeOrder->user_id !== auth()->id()) {
                $owner = \App\Models\User::find($activeOrder->user_id);
                Notification::make()
                    ->title('Access Denied')
                    ->body("This table is currently served by " . ($owner?->name ?? 'another waiter'))
                    ->danger()
                    ->send();
                $this->selectedTableId = null;
                return;
            }

            $orders = \App\Models\Order::where('table_id', $value)
                ->whereIn('status', ['pending', 'preparing', 'ready', 'served'])
                ->with('items.product')
                ->get();

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    if (isset($this->existingItems[$item->product_id ?: $item->id])) {
                        $this->existingItems[$item->product_id ?: $item->id]['quantity'] += $item->quantity;
                    } else {
                        $key = $item->product_id ?: $item->id;
                        $this->existingItems[$key] = [
                            'id' => $item->product_id ?: $item->menu_item_id,
                            'name' => $item->product_name,
                            'price' => $item->unit_price,
                            'quantity' => $item->quantity,
                            'image' => $item->product ? $item->product->image : null,
                            'type' => $item->item_type,
                            'product_id' => $item->product_id,
                            'menu_item_id' => $item->menu_item_id,
                        ];
                    }
                }
            }

            $this->existingTotal = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity']);
            if ($orders->isNotEmpty()) {
                Notification::make()->title('Order Resumed')->info()->send();
            }
        }
    }

    public function loadCurrentShift()
    {
        // This method exists to enable polling for shift status updates —
        // the actual shift data is read live from auth()->user()->currentShift()
        // in the view. Every 10s tick used to force a full re-render of this
        // whole (large) component regardless of whether anything changed,
        // competing with real clicks for server time. Skip the render on
        // every tick except the rare one where the shift actually started,
        // ended, or changed — that's the only case the poll exists to catch.
        $currentShiftId = auth()->user()?->currentShift()?->id;

        if ($currentShiftId === $this->lastPolledShiftId) {
            $this->skipRender();
        }

        $this->lastPolledShiftId = $currentShiftId;
    }

    /**
     * Runs before every request (initial mount AND every subsequent
     * Livewire update/poll) — unlike mount(), which only fires once. Two
     * separate jobs, both learned the hard way in production:
     *
     * 1. Auth::shouldUse('staff_pin') is what makes every auth()->id()/
     *    auth()->user() call elsewhere in this component (including
     *    checkout()'s OrderSplitter call) resolve against the PIN-
     *    identified waiter instead of the default 'web' guard. The
     *    EnsureStaffPinAuthenticated middleware calls it, but only for the
     *    request that middleware actually runs on — Livewire's follow-up
     *    "component update" AJAX request (e.g. clicking "Send to Kitchen")
     *    does NOT reliably re-run that middleware, so without setting it
     *    again here, auth()->id() silently comes back null on exactly the
     *    request that places the order, throwing a TypeError deep inside
     *    OrderSplitter. This must be set on every request, not assumed to
     *    already be in effect from the initial page load.
     *
     * 2. A kiosk/staff PIN session can be logged out from a different
     *    browser tab sharing the same session (e.g. that tab's order
     *    completed and triggered the auto-logout), while this tab's
     *    wire:poll keeps ticking every 10s. Without this check, the next
     *    poll or click here would hard-crash on auth()->user()->
     *    currentShift() throughout the view instead of bouncing back to
     *    the PIN pad.
     */
    public function boot(): void
    {
        $isKioskOrStaffSession = session('kiosk_device_id') || session('trusted_device_user_id');

        if (!$isKioskOrStaffSession) {
            return;
        }

        if (!auth()->guard('staff_pin')->check()) {
            $routeName = session('kiosk_device_id') ? 'kiosk.home' : 'staff.home';
            $this->redirect(route($routeName), navigate: false);
            $this->skipRender();
            return;
        }

        auth()->shouldUse('staff_pin');
    }

    public function mount($table_id = null)
    {
        // Kiosk/staff-phone sessions mount this component fresh on every
        // single PIN login (one login = one interaction, then auto-logout),
        // unlike the admin sales page where one mount lives for a whole
        // shift. Deferring the first product load only pays off when the
        // extra wire:init round-trip is amortized over many interactions —
        // on a kiosk it just adds a visible delay to every order, so skip
        // the defer there and render products in the same initial response.
        if (session('kiosk_device_id') || session('trusted_device_user_id')) {
            $this->deferProducts = false;
        }

        $this->categories = Cache::remember('categories', 3600, function () {
            return Category::has('products')->get();
        });
        $this->activeCategoryId = $this->categories->first()?->id;

        $this->selectedTableId = $table_id ?? $this->tables->first(function ($table) {
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
     * Validate whether an item can be added to the local Alpine cart. Never
     * mutates Livewire state — the cart itself lives entirely in Alpine —
     * so skipRender() is always safe here: there is nothing in the template
     * that depends on this call's result besides the returned array itself,
     * and dispatched notifications still reach the browser regardless of
     * whether the HTML gets re-rendered. Skipping the ~1500-line component's
     * full re-render/diff on every single tap is the actual point of this
     * method existing separately from a normal reactive property update.
     * Returns ['ok' => true, 'item' => [...]] or ['ok' => false].
     */
    public function validateAndAddToCart(int $itemId, string $itemType, int $currentQty = 0): array
    {
        $this->skipRender();

        if (!auth()->user()?->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before adding items to cart.')->danger()->send();
            return ['ok' => false];
        }

        if ($itemType === 'product') {
            $product = Product::with('category')->find($itemId);

            // Determine the specific warehouse for this product
            $warehouseId = \App\Services\InventoryService::getWarehouseForProduct($product);

            // Check stock in that specific warehouse
            $available = (int) DB::table('inventory_items')
                ->where('product_id', $itemId)
                ->where('warehouse_id', $warehouseId)
                ->value('quantity');

            if ($available <= $currentQty) {
                Notification::make()->title('Out of Stock')
                    ->body("Only {$available} available in stock. Warehouse ID: {$warehouseId}")->danger()->send();
                return ['ok' => false];
            }

            return [
                'ok' => true,
                'item' => [
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'type' => 'product',
                ]
            ];
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

            return [
                'ok' => true,
                'item' => [
                    'name' => $menuItem->name,
                    'price' => (float) $menuItem->sale_price,
                    'type' => 'menu_item',
                    'menu_item_id' => $itemId,
                ]
            ];
        }

        return ['ok' => false];
    }

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
        ]);

        // Auto-select the new guest in the dropdown
        $this->selectedGuestId = $guest->id;

        // Reset and Close Guest Modal
        $this->newGuestName = '';
        $this->newGuestPhone = '';
        $this->showGuestModal = false;

        Notification::make()->title('Guest Added')->success()->send();
    }

    public function processPayment(array $cartItems, float $paidAmount, string $paymentMethod, ?int $guestId = null, array $splitPayments = []): bool
    {
        // Check if user has an active shift
        if (!auth()->user()?->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before processing payments.')->danger()->send();
            return false;
        }

        if (!auth()->check()) {
            Notification::make()->title('Authentication Required')->danger()->send();
            return false;
        }

        // STRICT RULE 1: Prevent paying for items that haven't been sent to the kitchen yet
        if (!empty($cartItems)) {
            Notification::make()
                ->title('Unsent Items')
                ->body('You must send new items to the kitchen by clicking "Order" before processing payment.')
                ->warning()
                ->send();
            return false;
        }

        $total = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity'])
            + collect($cartItems)->sum(fn($i) => ($i['price'] ?? 0) * ($i['qty'] ?? $i['quantity'] ?? 1));

        if ($total <= 0) {
            Notification::make()->title('Cart is empty')->warning()->send();
            return false;
        }

        // Validate split payment if applicable
        if (!empty($splitPayments)) {
            $splitTotal = ($splitPayments['cash'] ?? 0) + ($splitPayments['pos'] ?? 0);
            if ($splitTotal - $total > 0.01) {
                Notification::make()
                    ->title('Split Payment Error')
                    ->body("Cash (₦" . number_format($splitPayments['cash'] ?? 0) . ") + POS (₦" . number_format($splitPayments['pos'] ?? 0) . ") cannot exceed Total (₦" . number_format($total) . ")")
                    ->danger()
                    ->send();
                return false;
            }
            $paidAmount = $splitTotal;
        }

        if ($paidAmount < $total && empty($guestId)) {
            Notification::make()->title('Select a Guest for Debt')->warning()->send();
            return false;
        }

        if (!$this->selectedTableId) {
            Notification::make()->title('Please select a table or Take Away')->warning()->send();
            return false;
        }

        $isTakeaway = $this->selectedTableId === 'takeaway';
        $tableId = $isTakeaway ? null : (int) $this->selectedTableId;
        $orderStatus = ($paidAmount >= $total) ? 'paid' : 'partial';

        // STRICT RULE 2: Prevent paying for orders that are still cooking/pending
        $unprocessedOrdersQuery = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing']);

        if ($isTakeaway) {
            $unprocessedOrdersQuery->where('user_id', auth()->id());
        }

        if ($unprocessedOrdersQuery->exists()) {
            Notification::make()
                ->title('Order Not Ready')
                ->body('Payment blocked. The kitchen or bar has not approved this order yet.')
                ->danger()
                ->send();
            return false;
        }

        // STRICT RULE 3: For dine-in tables, prevent paying before the waiter
        // has confirmed they actually carried the items to the table. Being
        // "ready" only means the kitchen/bar finished prep — it doesn't mean
        // it left the pass. Takeaway has no table to carry to, so it's exempt.
        if (!$isTakeaway && Order::where('table_id', $tableId)->where('status', 'ready')->exists()) {
            Notification::make()
                ->title('Not Yet Served')
                ->body('Confirm you have carried the order to the table before processing payment.')
                ->danger()
                ->send();
            return false;
        }

        // Restore old stock & delete previous orders only when a table is involved
        $waiterUserId = auth()->id();
        if (!$isTakeaway && $tableId) {
            $existingOrders = Order::where('table_id', $tableId)->whereIn('status', ['ready', 'served'])->with('items')->get();
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
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $qty,
                'type' => $item['type'] ?? 'product',
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
                'amount_paid' => $paidAmount,
                'payment_method' => $paymentMethod,
                'status' => $orderStatus,
                'guest_id' => $guestId,
                'processed_by_user_id' => auth()->id(),
                // Attribute to the waiter's own shift (accountability is
                // per-waiter, even if a different staff member is the one
                // finalizing checkout at the till).
                'shift_id' => \App\Models\User::find($waiterUserId)?->currentShift()?->id,
                'paid_cash' => $splitPayments['cash'] ?? ($paymentMethod === 'cash' ? $paidAmount : 0),
                'paid_pos' => $splitPayments['pos'] ?? ($paymentMethod !== 'cash' ? $paidAmount : 0),
                'kiosk_device_id' => session('kiosk_device_id'),
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()->title('Stock Error')->body($e->getMessage())->danger()->send();
                return false;
            }
            throw $e;
        }

        if ($paidAmount > 0 && !empty($orders)) {
            // A mixed cart (e.g. food + drinks) is split by OrderSplitter into
            // one Order per destination, each already carrying its own
            // proportional amount_paid. Record a payment per order — not just
            // the first — otherwise destination-level cash reporting silently
            // drops whatever was paid against the other split orders.
            $shiftId = auth()->user()?->currentShift()?->id;
            $method = !empty($splitPayments) ? 'split' : $paymentMethod;

            foreach ($orders as $order) {
                if ($order->amount_paid <= 0) {
                    continue;
                }

                \App\Models\OrderPayment::create([
                    'order_id' => $order->id,
                    'amount' => $order->amount_paid,
                    'method' => $method,
                    'user_id' => auth()->id(),
                    'shift_id' => $shiftId,
                    'paid_at' => now(),
                ]);
            }
        }

        if ($tableId) {
            \App\Models\Table::find($tableId)->update(['status' => 'available']);
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
        Cache::forget('menu_items_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);

        $this->dispatch('order-completed');

        return true;
    }

    // --- STANDARD CHECKOUT (Send to Kitchen) ---
    // Shift enforcement (waiter, and bartender/chef for bar/kitchen
    // destinations) lives entirely in OrderSplitter now — this is the only
    // choke point, so it can't be bypassed by any other entry point either.
    public function checkout(array $cartItems, string $action = 'update'): bool
    {
        if (empty($cartItems))
            return false;
        if (!$this->selectedTableId || $this->selectedTableId === 'takeaway') {
            Notification::make()->title('Please select a table to send orders to the kitchen')->warning()->send();
            return false;
        }
        $tableId = $this->selectedTableId;

        // Normalize qty → quantity for OrderSplitter
        $normalized = [];
        foreach ($cartItems as $key => $item) {
            $norm = [
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 1,
                'type' => $item['type'] ?? 'product',
            ];
            if (!empty($item['menu_item_id'])) {
                $norm['menu_item_id'] = $item['menu_item_id'];
            }
            $normalized[$key] = $norm;
        }

        try {
            $splitter = new OrderSplitter();
            $splitter->handle($normalized, $tableId, auth()->id(), [
                'status' => 'pending',
                'payment_method' => 'cash',
                'shift_id' => auth()->user()?->currentShift()?->id,
                'kiosk_device_id' => session('kiosk_device_id'),
            ]);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Out of Stock') || str_contains($e->getMessage(), 'Insufficient ingredients')) {
                Notification::make()->title('Stock Error')->body($e->getMessage())->danger()->send();
                return false;
            }
            if (str_contains($e->getMessage(), 'shift') || str_contains($e->getMessage(), 'session')) {
                Notification::make()->title('No Active Shift')->body($e->getMessage())->danger()->send();
                return false;
            }
            throw $e;
        }

        \App\Models\Table::find($tableId)->update(['status' => 'occupied']);

        // Merge new items into existingItems so they render as grayed-out server-side
        foreach ($normalized as $key => $item) {
            if (isset($this->existingItems[$key])) {
                $this->existingItems[$key]['quantity'] += $item['quantity'];
            } else {
                $this->existingItems[$key] = [
                    'id' => $key,
                    'name' => $item['name'],
                    'price' => $item['price'],
                    'quantity' => $item['quantity'],
                    'image' => null,
                    'type' => $item['type'],
                    'product_id' => $item['type'] === 'product' ? $key : null,
                    'menu_item_id' => $item['type'] === 'menu_item' ? $item['menu_item_id'] : null,
                ];
            }
        }
        $this->existingTotal = collect($this->existingItems)->sum(fn($i) => $i['price'] * $i['quantity']);

        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);
        Cache::forget('menu_items_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);

        Notification::make()->title('Order Updated')->success()->send();

        // Harmless no-op outside the kiosk (nothing listens there); the
        // kiosk's wrapper component listens for this to auto-logout back to
        // the table grid after one order interaction.
        $this->dispatch('order-completed');

        return true;
    }

    /**
     * Two taps, no typing: pick a method, done. The amount is always the
     * order's own outstanding balance — never anything the client sends —
     * so there is no path from this action to a wrong or short payment
     * amount being recorded. Only applies to orders already confirmed
     * served with nothing new/unsent still in the cart; anything still
     * cooking or not yet carried to the table goes through the standard
     * flow instead, same as it always has.
     */
    public function markPaidFast(string $method)
    {
        if (!auth()->user()?->currentShift()) {
            Notification::make()->title('No Active Shift')->body('You must start a shift before processing payments.')->danger()->send();
            return;
        }

        if (!in_array($method, ['cash', 'pos', 'transfer'], true)) {
            return;
        }

        if (!$this->selectedTableId || $this->selectedTableId === 'takeaway') {
            Notification::make()->title('Please select a table')->warning()->send();
            return;
        }

        if (!empty($this->existingItems)) {
            Notification::make()->title('Unsent Items')->body('Send new items to the kitchen first, then use Mark Paid.')->warning()->send();
            return;
        }

        $tableId = $this->selectedTableId;

        $unprocessedExists = Order::where('table_id', $tableId)
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->exists();

        if ($unprocessedExists) {
            Notification::make()->title('Not Ready')->body('Some items are still cooking, or not yet confirmed served.')->danger()->send();
            return;
        }

        $servedOrders = Order::where('table_id', $tableId)->where('status', 'served')->get();

        if ($servedOrders->isEmpty()) {
            Notification::make()->title('Nothing to Pay')->warning()->send();
            return;
        }

        $shiftId = auth()->user()?->currentShift()?->id;
        $totalPaid = 0;

        $orderIds = $servedOrders->pluck('id')->all();

        DB::transaction(function () use ($orderIds, $method, $shiftId, &$totalPaid) {
            // Re-fetched and locked inside the transaction, re-checking
            // status='served' on the locked copy — $servedOrders above was
            // read before the transaction started, so without this a
            // double-tap or two devices open on the same table could both
            // see the same outstanding balance and both record a payment
            // for it, double-counting revenue and overstating what the
            // waiter is expected to remit at settlement.
            $orders = Order::whereIn('id', $orderIds)
                ->where('status', 'served')
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                $outstanding = round(max(0, (float) $order->total_amount - (float) $order->amount_paid), 2);

                if ($outstanding > 0) {
                    OrderPayment::create([
                        'order_id' => $order->id,
                        'amount' => $outstanding,
                        'method' => $method,
                        'user_id' => auth()->id(),
                        'shift_id' => $shiftId,
                        'paid_at' => now(),
                    ]);

                    $totalPaid += $outstanding;
                }

                $order->update([
                    'amount_paid' => $order->total_amount,
                    'status' => 'paid',
                ]);
            }
        });

        \App\Models\Table::find($tableId)->update(['status' => 'available']);

        Notification::make()->title('Paid: ₦' . number_format($totalPaid))->success()->send();

        $this->existingItems = [];
        $this->existingTotal = 0;
        $this->currentOrderId = null;
        $this->selectedTableId = null;
        $this->selectedGuestId = null;

        $this->dispatch('order-completed');
    }

    public function cancelOrder()
    {
        // Check if user has an active shift
        if (!auth()->user()?->currentShift()) {
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

        // Clear product cache to refresh inventory display
        Cache::forget('products_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);
        Cache::forget('menu_items_' . ($this->activeCategoryId ?? 'all') . '_' . $this->search);

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
                'name' => $item['name'],
                'price' => $item['price'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 1,
            ];
        }

        $allItems = array_merge($this->existingItems, $normalizedCart);
        $total = collect($allItems)->sum(fn($i) => $i['price'] * $i['quantity']);

        $this->dispatch('print-bill', [
            'tableName' => $tableName,
            'items' => array_values($allItems),
            'total' => $total,
            'date' => now()->format('M j, Y g:i A'),
            'cashier' => auth()->user()?->name,
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

        $buildProducts = function () {
            $query = Product::where('is_active', true)
                ->with(['inventory.warehouse', 'category']);

            if (!empty($this->search))
                $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));
            elseif ($this->activeCategoryId)
                $query->where('category_id', $this->activeCategoryId);

            $products = $query->limit(100)->get();

            foreach ($products as $product) {
                $warehouseId = \App\Services\InventoryService::getWarehouseForProduct($product);
                $product->available_stock = $product->inventory
                    ->where('warehouse_id', $warehouseId)
                    ->sum('quantity');
            }

            return $products;
        };

        $buildMenuItems = function () {
            $query = \App\Models\MenuItem::where('available_for_sale', true)
                ->with(['recipes.ingredient']);

            if (!empty($this->search))
                $query->where(fn($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('sku', 'like', "%{$this->search}%"));
            elseif ($this->activeCategoryId)
                $query->where('category_id', $this->activeCategoryId);

            return $query->limit(100)->get();
        };

        // A live search creates a distinct cache key on every keystroke
        // ("b", "bu", "bur"...), which is never reused — caching it just
        // fills the (database-backed) cache table with dead rows while
        // still hitting the DB fresh every time anyway. Only cache the
        // category-browsing case, which genuinely gets reused.
        if (!empty($this->search)) {
            $products = $buildProducts();
            $menuItems = $buildMenuItems();
        } else {
            $cacheKey = 'products_' . ($this->activeCategoryId ?? 'all');
            $products = Cache::remember($cacheKey, 1800, $buildProducts);

            // Reduced cache time to 5 seconds to ensure ingredient stock is fresh
            $menuItemsCacheKey = 'menu_items_' . ($this->activeCategoryId ?? 'all');
            $menuItems = Cache::remember($menuItemsCacheKey, 5, $buildMenuItems);
        }

        return [
            'products' => $products,
            'menuItems' => $menuItems
        ];
    }

    // Helper to get Warehouse ID consistently
    private function getWarehouseId($product): int
    {
        return \App\Services\InventoryService::getWarehouseForProduct($product);
    }

    public function openReturnModal($key)
    {
        if (!isset($this->existingItems[$key])) {
            Notification::make()->title('Item not found')->danger()->send();
            return;
        }

        $this->returnItemKey = $key;
        $this->returnReason = '';
        $this->maxReturnQuantity = $this->existingItems[$key]['quantity'];
        $this->returnQuantity = 1; // Default to returning 1
        $this->showReturnModal = true;
    }

    public function submitReturnRequest()
    {
        $this->validate([
            'returnReason' => 'required|string|min:3',
            'returnQuantity' => 'required|integer|min:1|max:' . $this->maxReturnQuantity,
        ]);

        if (!$this->returnItemKey || !isset($this->existingItems[$this->returnItemKey])) {
            Notification::make()->title('Invalid Item')->danger()->send();
            return;
        }

        $itemData = $this->existingItems[$this->returnItemKey];
        $destination = 'kitchen';

        // Determine destination
        if ($itemData['type'] === 'product' && $itemData['product_id']) {
            $product = Product::with('category')->find($itemData['product_id']);
            if ($product && $product->category && $product->category->type === 'drink') {
                $destination = 'bar';
            }
        }

        // Create the return ticket only. The guest's bill is NOT touched
        // here — that only happens once the on-duty bartender/chef confirms
        // physically receiving the item back (ReturnConfirmationService).
        // This is the fix for the classic void-and-pocket scam: previously
        // the bill dropped the instant this button was pressed, whether or
        // not the drink/dish ever actually came back.
        Order::create([
            'order_number' => 'RET-' . time(),
            'table_id' => $this->selectedTableId === 'takeaway' ? null : $this->selectedTableId,
            'user_id' => auth()->id(),
            'shift_id' => auth()->user()?->currentShift()?->id,
            'status' => 'pending',
            'destination' => $destination,
            'total_amount' => 0,
            'is_return' => true,
        ])->items()->create([
            'product_id' => $itemData['type'] === 'product' ? $itemData['product_id'] : null,
            'menu_item_id' => $itemData['type'] === 'menu_item' ? $itemData['menu_item_id'] : null,
            'product_name' => $itemData['name'],
            'item_type' => $itemData['type'],
            'quantity' => $this->returnQuantity,
            'unit_price' => $itemData['price'],
            'subtotal' => 0,
            'return_reason' => $this->returnReason,
        ]);

        $this->showReturnModal = false;
        $this->returnReason = '';
        $this->returnQuantity = 1;
        Notification::make()->title('Return Request Sent')->body('Awaiting bar/kitchen confirmation before the bill changes.')->success()->send();
    }

    public function getCashDropReceiversProperty()
    {
        return \App\Models\User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'admin', 'super_admin']))->get();
    }

    public function openCashDropModal(): void
    {
        $this->cashDropReceiverId = null;
        $this->cashDropAmount = 0;
        $this->cashDropNote = '';
        $this->showCashDropModal = true;
    }

    /**
     * Declares only — nothing about this waiter's expected remittance
     * changes until the named receiver confirms it from their own login.
     */
    public function declareCashDrop(): void
    {
        $this->validate([
            'cashDropReceiverId' => 'required|integer',
            'cashDropAmount' => 'required|numeric|min:0.01',
        ]);

        try {
            $receiver = \App\Models\User::findOrFail($this->cashDropReceiverId);
            (new \App\Services\CashDropService())->declare(auth()->user(), $receiver, (float) $this->cashDropAmount, $this->cashDropNote ?: null);

            $this->showCashDropModal = false;
            Notification::make()->title('Cash Drop Declared')->body("Awaiting {$receiver->name}'s confirmation.")->success()->send();
        } catch (\Exception $e) {
            Notification::make()->title('Could Not Declare Drop')->body($e->getMessage())->danger()->send();
        }
    }
};
?>

@php
    // Only the physical kiosk touchscreen (1920x1080) gets the fixed
    // app-shell + touch-first redesign below. Staff-phone (trusted device,
    // narrow viewport) and the admin Filament Sales page keep the original
    // layout untouched — they're a different shape of screen and the admin
    // page is embedded inside Filament's own panel chrome, which a
    // full-viewport shell would fight with.
    $isKioskDevice = (bool) session('kiosk_device_id');
@endphp

<div class="{{ $isKioskDevice ? 'h-dvh w-screen overflow-hidden flex flex-col' : 'min-h-screen' }} bg-gray-50 dark:bg-gray-900" x-data="posCart()" x-init="
         existingTotal = {{ (int) $existingTotal }};
         existingCount = {{ count($existingItems) }};
         $watch('$wire.existingTotal', v => existingTotal = v);
         $watch('$wire.existingItems', v => existingCount = Object.keys(v).length);
     " @print-bill.window="printPOSBill($event.detail[0] ?? $event.detail)"
    @order-cancelled.window="cart = {}; showCart = false">
    <div wire:poll.10s="loadCurrentShift"
        class="shrink-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                @if(session('kiosk_device_id') || session('trusted_device_user_id'))
                    <span class="text-sm font-bold text-gray-800 dark:text-gray-100">Hi {{ auth()->user()->name }}</span>
                @endif
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
            <div class="flex items-center gap-3">
                @if(auth()->user()->currentShift())
                    <button wire:click="openCashDropModal" class="text-xs font-bold px-3 py-1.5 rounded-lg bg-emerald-600 text-white">💵 Drop Cash</button>
                @endif
                @if(session('kiosk_device_id') || session('trusted_device_user_id'))
                    <button @click="$wire.dispatch('lock-requested')"
                        class="text-xs font-bold px-3 py-1.5 rounded-lg bg-gray-700 text-white flex items-center gap-1 touch-manipulation">
                        🔒 Lock
                    </button>
                @endif
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ now()->format('M j, Y g:i A') }}
                </div>
            </div>
        </div>
    </div>

    @unless($isKioskDevice)
    <div class="hidden lg:block">
        <div class="grid grid-cols-12 gap-4 h-[calc(100vh-8rem)]">
            <div
                class="col-span-8 flex flex-col h-full bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 lg:m-0 lg:relative">
                    <div class="relative">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Search Item Name or Barcode..."
                            class="w-full px-4 py-3 pl-12 text-base lg:text-lg border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm {{ auth()->user()->currentShift() ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }}"
                            {{ auth()->user()->currentShift() ? 'autofocus' : 'disabled' }}>
                    </div>
                </div>
                <div
                    class="flex overflow-x-auto overflow-y-hidden p-2 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 space-x-2 flex-nowrap">
                    @foreach($categories as $category)
                        <button @click="if($wire.currentShift) { $wire.set('activeCategoryId', {{ $category->id }}) }"
                            class="px-3 py-2 lg:px-4 rounded-lg text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                            {{ auth()->user()->currentShift() ? '' : 'disabled' }}>{{ $category->name }}</button>
                    @endforeach
                </div>
                <div @if($deferProducts) wire:init="loadProducts" @endif
                    class="flex-1 overflow-y-auto p-4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-4 content-start relative">
                    @if(!auth()->user()->currentShift())
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div
                                    class="w-8 h-8 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                                        </path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Start shift to add items</p>
                            </div>
                        </div>
                    @elseif(!$selectedTableId)
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div
                                    class="w-8 h-8 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2">
                                        </path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Select a table (or Take Away) to start adding items</p>
                            </div>
                        </div>
                    @endif

                    @if($products->isEmpty())
                        @for($i = 0; $i < 8; $i++)
                            <div
                                class="animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center h-28 lg:h-32">
                            </div>
                        @endfor
                    @endif

                    @php $canAddToCart = auth()->user()->currentShift() && $selectedTableId; @endphp
                    @foreach($products as $product)
                        <div @if($canAddToCart)
                            @click="addProductToCart({{ $product->id }}, '{{ addslashes($product->name) }}', {{ (float) $product->price }}, {{ (int) ($product->available_stock ?? 0) }})"
                        @endif
                            class="relative {{ $canAddToCart ? 'cursor-pointer hover:border-amber-500 hover:shadow-md' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">
                                {{ $product->name }}
                            </div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">
                                ₦{{ number_format($product->price) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                            </div>
                        </div>
                    @endforeach
                    @foreach($menuItems as $menuItem)
                        @php $stock = $menuItem->available_stock; @endphp
                        <div @if(auth()->user()->currentShift())
                            @click="addMenuItemToCart({{ $menuItem->id }}, '{{ addslashes($menuItem->name) }}', {{ (float) $menuItem->sale_price }}, {{ $stock === null ? 'null' : $stock }})"
                        @endif
                            class="relative {{ auth()->user()->currentShift() && ($stock === null || $stock > 0) ? 'cursor-pointer hover:border-amber-500 hover:shadow-md' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 lg:p-4 flex flex-col items-center justify-center text-center transition-all h-28 lg:h-32 group touch-manipulation">
                            <div class="font-bold text-gray-800 dark:text-gray-200 line-clamp-2 text-sm lg:text-base">
                                {{ $menuItem->name }}
                            </div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono mt-1 text-sm lg:text-base">
                                ₦{{ number_format($menuItem->sale_price) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                {{ $stock === null ? 'Menu Item' : $stock . ' portions left' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div
                class="col-span-4 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col h-full lg:h-full max-h-[50vh] lg:max-h-none">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">
                        Select Table {{ !$selectedTableId ? '(required before adding items)' : '' }}
                    </label>
                    <div class="grid grid-cols-3 gap-2 max-h-48 overflow-y-auto">
                        <button type="button" wire:click="$set('selectedTableId', 'takeaway')"
                            class="p-2 rounded-lg text-xs font-bold border-2 transition-colors touch-manipulation {{ $selectedTableId === 'takeaway'
                                ? 'border-amber-500 bg-amber-500 text-white'
                                : 'border-blue-300 bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300 hover:border-blue-500' }}">
                            Take Away
                        </button>
                        @foreach($this->tables as $table)
                            @php
                                $hasActiveOrder = $table->orders->isNotEmpty();
                                $isOccupied = $table->status === 'occupied' && $hasActiveOrder;
                                $isSelected = (string) $selectedTableId === (string) $table->id;
                            @endphp
                            <button type="button" wire:click="$set('selectedTableId', {{ $table->id }})"
                                class="p-2 rounded-lg text-xs font-bold border-2 transition-colors touch-manipulation {{ $isSelected
                                    ? 'border-amber-500 bg-amber-500 text-white'
                                    : ($isOccupied
                                        ? 'border-red-300 bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'
                                        : 'border-green-300 bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-300 hover:border-green-500') }}">
                                {{ $table->name }}
                                <div class="text-[10px] font-normal opacity-80">{{ $isOccupied ? 'Occupied' : 'Free' }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto p-4 space-y-3 relative">
                    @if(!auth()->user()->currentShift())
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div
                                    class="w-8 h-8 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                                        </path>
                                    </svg>
                                </div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Cart disabled - start shift</p>
                            </div>
                        </div>
                    @endif
                    @if(!empty($existingItems))
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2">Existing Items</h4>
                        @foreach($existingItems as $id => $item)
                            <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2 opacity-75"
                                x-data="{ open: false }">
                                <div class="flex-1">
                                    <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x
                                        {{ $item['quantity'] }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="font-mono font-bold text-gray-700 dark:text-gray-300">
                                        ₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                                    <div class="relative">
                                        <button @click="open = !open" @click.outside="open = false"
                                            class="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition-colors text-gray-400 hover:text-gray-600">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z">
                                                </path>
                                            </svg>
                                        </button>
                                        <div x-show="open" x-transition
                                            class="absolute right-0 mt-1 w-32 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-10 overflow-hidden">
                                            <button wire:click="openReturnModal('{{ $id }}')" @click="open = false"
                                                class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">Return
                                                Item</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 mt-4" x-show="cartCount > 0">New
                            Items</h4>
                    @endif
                    {{-- Alpine-managed new cart items --}}
                    <template x-for="(item, key) in cart" :key="key">
                        <div
                            class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800 dark:text-gray-200" x-text="item.name">
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦<span x-text="item.price"></span>
                                    x <span x-text="item.qty"></span></div>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦<span
                                    x-text="(item.price * item.qty).toLocaleString()"></span></div>
                            <button @if(auth()->user()->currentShift()) @click="removeFromCart(key)" @endif
                                class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1"><span
                                    class="text-lg">×</span></button>
                        </div>
                    </template>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div
                        class="flex justify-between text-xl lg:text-2xl font-bold mb-4 text-gray-900 dark:text-gray-100">
                        <span>Total:</span><span>₦<span x-text="total.toLocaleString()"></span></span>
                    </div>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <button @if(auth()->user()->currentShift()) @click="sendToKitchen()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-blue-600 hover:bg-blue-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span
                                class="text-sm lg:text-base">Order</span></button>
                        <button @if(auth()->user()->currentShift()) @click="openPaymentModal()" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-green-600 hover:bg-green-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span
                                class="text-sm lg:text-base">Pay</span></button>
                        <button @if(auth()->user()->currentShift()) @click="$wire.call('cancelOrder')" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-4 px-4 rounded-lg flex flex-col items-center justify-center touch-manipulation transition-colors"><span
                                class="text-sm lg:text-base">Cancel</span></button>
                    </div>

                    {{-- Fast mark-paid: no amount typed, order's own outstanding total, two taps. --}}
                    <div class="mt-3">
                        <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-2">Mark Paid</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button wire:click="markPaidFast('cash')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">Cash</button>
                            <button wire:click="markPaidFast('pos')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">POS</button>
                            <button wire:click="markPaidFast('transfer')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">Transfer</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="lg:hidden min-h-screen flex flex-col">
        <div
            class="bg-white dark:bg-gray-900 px-4 py-3 border-b border-gray-200 dark:border-gray-700 fixed top-[62px] left-0 right-0 z-20">
            <div class="relative">
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search products..."
                    class="w-full px-4 py-3 pl-12 text-base border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm {{ auth()->user()->currentShift() ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-blue-500' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }}"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                <div class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                @if($search)
                    <button @click="if($wire.currentShift) { $wire.call('clearSearch') }"
                        class="absolute right-3 top-1/2 -translate-y-1/2 {{ auth()->user()->currentShift() ? 'text-gray-400 hover:text-gray-600 cursor-pointer' : 'text-gray-300 cursor-not-allowed' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                            </path>
                        </svg>
                    </button>
                @endif
            </div>
        </div>

        <div
            class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 fixed top-[137px] left-0 right-0 z-20">
            <div class="flex overflow-x-auto overflow-y-hidden p-3 space-x-2 flex-nowrap">
                <button @click="if($wire.currentShift) { $wire.set('activeCategoryId', null) }"
                    class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ !$activeCategoryId ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                    All
                </button>
                @foreach($categories as $category)
                    <button @click="if($wire.currentShift) { $wire.set('activeCategoryId', {{ $category->id }}) }"
                        class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap transition-colors touch-manipulation flex-shrink-0 {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 cursor-pointer' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 dark:text-gray-500 cursor-not-allowed') }}"
                        {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="flex-1 overflow-y-auto bg-gray-50 dark:bg-gray-900 p-4 mt-[50px] mb-[120px] relative">
            @if(!auth()->user()->currentShift())
                <div
                    class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                    <div
                        class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-3 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                        <div
                            class="w-6 h-6 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                            <svg class="w-3 h-3 text-red-600 dark:text-red-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                                </path>
                            </svg>
                        </div>
                        <p class="text-xs font-medium text-gray-900 dark:text-white">Start shift to add items</p>
                    </div>
                </div>
            @elseif(!$selectedTableId)
                <div
                    class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                    <div
                        class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-3 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                        <div
                            class="w-6 h-6 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                            <svg class="w-3 h-3 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2">
                                </path>
                            </svg>
                        </div>
                        <p class="text-xs font-medium text-gray-900 dark:text-white">Pick a table below to start adding items</p>
                    </div>
                </div>
            @endif
            @php $canAddToCartMobile = auth()->user()->currentShift() && $selectedTableId; @endphp
            {{-- Desktop grid above already carries wire:init="loadProducts" —
                 both grids are always in the DOM regardless of viewport (CSS
                 only toggles visibility), so only one needs to fire it. --}}
            <div class="grid grid-cols-2 gap-3">
                @if($products->isEmpty())
                    @for($i = 0; $i < 8; $i++)
                        <div
                            class="animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center h-28">
                        </div>
                    @endfor
                @endif

                @foreach($products as $product)
                    <div @if($canAddToCartMobile)
                        @click="addProductToCart({{ $product->id }}, '{{ addslashes($product->name) }}', {{ (float) $product->price }}, {{ (int) ($product->available_stock ?? 0) }})"
                    @endif
                        class="relative {{ $canAddToCartMobile ? 'hover:border-amber-500 active:scale-95' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation">
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">
                            {{ $product->name }}
                        </div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">
                            ₦{{ number_format($product->price) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ $product->inventory->map(fn($inv) => $inv->warehouse->name . ': ' . $inv->quantity)->join(', ') }}
                        </div>
                    </div>
                @endforeach
                @foreach($menuItems as $menuItem)
                    @php $stock = $menuItem->available_stock; @endphp
                    <div @if($canAddToCartMobile)
                        @click="addMenuItemToCart({{ $menuItem->id }}, '{{ addslashes($menuItem->name) }}', {{ (float) $menuItem->sale_price }}, {{ $stock === null ? 'null' : $stock }})"
                    @endif
                        class="relative {{ $canAddToCartMobile && ($stock === null || $stock > 0) ? 'hover:border-amber-500 active:scale-95' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col text-center transition-all touch-manipulation">
                        <div class="font-bold text-gray-800 dark:text-gray-200 text-sm line-clamp-2 mb-2">
                            {{ $menuItem->name }}
                        </div>
                        <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-lg">
                            ₦{{ number_format($menuItem->sale_price) }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">
                            {{ $stock === null ? 'Menu Item' : $stock . ' left' }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div
            class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 py-3 fixed bottom-[62px] left-0 right-0 z-25">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="text-center">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Total</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦<span
                                x-text="total.toLocaleString()"></span></div>
                    </div>
                    <div class="text-center" x-show="cartCount + existingCount > 0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Items</div>
                        <div class="text-lg font-bold text-blue-600" x-text="cartCount + existingCount"></div>
                    </div>
                </div>
                <div class="flex space-x-2">
                </div>
            </div>
        </div>

        <div
            class="bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 py-3 fixed bottom-0 left-0 right-0 z-25">
            <div class="flex items-center justify-between">
                <select @if(auth()->user()->currentShift()) wire:model.live="selectedTableId" @endif
                    class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg {{ auth()->user()->currentShift() ? 'bg-gray-50 dark:bg-gray-800 text-gray-800 dark:text-gray-200 cursor-pointer' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed' }} font-bold"
                    {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                    <option value="">Table</option>
                    <option value="takeaway">Take Away</option>
                    @foreach($this->tables as $table)
                        @php
                            $hasActiveOrder = $table->orders->isNotEmpty();
                            $isOccupied = $table->status === 'occupied' && $hasActiveOrder;
                        @endphp
                        <option value="{{ $table->id }}" class="{{ $isOccupied ? 'text-red-600' : 'text-green-600' }}">
                            {{ $table->name }}
                        </option>
                    @endforeach
                </select>
                <button @click="showCart = !showCart" class="relative p-2 bg-blue-600 text-white rounded-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.1 5H19M7 13v8a2 2 0 002 2h10a2 2 0 002-2v-3">
                        </path>
                    </svg>
                    <span x-show="cartCount + existingCount > 0" x-text="cartCount + existingCount"
                        class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center"></span>
                </button>
            </div>
        </div>

        <div x-show="showCart" x-cloak class="fixed inset-0 bg-black bg-opacity-50 z-50 flex">
            <div class="ml-auto w-80 bg-white dark:bg-gray-900 h-full flex flex-col">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">🛒 Cart</h3>
                    <button @click="showCart = false" class="text-gray-400 hover:text-red-500 p-2">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="px-4 py-2 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 10h18M3 6h18M3 14h18M3 18h18"></path>
                    </svg>
                    @if($selectedTableId === 'takeaway')
                        <span>Take Away</span>
                    @elseif($selectedTableId)
                        <span>{{ $this->tables->find($selectedTableId)?->name ?? 'Table' }}</span>
                    @else
                        <span class="italic">No table selected</span>
                    @endif
                </div>

                <div class="flex-1 overflow-y-auto p-4 space-y-3 relative">
                    @if(!auth()->user()->currentShift())
                        <div
                            class="absolute inset-0 bg-gray-900/30 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-3 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <div
                                    class="w-6 h-6 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-2">
                                    <svg class="w-3 h-3 text-red-600 dark:text-red-400" fill="none" stroke="currentColor"
                                        viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z">
                                        </path>
                                    </svg>
                                </div>
                                <p class="text-xs font-medium text-gray-900 dark:text-white">Cart disabled</p>
                            </div>
                        </div>
                    @endif
                    @if(!empty($existingItems))
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2">Existing Items</h4>
                        @foreach($existingItems as $id => $item)
                            <div x-data="{ offset: 0, startX: 0 }"
                                class="relative overflow-hidden border-b border-gray-200 dark:border-gray-700 pb-2 opacity-75">
                                <div class="absolute inset-y-0 right-0 flex items-center">
                                    <button wire:click="openReturnModal('{{ $id }}')"
                                        class="h-full px-4 bg-red-600 text-white text-sm font-bold flex items-center">Return</button>
                                </div>
                                <div class="flex justify-between items-center bg-white dark:bg-gray-900 relative z-10 transition-transform duration-100"
                                    :style="`transform: translateX(${offset}px)`"
                                    @touchstart="startX = $event.touches[0].clientX"
                                    @touchmove="offset = Math.min(0, Math.max(-70, $event.touches[0].clientX - startX))"
                                    @touchend="offset = offset < -35 ? -70 : 0">
                                    <div class="flex-1">
                                        <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $item['name'] }}
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">₦{{ $item['price'] }} x
                                            {{ $item['quantity'] }}
                                        </div>
                                    </div>
                                    <div class="font-mono font-bold text-gray-700 dark:text-gray-300">
                                        ₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                                </div>
                            </div>
                        @endforeach
                    @endif

                    {{-- Alpine-managed new cart items --}}
                    <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-2 {{ !empty($existingItems) ? 'mt-4' : '' }}"
                        x-show="cartCount > 0">New Items</h4>
                    <template x-for="(item, key) in cart" :key="key">
                        <div
                            class="flex justify-between items-center border-b border-gray-200 dark:border-gray-700 pb-2">
                            <div class="flex-1">
                                <div class="font-bold text-sm text-gray-800 dark:text-gray-200" x-text="item.name">
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦<span x-text="item.price"></span>
                                    x <span x-text="item.qty"></span></div>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300">₦<span
                                    x-text="(item.price * item.qty).toLocaleString()"></span></div>
                            <button @if(auth()->user()->currentShift()) @click="removeFromCart(key)" @endif
                                class="ml-3 {{ auth()->user()->currentShift() ? 'text-red-500 hover:text-red-700 cursor-pointer' : 'text-gray-400 cursor-not-allowed' }} touch-manipulation p-1">
                                <span class="text-lg">×</span>
                            </button>
                        </div>
                    </template>

                    <div x-show="cartCount === 0 && {{ count($existingItems) }} === 0"
                        class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <div class="text-4xl mb-2">🛒</div>
                        <div>Your cart is empty</div>
                        <div class="text-sm">Tap on products to add them</div>
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800"
                    x-show="cartCount > 0 || existingCount > 0">
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
                        <button @if(auth()->user()->currentShift()) @click="$wire.call('cancelOrder')" @endif
                            class="{{ auth()->user()->currentShift() ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-gray-400 cursor-not-allowed' }} text-white font-bold py-3 px-4 rounded-lg text-sm transition-colors touch-manipulation">
                            Cancel
                        </button>
                    </div>

                    <div class="mt-3">
                        <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase mb-2">Mark Paid</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button wire:click="markPaidFast('cash')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">Cash</button>
                            <button wire:click="markPaidFast('pos')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">POS</button>
                            <button wire:click="markPaidFast('transfer')"
                                class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-lg text-sm">Transfer</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endunless

    @if($isKioskDevice)
        @php
            $canAddToCartKiosk = auth()->user()->currentShift() && $selectedTableId;
            $groupedKioskTables = $this->tables->groupBy('location');
            $selectedKioskTableName = $selectedTableId === 'takeaway'
                ? 'Take Away'
                : ($selectedTableId ? ($this->tables->find($selectedTableId)?->name ?? 'Table') : null);
        @endphp

        <div class="flex-1 min-h-0 flex">
            {{-- LEFT: PRODUCT ZONE --}}
            <div class="flex-1 min-h-0 flex flex-col">
                <div
                    class="shrink-0 flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                    <div class="flex-1 flex items-center gap-2 overflow-x-auto">
                        <button @click="if($wire.currentShift) { $wire.set('activeCategoryId', null) }"
                            class="shrink-0 h-14 px-8 rounded-full text-lg font-bold whitespace-nowrap transition-colors touch-manipulation {{ !$activeCategoryId ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 cursor-not-allowed') }}"
                            {{ auth()->user()->currentShift() ? '' : 'disabled' }}>All</button>
                        @foreach($categories as $category)
                            <button
                                @click="if($wire.currentShift) { $wire.set('activeCategoryId', {{ $category->id }}) }"
                                class="shrink-0 h-14 px-8 rounded-full text-lg font-bold whitespace-nowrap transition-colors touch-manipulation {{ $activeCategoryId === $category->id ? 'bg-amber-500 text-white' : (auth()->user()->currentShift() ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' : 'bg-gray-200 dark:bg-gray-600 text-gray-400 cursor-not-allowed') }}"
                                {{ auth()->user()->currentShift() ? '' : 'disabled' }}>{{ $category->name }}</button>
                        @endforeach
                    </div>
                    <div class="relative shrink-0 w-72">
                        <input type="text" wire:model.live.debounce.300ms="search"
                            placeholder="Search or scan barcode..."
                            class="w-full h-14 pl-4 pr-10 text-lg border border-gray-300 dark:border-gray-600 rounded-xl {{ auth()->user()->currentShift() ? 'bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500' : 'bg-gray-100 dark:bg-gray-700 text-gray-400 cursor-not-allowed' }}"
                            {{ auth()->user()->currentShift() ? '' : 'disabled' }}>
                        @if($search)
                            <button @click="if($wire.currentShift) { $wire.call('clearSearch') }"
                                class="absolute right-2 top-1/2 -translate-y-1/2 w-10 h-10 flex items-center justify-center text-gray-400 hover:text-gray-600 touch-manipulation">
                                <span class="text-2xl leading-none">&times;</span>
                            </button>
                        @endif
                    </div>
                </div>

                <div @if($deferProducts) wire:init="loadProducts" @endif
                    class="flex-1 min-h-0 overflow-y-auto overscroll-contain p-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-3 content-start relative">
                    @if(!auth()->user()->currentShift())
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Start shift to add items</p>
                            </div>
                        </div>
                    @elseif(!$selectedTableId)
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <div
                                class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 text-center border border-gray-200 dark:border-gray-700 max-w-xs">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">Select a table to start
                                    adding items</p>
                            </div>
                        </div>
                    @endif

                    @if($products->isEmpty())
                        @for($i = 0; $i < 8; $i++)
                            <div
                                class="animate-pulse bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl h-40">
                            </div>
                        @endfor
                    @endif

                    @php
                        // Availability is shown as a discrete state (Low/Sold
                        // out/nothing), never the raw count — the exact number
                        // must not be readable from this page (view-source,
                        // network tab, or an onclick attribute), since it
                        // would leak the expected quantity to whoever is
                        // meant to be blind-counted against it later. 5 is a
                        // simple default; no per-product threshold exists yet
                        // to reuse.
                        $kioskLowStockThreshold = 5;
                    @endphp
                    @foreach($products as $product)
                        @php
                            $kioskStockLevel = (int) ($product->available_stock ?? 0);
                            $kioskSoldOut = $kioskStockLevel <= 0;
                            $kioskLowStock = !$kioskSoldOut && $kioskStockLevel <= $kioskLowStockThreshold;
                        @endphp
                        <div @if($canAddToCartKiosk && !$kioskSoldOut)
                            @click="addProductToCart({{ $product->id }}, '{{ addslashes($product->name) }}', {{ (float) $product->price }})"
                            @endif
                            class="relative select-none {{ $canAddToCartKiosk && !$kioskSoldOut ? 'cursor-pointer active:scale-[0.97] active:brightness-125 hover:border-amber-500' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col items-center justify-center text-center transition-all h-40 touch-manipulation">
                            <div class="font-semibold text-gray-800 dark:text-gray-200 line-clamp-2 text-lg">
                                {{ $product->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-2xl tabular-nums mt-1">
                                ₦{{ number_format($product->price) }}</div>
                            @if($kioskSoldOut)
                                <span
                                    class="mt-1 text-[11px] font-bold uppercase tracking-wide text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/30 px-2 py-0.5 rounded-full">Sold out</span>
                            @elseif($kioskLowStock)
                                <span
                                    class="mt-1 text-[11px] font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full">Low</span>
                            @endif
                            <span x-show="cart['{{ $product->id }}']"
                                x-text="'×' + (cart['{{ $product->id }}']?.qty ?? '')"
                                class="absolute top-1.5 right-1.5 min-w-[22px] h-[22px] px-1 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center"></span>
                        </div>
                    @endforeach
                    @foreach($menuItems as $menuItem)
                        @php
                            $kioskMenuStockLevel = $menuItem->available_stock;
                            $kioskMenuSoldOut = $kioskMenuStockLevel !== null && $kioskMenuStockLevel <= 0;
                            $kioskMenuLowStock = !$kioskMenuSoldOut && $kioskMenuStockLevel !== null && $kioskMenuStockLevel <= $kioskLowStockThreshold;
                        @endphp
                        <div @if(auth()->user()->currentShift() && $selectedTableId && !$kioskMenuSoldOut)
                            @click="addMenuItemToCart({{ $menuItem->id }}, '{{ addslashes($menuItem->name) }}', {{ (float) $menuItem->sale_price }})"
                            @endif
                            class="relative select-none {{ auth()->user()->currentShift() && $selectedTableId && !$kioskMenuSoldOut ? 'cursor-pointer active:scale-[0.97] active:brightness-125 hover:border-amber-500' : 'cursor-not-allowed opacity-60' }} bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3 flex flex-col items-center justify-center text-center transition-all h-40 touch-manipulation">
                            <div class="font-semibold text-gray-800 dark:text-gray-200 line-clamp-2 text-lg">
                                {{ $menuItem->name }}</div>
                            <div class="text-amber-600 dark:text-amber-500 font-mono font-bold text-2xl tabular-nums mt-1">
                                ₦{{ number_format($menuItem->sale_price) }}</div>
                            @if($kioskMenuSoldOut)
                                <span
                                    class="mt-1 text-[11px] font-bold uppercase tracking-wide text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/30 px-2 py-0.5 rounded-full">Sold out</span>
                            @elseif($kioskMenuLowStock)
                                <span
                                    class="mt-1 text-[11px] font-bold uppercase tracking-wide text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full">Low</span>
                            @endif
                            <span x-show="cart['menu_{{ $menuItem->id }}']"
                                x-text="'×' + (cart['menu_{{ $menuItem->id }}']?.qty ?? '')"
                                class="absolute top-1.5 right-1.5 min-w-[22px] h-[22px] px-1 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center"></span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- RIGHT: ORDER PANEL --}}
            <div class="w-[440px] shrink-0 flex flex-col border-l border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                <button type="button" @click="showTablePicker = true"
                    class="shrink-0 h-16 px-4 flex items-center justify-between border-b border-gray-200 dark:border-gray-700 touch-manipulation {{ !$selectedTableId ? 'animate-pulse bg-amber-50 dark:bg-amber-900/20' : '' }}">
                    <span
                        class="flex items-center gap-2 text-lg font-bold {{ $selectedTableId ? 'text-gray-900 dark:text-white' : 'text-amber-600 dark:text-amber-400' }}">
                        🪑 {{ $selectedKioskTableName ?? 'Select table…' }}
                    </span>
                    <span class="text-gray-400 text-xl">▸</span>
                </button>

                <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain p-4 space-y-2 relative">
                    @if(!auth()->user()->currentShift())
                        <div
                            class="absolute inset-0 bg-gray-900/20 backdrop-blur-[1px] z-10 flex items-center justify-center rounded-lg">
                            <p
                                class="text-sm font-medium text-gray-900 dark:text-white bg-white dark:bg-gray-800 rounded-lg shadow-lg p-4 border border-gray-200 dark:border-gray-700">
                                Start shift to add items</p>
                        </div>
                    @endif

                    @if(!empty($existingItems))
                        <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-1">Existing Items</h4>
                        @foreach($existingItems as $id => $item)
                            <div
                                class="flex items-center justify-between gap-2 min-h-[72px] border-b border-gray-200 dark:border-gray-700 pb-2 opacity-75">
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-sm text-gray-800 dark:text-gray-200 truncate">
                                        {{ $item['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        ₦{{ number_format($item['price']) }} each</div>
                                </div>
                                <div class="font-mono font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                                    ₦{{ number_format($item['price'] * $item['quantity']) }}</div>
                                <button wire:click="openReturnModal('{{ $id }}')"
                                    class="h-12 px-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 text-xs font-bold touch-manipulation">
                                    Return</button>
                            </div>
                        @endforeach
                    @endif

                    <h4 class="text-sm font-bold text-gray-600 dark:text-gray-400 mb-1 mt-3" x-show="cartCount > 0">
                        New Items</h4>
                    <template x-for="(item, key) in cart" :key="key">
                        <div class="flex items-center justify-between gap-2 min-h-[72px] border-b border-gray-200 dark:border-gray-700 pb-2"
                            x-effect="key === lastAddedKey && $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }))">
                            <div class="flex-1 min-w-0">
                                <div class="font-semibold text-gray-800 dark:text-gray-200 truncate" x-text="item.name">
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">₦<span
                                        x-text="Number(item.price).toLocaleString()"></span> each</div>
                            </div>
                            <div class="flex items-center gap-1.5 shrink-0">
                                <button @click="decrementCartItem(key)"
                                    class="h-12 w-12 rounded-lg border border-gray-300 dark:border-gray-600 text-xl font-bold touch-manipulation active:bg-gray-100 dark:active:bg-gray-700">−</button>
                                <span class="w-8 text-center text-xl font-bold tabular-nums" x-text="item.qty"></span>
                                <button @click="incrementCartItem(key)"
                                    class="h-12 w-12 rounded-lg border border-gray-300 dark:border-gray-600 text-xl font-bold touch-manipulation active:bg-gray-100 dark:active:bg-gray-700">+</button>
                            </div>
                            <div class="font-mono font-bold text-gray-700 dark:text-gray-300 tabular-nums w-20 text-right"
                                x-text="'₦' + (item.price * item.qty).toLocaleString()"></div>
                            <button @click="removeFromCart(key)"
                                class="h-12 w-12 flex items-center justify-center text-red-500 touch-manipulation">
                                <span class="text-xl">✕</span>
                            </button>
                        </div>
                    </template>

                    <div x-show="cartCount === 0 && {{ count($existingItems) }} === 0"
                        class="text-center py-10 text-gray-500 dark:text-gray-400">
                        <div class="text-4xl mb-2">🛒</div>
                        <div class="font-medium">No items yet</div>
                        <div class="text-sm">Tap a product to start the order.</div>
                    </div>
                </div>

                <div x-show="undoStack" x-cloak x-transition
                    class="shrink-0 mx-4 mb-2 px-4 py-3 rounded-xl bg-gray-800 text-white flex items-center justify-between gap-3">
                    <span class="text-sm" x-text="undoStack ? 'Removed ' + undoStack.item.name : ''"></span>
                    <button @click="undoRemove()"
                        class="text-amber-400 font-bold text-sm shrink-0 h-10 px-3 touch-manipulation">Undo</button>
                </div>

                <div class="shrink-0 p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700"
                    x-data="{ showMarkPaidMethods: false }" x-effect="if (cartCount > 0 || existingCount === 0) showMarkPaidMethods = false">

                    {{-- STATE 1: unsent new items — Place Order is the only path forward --}}
                    <template x-if="cartCount > 0">
                        <div>
                            <button @if(auth()->user()->currentShift()) @click="sendToKitchen()" @endif
                                :disabled="isLoading || !$wire.selectedTableId"
                                :class="(isLoading || !$wire.selectedTableId) ? 'bg-gray-400 cursor-not-allowed' : 'bg-emerald-600 hover:bg-emerald-700 cursor-pointer'"
                                class="w-full h-16 rounded-xl text-white text-xl font-semibold touch-manipulation transition-colors">
                                <span
                                    x-text="isLoading ? 'Sending…' : ('Place Order · ₦' + newCartTotal.toLocaleString())"></span>
                            </button>
                            <p class="text-xs text-center text-gray-500 dark:text-gray-400 mt-1"
                                x-show="!$wire.selectedTableId">Select a table to continue</p>
                            <button @click="clearNewItems()"
                                class="mt-2 w-full h-10 rounded-lg text-sm font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 touch-manipulation">
                                Clear
                            </button>
                        </div>
                    </template>

                    {{-- STATE 2: nothing new, but existing (already-sent) items are unpaid --}}
                    <template x-if="cartCount === 0 && existingCount > 0">
                        <div>
                            <button @click="showMarkPaidMethods = !showMarkPaidMethods"
                                class="w-full h-16 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xl font-semibold touch-manipulation transition-colors">
                                <span x-text="'Mark Paid · ₦' + existingTotal.toLocaleString()"></span>
                            </button>
                            <div class="grid grid-cols-3 gap-2 mt-2" x-show="showMarkPaidMethods" x-transition>
                                <button wire:click="markPaidFast('cash')" wire:loading.attr="disabled"
                                    wire:target="markPaidFast"
                                    class="h-14 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm touch-manipulation">
                                    Cash</button>
                                <button wire:click="markPaidFast('pos')" wire:loading.attr="disabled"
                                    wire:target="markPaidFast"
                                    class="h-14 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm touch-manipulation">
                                    POS</button>
                                <button wire:click="markPaidFast('transfer')" wire:loading.attr="disabled"
                                    wire:target="markPaidFast"
                                    class="h-14 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm touch-manipulation">
                                    Transfer</button>
                            </div>
                            <button @if(auth()->user()->currentShift()) @click="openPaymentModal()" @endif
                                class="mt-2 w-full h-10 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 touch-manipulation">
                                Split payment…
                            </button>
                        </div>
                    </template>

                    {{-- STATE 3: nothing pending either way --}}
                    <template x-if="cartCount === 0 && existingCount === 0">
                        <div
                            class="w-full h-16 rounded-xl bg-gray-200 dark:bg-gray-700 text-gray-400 dark:text-gray-500 text-lg font-semibold flex items-center justify-center">
                            No pending items
                        </div>
                    </template>

                    {{-- Cancel voids already-sent orders (reason required) — orthogonal
                         to whichever state above is showing, so it's available whenever
                         there's anything sent to void, new cart or not. --}}
                    <button x-show="existingCount > 0" @if(auth()->user()->currentShift()) @click="$wire.call('cancelOrder')" @endif
                        class="mt-2 w-full h-10 rounded-lg text-sm font-semibold border-2 border-red-500 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 touch-manipulation">
                        Cancel Order
                    </button>
                </div>
            </div>
        </div>

        {{-- Full-screen table picker overlay --}}
        <div x-show="showTablePicker" x-cloak
            x-effect="if ($wire.selectedTableId && $wire.selectedTableId == pendingTableId) { showTablePicker = false; pendingTableId = null }"
            class="fixed inset-0 z-[70] bg-gray-900/80 backdrop-blur-sm flex flex-col">
            <div class="shrink-0 flex items-center justify-between px-6 py-4 border-b border-gray-700">
                <h2 class="text-2xl font-bold text-white">Select a Table</h2>
                <button @click="showTablePicker = false"
                    class="w-14 h-14 flex items-center justify-center rounded-full bg-gray-800 text-white text-2xl touch-manipulation">✕</button>
            </div>
            <div class="flex-1 min-h-0 overflow-y-auto overscroll-contain p-6">
                <button type="button" wire:click="$set('selectedTableId', 'takeaway')" @click="pendingTableId = 'takeaway'"
                    class="w-full min-h-[88px] mb-6 rounded-xl text-xl font-bold border-2 touch-manipulation {{ $selectedTableId === 'takeaway' ? 'border-amber-500 bg-amber-500 text-white' : 'border-blue-300 bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300' }}">
                    Take Away
                </button>

                @foreach($groupedKioskTables as $kioskLocation => $tablesInKioskLocation)
                    <div class="mb-6">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-gray-400 mb-3">
                            {{ $kioskLocation ?: 'Other' }}</h3>
                        <div class="grid grid-cols-4 gap-3">
                            @foreach($tablesInKioskLocation as $table)
                                @php
                                    $kioskHasActiveOrder = $table->orders->isNotEmpty();
                                    $kioskIsOccupied = $table->status === 'occupied' && $kioskHasActiveOrder;
                                    $kioskIsSelected = (string) $selectedTableId === (string) $table->id;
                                @endphp
                                <button type="button" wire:click="$set('selectedTableId', {{ $table->id }})"
                                    @click="pendingTableId = {{ $table->id }}"
                                    class="min-h-[88px] rounded-xl p-3 text-lg font-bold border-2 touch-manipulation transition-colors {{ $kioskIsSelected
                                        ? 'border-amber-500 bg-amber-500 text-white'
                                        : ($kioskIsOccupied
                                            ? 'border-red-400 bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                                            : 'border-green-400 bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300') }}">
                                    {{ $table->name }}
                                    <div class="text-xs font-normal opacity-80 mt-1">
                                        {{ $kioskIsOccupied ? 'Occupied' : 'Free' }}</div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div x-show="showPaymentModal" x-cloak
        class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
        <div
            class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700 relative max-h-[90vh] overflow-y-auto">

            <div
                class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0 z-50">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">💰 Checkout</h3>
                <button @click="showPaymentModal = false"
                    class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                        class="text-2xl">&times;</span></button>
            </div>

            <div class="p-6 space-y-4">
                <div class="text-center mb-6">
                    <div class="text-sm text-gray-500 dark:text-gray-400 uppercase tracking-wider font-bold">Total Due
                    </div>
                    <div class="text-3xl lg:text-4xl font-black text-gray-900 dark:text-white">₦<span
                            x-text="total.toLocaleString()"></span></div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Payment Type</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            @click="paymentType = 'single'; paidAmount = total; splitCashAmount = 0; splitPosAmount = 0;"
                            :class="paymentType === 'single' ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600'"
                            class="p-3 border rounded-lg font-bold text-sm transition-colors touch-manipulation">
                            Single Payment
                        </button>
                        <button @click="paymentType = 'split'; splitCashAmount = 0; splitPosAmount = 0;"
                            :class="paymentType === 'split' ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600'"
                            class="p-3 border rounded-lg font-bold text-sm transition-colors touch-manipulation">
                            Split Payment
                        </button>
                    </div>
                </div>

                <div x-show="paymentType === 'single'">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Amount
                            Received</label>
                        <div class="relative">
                            <span
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-lg">₦</span>
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
                        <div class="text-2xl font-black text-red-600 dark:text-red-400">₦<span
                                x-text="balance.toLocaleString()"></span></div>
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
                            <button @click="$wire.set('showGuestModal', true)"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-xl font-bold flex items-center justify-center touch-manipulation">
                                +
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Payment
                            Method</label>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach(['cash' => '💵 Cash', 'pos' => '💳 POS', 'transfer' => '🏦 Transfer'] as $key => $label)
                                <button @click="paymentMethod = '{{ $key }}'"
                                    :class="paymentMethod === '{{ $key }}' ? 'bg-blue-600 text-white border-blue-600' : 'hover:bg-gray-50 dark:hover:bg-gray-700 border-gray-300 dark:border-gray-600'"
                                    class="p-3 border rounded-lg font-bold text-sm transition-colors touch-manipulation">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div x-show="paymentType === 'split'" class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">💵 Cash
                            Amount</label>
                        <div class="relative">
                            <span
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-lg">₦</span>
                            <input type="number" x-model.number="splitCashAmount" inputmode="decimal"
                                class="w-full pl-8 pr-4 py-4 text-xl font-bold border rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white touch-manipulation"
                                placeholder="0.00">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">💳 POS
                            Amount</label>
                        <div class="relative">
                            <span
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-lg">₦</span>
                            <input type="number" x-model.number="splitPosAmount" inputmode="decimal"
                                class="w-full pl-8 pr-4 py-4 text-xl font-bold border rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white touch-manipulation"
                                placeholder="0.00">
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-lg border border-gray-200 dark:border-gray-700">
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600 dark:text-gray-400">Total Due:</span>
                            <span class="font-bold">₦<span x-text="total.toLocaleString()"></span></span>
                        </div>
                        <div class="flex justify-between text-sm mb-2">
                            <span class="text-gray-600 dark:text-gray-400">Split Total:</span>
                            <span class="font-bold"
                                :class="(splitCashAmount + splitPosAmount) <= total ? 'text-green-600' : 'text-red-600'">
                                ₦<span x-text="(splitCashAmount + splitPosAmount).toLocaleString()"></span>
                            </span>
                        </div>
                        <div class="border-t border-gray-300 dark:border-gray-600 pt-2 mt-2">
                            <div class="flex justify-between font-bold">
                                <span class="text-gray-700 dark:text-gray-300">Remaining:</span>
                                <span
                                    :class="(total - (splitCashAmount + splitPosAmount)) >= -0.01 ? 'text-green-600' : 'text-red-600'">
                                    ₦<span
                                        x-text="Math.max(total - (splitCashAmount + splitPosAmount), 0).toLocaleString()"></span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div x-show="(splitCashAmount + splitPosAmount) - total > 0.01"
                        class="bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg border border-yellow-200 dark:border-yellow-800">
                        <p class="text-sm text-yellow-800 dark:text-yellow-300 font-medium">
                            ⚠️ Cash + POS cannot exceed the total amount
                        </p>
                    </div>

                    <div x-show="total - (splitCashAmount + splitPosAmount) > 0.01"
                        class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg border border-red-200 dark:border-red-800 text-center animate-pulse">
                        <span class="text-red-700 dark:text-red-400 font-bold text-sm">⚠️ Remaining Debt:</span>
                        <div class="text-2xl font-black text-red-600 dark:text-red-400">₦<span
                                x-text="(total - (splitCashAmount + splitPosAmount)).toLocaleString()"></span></div>
                    </div>

                    <div x-show="total - (splitCashAmount + splitPosAmount) > 0.01">
                        <label class="block text-sm font-bold text-red-600 mb-1">Select Guest for Debt *</label>
                        <div class="flex gap-2">
                            <select wire:model="selectedGuestId"
                                class="w-full p-3 text-base border border-red-300 rounded-lg dark:bg-gray-800 dark:border-red-900 touch-manipulation">
                                <option value="">-- Select Guest --</option>
                                @foreach(\App\Models\Guest::all() as $guest)
                                    <option value="{{ $guest->id }}">{{ $guest->name }}</option>
                                @endforeach
                            </select>
                            <button @click="$wire.set('showGuestModal', true)"
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-xl font-bold flex items-center justify-center touch-manipulation">
                                +
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3 sticky bottom-0 bg-white dark:bg-gray-900">
                <button @click="showPaymentModal = false"
                    class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 touch-manipulation">Cancel</button>
                <button @click="confirmPayment()"
                    :disabled="isLoading || (paymentType === 'split' && ((splitCashAmount + splitPosAmount) - total > 0.01 || ((total - (splitCashAmount + splitPosAmount)) > 0.01 && !$wire.selectedGuestId)))"
                    :class="isLoading || (paymentType === 'split' && ((splitCashAmount + splitPosAmount) - total > 0.01 || ((total - (splitCashAmount + splitPosAmount)) > 0.01 && !$wire.selectedGuestId))) ? 'bg-gray-400 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700'"
                    class="px-4 py-3 font-bold text-white rounded-lg shadow-lg shadow-green-600/30 flex items-center justify-center gap-2 touch-manipulation"><span
                        x-text="isLoading ? 'Processing…' : 'Confirm'"></span></button>
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
                    <button @click="$wire.set('showGuestModal', false)"
                        class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
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
                    <button @click="$wire.set('showGuestModal', false)"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg touch-manipulation">Cancel</button>
                    <button @click="$wire.call('saveNewGuest')"
                        class="px-4 py-3 font-bold text-white bg-blue-600 rounded-lg hover:bg-blue-700 touch-manipulation">Save
                        Guest</button>
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
                existingCount: 0,
                showCart: false,
                showPaymentModal: false,
                paidAmount: 0,
                paymentMethod: 'cash',
                paymentType: 'single',
                splitCashAmount: 0,
                splitPosAmount: 0,
                isLoading: false,

                // Kiosk-only UI state (table picker overlay + cart row undo)
                showTablePicker: false,
                pendingTableId: null,
                lastAddedKey: null,
                undoStack: null,
                undoTimer: null,

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
                 *
                 * availableStock is only ever passed by the admin Sales page
                 * and staff-phone layouts (unchanged, legacy behavior) — the
                 * kiosk shell never sends it, since the exact stock figure
                 * must not be exposed to whoever taps the button (blind
                 * counting control). Without it, the kiosk relies entirely
                 * on the server's OrderSplitter/InventoryService check when
                 * the order is actually sent; sold-out kiosk cards are simply
                 * not tappable at all, so this path never even runs for them.
                 */
                addProductToCart(id, name, price, availableStock = undefined) {
                    const key = String(id);
                    const currentQty = this.cart[key] ? this.cart[key].qty : 0;

                    if (availableStock !== undefined && availableStock <= currentQty) {
                        alert('Out of stock: only ' + availableStock + ' available.');
                        return;
                    }

                    if (this.cart[key]) {
                        this.cart[key].qty++;
                    } else {
                        this.cart[key] = {
                            name, price, qty: 1, type: 'product',
                            ...(availableStock !== undefined ? { stock: availableStock } : {}),
                        };
                    }
                    this.lastAddedKey = key;
                },

                /**
                 * Add a menu item to cart — must hit server for ingredient
                 * availability check either way. availableStock is legacy,
                 * admin/staff-phone-only — see addProductToCart.
                 */
                async addMenuItemToCart(id, name, price, availableStock = undefined) {
                    if (this.isLoading) return;
                    const key = 'menu_' + id;
                    const currentQty = this.cart[key] ? this.cart[key].qty : 0;

                    if (availableStock !== undefined && availableStock !== null && availableStock <= currentQty) {
                        alert('Out of stock: only ' + availableStock + ' portions available.');
                        return;
                    }

                    this.isLoading = true;
                    try {
                        const result = await this.$wire.validateAndAddToCart(id, 'menu_item', currentQty);
                        if (result.ok) {
                            if (this.cart[key]) {
                                this.cart[key].qty++;
                            } else {
                                this.cart[key] = {
                                    name: result.item.name ?? name,
                                    price: result.item.price ?? price,
                                    qty: 1,
                                    type: 'menu_item',
                                    menu_item_id: id,
                                    ...(availableStock !== undefined ? { stock: availableStock } : {}),
                                };
                            }
                            this.lastAddedKey = key;
                        }
                    } finally {
                        this.isLoading = false;
                    }
                },

                /**
                 * Decrement/increment for the kiosk cart row +/- stepper.
                 * Only caps against a stored stock snapshot when one exists
                 * (legacy admin/staff-phone adds) — kiosk-added items never
                 * carry one, so the server is the only check once the order
                 * is sent, consistent with the card never exposing the number.
                 */
                decrementCartItem(key) {
                    if (!this.cart[key]) return;
                    if (this.cart[key].qty <= 1) {
                        this.removeFromCart(key);
                        return;
                    }
                    this.cart[key].qty--;
                },

                incrementCartItem(key) {
                    const item = this.cart[key];
                    if (!item) return;
                    if (item.stock !== null && item.stock !== undefined && item.qty >= item.stock) {
                        alert('Out of stock: only ' + item.stock + ' available.');
                        return;
                    }
                    item.qty++;
                },

                /**
                 * Clears only unsent (New Items) cart lines — Existing Items
                 * are never touched here; they can only leave via the
                 * per-line Return flow (bartender confirmation) or a
                 * manager void, both unaffected by this.
                 */
                clearNewItems() {
                    if (this.cartCount === 0) return;
                    if (this.cartCount > 1 && !confirm('Clear all new items from this order?')) return;
                    this.cart = {};
                    this.undoStack = null;
                },

                /**
                 * Removing a line keeps a 4s undo window instead of a confirm
                 * dialog — faster and less annoying on a bar floor, and the
                 * item never left the server (it's still just Alpine state).
                 */
                removeFromCart(key) {
                    if (this.cart[key]) {
                        this.undoStack = { key, item: { ...this.cart[key] } };
                        clearTimeout(this.undoTimer);
                        this.undoTimer = setTimeout(() => { this.undoStack = null; }, 4000);
                    }
                    const updated = { ...this.cart };
                    delete updated[key];
                    this.cart = updated;
                },

                undoRemove() {
                    if (!this.undoStack) return;
                    this.cart = { ...this.cart, [this.undoStack.key]: this.undoStack.item };
                    clearTimeout(this.undoTimer);
                    this.undoStack = null;
                },

                openPaymentModal() {
                    if (this.cartCount > 0) {
                        // Ensure the waiter has sent the order items to the waiter/chef for processing first
                        alert('Please send the order to the kitchen before proceeding to payment.');
                        return; 
                    }
                    if (this.total <= 0) return;
                    this.paidAmount = this.total;
                    this.paymentMethod = 'cash';
                    this.paymentType = 'single';
                    this.splitCashAmount = 0;
                    this.splitPosAmount = 0;
                    this.$wire.$set('selectedGuestId', null);
                    this.showPaymentModal = true;
                    this.showCart = false;
                },

                async confirmPayment() {
                    if (this.isLoading) return;
                    this.isLoading = true;
                    try {
                        let paid;
                        if (this.paymentType === 'split') {
                            paid = await this.$wire.processPayment(
                                this.cart,
                                this.splitCashAmount + this.splitPosAmount,
                                'split',
                                this.$wire.selectedGuestId || null,
                                { cash: this.splitCashAmount, pos: this.splitPosAmount }
                            );
                        } else {
                            paid = await this.$wire.processPayment(
                                this.cart,
                                parseFloat(this.paidAmount || 0),
                                this.paymentMethod,
                                this.$wire.selectedGuestId || null,
                                {}
                            );
                        }
                        // Server rejected the payment (e.g. order not yet served,
                        // still cooking, missing debt guest) — the notification
                        // already explains why. Keep the modal open so the
                        // waiter can see it and fix the input instead of being
                        // silently bounced back as if it worked.
                        if (!paid) return;
                        // Wire updated server state — sync Alpine directly (no dispatch needed)
                        this.cart = {};
                        this.showPaymentModal = false;
                        this.showCart = false;
                        this.paidAmount = 0;
                        this.splitCashAmount = 0;
                        this.splitPosAmount = 0;
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
                        const sent = await this.$wire.checkout(this.cart);
                        // Blocked server-side (e.g. no table selected, no active
                        // shift) — the notification already explains why. Leave
                        // the cart and cart view alone instead of clearing it as
                        // if the order actually went through.
                        if (!sent) return;
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
                    <button @click="$wire.call('cancelCancelModal')"
                        class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                            class="text-2xl">&times;</span></button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="text-center mb-4">
                        <div class="text-red-600 dark:text-red-400 font-bold text-lg">⚠️ This action cannot be undone</div>
                        <div class="text-gray-600 dark:text-gray-400 text-sm">All active orders for this table will be
                            cancelled</div>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Cancellation Reason
                            *</label>
                        <textarea wire:model="cancellationReason"
                            class="w-full p-3 text-base border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 touch-manipulation resize-none"
                            rows="3" placeholder="Please provide a reason for cancelling this order..."></textarea>
                        @error('cancellationReason') <span class="text-xs text-red-600 font-bold">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                    <button @click="$wire.call('cancelCancelModal')"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg touch-manipulation">Keep
                        Order</button>
                    <button @click="$wire.call('confirmCancelOrder')"
                        class="px-4 py-3 font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 touch-manipulation flex items-center justify-center gap-2">
                        <span>Cancel Order</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if($showReturnModal)
        <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm">
            <div
                class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700">
                <div
                    class="bg-red-50 dark:bg-red-900/20 p-4 border-b border-red-100 dark:border-red-800 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-red-700 dark:text-red-400">↩️ Return Item</h3>
                    <button @click="$wire.set('showReturnModal', false)"
                        class="text-gray-400 hover:text-red-500 touch-manipulation p-2"><span
                            class="text-2xl">&times;</span></button>
                </div>

                <div class="p-6 space-y-4">
                    <div class="text-center mb-2">
                        <div class="text-gray-800 dark:text-gray-200 font-medium">
                            Returning: <span class="font-bold">{{ $existingItems[$returnItemKey]['name'] ?? 'Item' }}</span>
                        </div>
                    </div>

                    <div x-data="{ qty: @entangle('returnQuantity'), maxQty: @entangle('maxReturnQuantity') }">
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Quantity to
                            Return</label>
                        <div class="flex items-center gap-3">
                            <button type="button" @click="if(qty > 1) qty--"
                                class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 px-4 py-3 rounded-lg font-bold text-xl text-gray-700 dark:text-gray-300 transition">-</button>
                            <input type="number" wire:model="returnQuantity" min="1" :max="maxQty" readonly
                                class="w-full text-center p-3 text-lg border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 font-bold">
                            <button type="button" @click="if(qty < maxQty) qty++"
                                class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 px-4 py-3 rounded-lg font-bold text-xl text-gray-700 dark:text-gray-300 transition">+</button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1 text-center font-medium">Max returnable: <span
                                x-text="maxQty"></span></div>
                        @error('returnQuantity') <span class="text-xs text-red-600 font-bold">{{ $message }}</span>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Reason for Return
                            *</label>
                        <textarea wire:model="returnReason"
                            class="w-full p-3 text-base border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600 touch-manipulation resize-none"
                            rows="3" placeholder="e.g. Wrong item, Cold food, Customer changed mind..."></textarea>
                        @error('returnReason') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                    <button @click="$wire.set('showReturnModal', false)"
                        class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg touch-manipulation">Cancel</button>
                    <button wire:click="submitReturnRequest"
                        class="px-4 py-3 font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 touch-manipulation flex items-center justify-center gap-2">Confirm
                        Return</button>
                </div>
            </div>
        </div>
    @endif

    @if($showCashDropModal)
        <div class="fixed inset-0 bg-black/60 z-[60] flex items-center justify-center p-4 backdrop-blur-sm">
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700">
                <div class="bg-emerald-50 dark:bg-emerald-900/20 p-4 border-b border-emerald-100 dark:border-emerald-800 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-emerald-700 dark:text-emerald-400">💵 Drop Cash</h3>
                    <button @click="$wire.set('showCashDropModal', false)" class="text-gray-400 hover:text-emerald-500 p-2"><span class="text-2xl">&times;</span></button>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Handing Cash To</label>
                        <select wire:model="cashDropReceiverId" class="w-full p-3 border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600">
                            <option value="">Select…</option>
                            @foreach($this->cashDropReceivers as $receiver)
                                <option value="{{ $receiver->id }}">{{ $receiver->name }}</option>
                            @endforeach
                        </select>
                        @error('cashDropReceiverId') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Amount</label>
                        <input type="number" wire:model="cashDropAmount" min="0.01" step="0.01" class="w-full p-3 text-lg border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600">
                        @error('cashDropAmount') <span class="text-xs text-red-600 font-bold">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Note (optional)</label>
                        <textarea wire:model="cashDropNote" class="w-full p-3 border border-gray-300 rounded-lg dark:bg-gray-800 dark:border-gray-600" rows="2"></textarea>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-3">
                    <button @click="$wire.set('showCashDropModal', false)" class="px-4 py-3 font-bold text-gray-700 bg-gray-100 rounded-lg">Cancel</button>
                    <button wire:click="declareCashDrop" class="px-4 py-3 font-bold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">Declare Drop</button>
                </div>
            </div>
        </div>
    @endif
</div>